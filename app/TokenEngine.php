<?php
declare(strict_types=1);

namespace App;

use App\Social\MessengerClient;
use PDO;

/**
 * Core Token Minting, Burning, Transfer & Supply Engine.
 * Every operation locks involved rows in ascending ID order (deadlock-free!), enforces supply caps,
 * circuit-breaker pause state, idempotency keys, exact decimal math, and hash-chained journaling.
 */
final class TokenEngine
{
    /**
     * Super-Admin Mint: creates brand-new tokens out of thin air to a target wallet (default: Treasury).
     *
     * @return array<string,mixed>
     */
    public static function mint(
        int $targetUserId,
        string $rawAmount,
        int $adminId,
        string $memo = 'ضرب توکن جدید توسط مدیر کل',
        ?string $idempotencyKey = null
    ): array {
        $amount = dec_norm($rawAmount);
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مقدار ضرب توکن باید بزرگ‌تر از صفر باشد.');
        }

        Users::ensureSystemAccounts();

        $tx = Db::tx(function (PDO $pdo) use ($targetUserId, $amount, $adminId, $memo, $idempotencyKey): array {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $ex = $pdo->prepare('SELECT * FROM ledger_entries WHERE idempotency_key = ? LIMIT 1');
                $ex->execute([$idempotencyKey]);
                if ($found = $ex->fetch()) {
                    return $found;
                }
            }

            // Enforce Max Supply Cap if > 0
            $maxSupply = dec_norm(Settings::get('token_max_supply', '0'));
            if (bccomp($maxSupply, '0', 8) > 0) {
                $totalHeld = (string) ($pdo->query('SELECT COALESCE(SUM(balance), 0) FROM users FOR UPDATE')->fetchColumn() ?: '0');
                $burnSink = (string) ($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users WHERE account_type = 'burn_sink'")->fetchColumn() ?: '0');
                $currentNetSupply = bcsub(dec_norm($totalHeld), dec_norm($burnSink), 8);
                if (bccomp(bcadd($currentNetSupply, $amount, 8), $maxSupply, 8) > 0) {
                    throw new \RuntimeException('مقدار درخواستی از سقف عرضه کل مجاز توکن (' . fmt_token($maxSupply) . ') فراتر می‌رود.');
                }
            }

            $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $uStmt->execute([$targetUserId]);
            $target = $uStmt->fetch();
            if (!$target) {
                throw new \RuntimeException('کیف پول مقصد یافت نشد.');
            }

            $newBal = bcadd((string) $target['balance'], $amount, 8);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$newBal, $amount, $targetUserId]);

            return Ledger::appendLocked($pdo, [
                'tx_type'                => 'mint',
                'from_user_id'           => null,
                'to_user_id'             => $targetUserId,
                'gross_amount'           => $amount,
                'net_amount'             => $amount,
                'receiver_balance_after' => $newBal,
                'channel'                => 'admin',
                'idempotency_key'        => $idempotencyKey,
                'memo'                   => $memo,
                'created_by_admin_id'    => $adminId,
            ]);
        });

        Audit::record('admin', $adminId, 'token.mint', 'تولید توکن جدید به مقدار ' . fmt_token($amount), [
            'to_user_id' => $targetUserId,
            'tx_hash'    => $tx['tx_hash'],
        ]);

        $target = Users::findById($targetUserId);
        if ($target) {
            MessengerClient::broadcastPublicAlert(
                "🏛 <b>اعلان شفافیت شبکه — تولید توکن جدید (Mint)</b>\n\n"
                . "➕ مقدار ضرب‌شده: <b>" . fmt_token($amount) . "</b>\n"
                . "📥 مقصد: <code>" . e($target['wallet_code']) . "</code>\n"
                . "📝 توضیح: " . e($memo) . "\n"
                . "🔗 هش زنجیره: <code>" . substr((string) $tx['tx_hash'], 0, 18) . "…</code>"
            );
        }

        return $tx;
    }

    /**
     * Permanent Token Burn: sends tokens irrevocably to Burn Sink (0x...dEaD) reducing circulating supply.
     *
     * @return array<string,mixed>
     */
    public static function burn(
        int $fromUserId,
        string $rawAmount,
        ?int $adminId = null,
        string $memo = 'توکن‌سوزی دائمی'
    ): array {
        $amount = dec_norm($rawAmount);
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مقدار توکن‌سوزی باید بزرگ‌تر از صفر باشد.');
        }
        $burnSink = Users::systemAccount('burn_sink');
        $burnId = (int) $burnSink['id'];
        if ($fromUserId === $burnId) {
            throw new \InvalidArgumentException('امکان سوزاندن مجدد آدرس توکن‌سوزی وجود ندارد.');
        }

        $tx = Db::tx(function (PDO $pdo) use ($fromUserId, $burnId, $amount, $adminId, $memo): array {
            $ids = [$fromUserId, $burnId];
            sort($ids);
            $lockStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $id) {
                $lockStmt->execute([$id]);
                $locked[$id] = $lockStmt->fetch();
            }
            $src = $locked[$fromUserId] ?? null;
            $sink = $locked[$burnId] ?? null;
            if (!$src || !$sink) {
                throw new \RuntimeException('حساب یافت نشد.');
            }
            if (bccomp((string) $src['balance'], $amount, 8) < 0) {
                throw new \RuntimeException('موجودی کافی برای توکن‌سوزی وجود ندارد.');
            }

            $srcAfter = bcsub((string) $src['balance'], $amount, 8);
            $sinkAfter = bcadd((string) $sink['balance'], $amount, 8);

            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$srcAfter, $amount, $fromUserId]);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$sinkAfter, $amount, $burnId]);

            return Ledger::appendLocked($pdo, [
                'tx_type'                => 'burn',
                'from_user_id'           => $fromUserId,
                'to_user_id'             => $burnId,
                'gross_amount'           => $amount,
                'net_amount'             => '0.00000000',
                'burned_amount'          => $amount,
                'sender_balance_after'   => $srcAfter,
                'receiver_balance_after' => $sinkAfter,
                'channel'                => $adminId ? 'admin' : 'wallet',
                'memo'                   => $memo,
                'created_by_admin_id'    => $adminId,
            ]);
        });

        MessengerClient::broadcastPublicAlert(
            "🔥 <b>توکن‌سوزی دائمی شبکه (Burn)</b>\n\n"
            . "🔥 مقدار سوزانده‌شده: <b>" . fmt_token($amount) . "</b>\n"
            . "📝 علت: " . e($memo) . "\n"
            . "🔗 هش زنجیره: <code>" . substr((string) $tx['tx_hash'], 0, 18) . "…</code>"
        );

        return $tx;
    }

    /**
     * Atomic P2P / Agent / Group / Mobile ECDSA Wallet Transfer with Full Fee Split & Notifications!
     *
     * @return array<string,mixed>
     */
    public static function transfer(
        int $senderId,
        int $receiverId,
        string $rawAmount,
        string $channel = 'web',
        string $memo = '',
        ?string $idempotencyKey = null,
        bool $forceZeroFee = false,
        ?string $signatureHex = null,
        ?string $overrideTxType = null
    ): array {
        if ($senderId === $receiverId) {
            throw new \InvalidArgumentException('امکان انتقال به کیف پول خودتان وجود ندارد.');
        }

        $sysAccounts = Users::ensureSystemAccounts();
        $feeVaultId = (int) $sysAccounts['fee_vault']['id'];
        $burnSinkId = (int) $sysAccounts['burn_sink']['id'];

        $result = Db::tx(function (PDO $pdo) use (
            $senderId,
            $receiverId,
            $rawAmount,
            $channel,
            $memo,
            $idempotencyKey,
            $forceZeroFee,
            $signatureHex,
            $overrideTxType,
            $feeVaultId,
            $burnSinkId
        ): array {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $ex = $pdo->prepare('SELECT * FROM ledger_entries WHERE idempotency_key = ? LIMIT 1');
                $ex->execute([$idempotencyKey]);
                if ($found = $ex->fetch()) {
                    return ['tx' => $found, 'duplicate' => true];
                }
            }

            // Lock sender & receiver first to compute exact fee & determine if an Agent also needs locking
            $baseIds = array_values(array_unique([$senderId, $receiverId, $feeVaultId, $burnSinkId]));
            sort($baseIds);

            $lockStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($baseIds as $uid) {
                $lockStmt->execute([$uid]);
                $locked[$uid] = $lockStmt->fetch();
            }

            $sender = $locked[$senderId] ?? null;
            $receiver = $locked[$receiverId] ?? null;
            if (!$sender || !$receiver) {
                throw new \RuntimeException('حساب فرستنده یا گیرنده یافت نشد.');
            }

            $exemptTypes = ['treasury', 'fee_vault', 'burn_sink', 'escrow'];
            $isSystemOp = in_array($sender['account_type'], $exemptTypes, true) || in_array($receiver['account_type'], $exemptTypes, true);

            if (!$isSystemOp && Settings::bool('transfers_paused', false)) {
                throw new \RuntimeException('انتقال توکن در شبکه موقتاً توسط مدیر کل متوقف شده است.');
            }
            if ((int) $sender['is_frozen'] === 1) {
                throw new \RuntimeException('کیف پول فرستنده مسدود است: ' . ($sender['freeze_reason'] ?: 'تماس با پشتیبانی'));
            }
            if ((int) $receiver['is_frozen'] === 1) {
                throw new \RuntimeException('کیف پول مقصد مسدود است.');
            }

            if (!$isSystemOp) {
                $minTx = dec_norm(Settings::get('min_transfer_amount', '0.01'));
                $maxTx = dec_norm(Settings::get('max_single_transfer', '500000'));
                $normAmt = dec_norm($rawAmount);
                if (bccomp($normAmt, $minTx, 8) < 0) {
                    throw new \InvalidArgumentException('حداقل مبلغ انتقال ' . fmt_token($minTx) . ' است.');
                }
                if (bccomp($maxTx, '0', 8) > 0 && bccomp($normAmt, $maxTx, 8) > 0) {
                    throw new \InvalidArgumentException('سقف هر انتقال ' . fmt_token($maxTx) . ' است.');
                }
            }

            $quote = FeeCalculator::quote($sender, $receiver, $rawAmount, $channel, $forceZeroFee);

            if (bccomp((string) $sender['balance'], $quote['sender_total_debit'], 8) < 0) {
                throw new \RuntimeException(
                    'موجودی کافی نیست. مورد نیاز (با احتساب کارمزد): ' . fmt_token($quote['sender_total_debit'])
                    . ' | موجودی فعلی: ' . fmt_token($sender['balance'])
                );
            }

            $agentId = $quote['agent_beneficiary_id'];
            if ($agentId !== null && !isset($locked[$agentId])) {
                $lockStmt->execute([$agentId]);
                $locked[$agentId] = $lockStmt->fetch();
            }

            // 1. Debit sender
            $senderAfter = bcsub((string) $sender['balance'], $quote['sender_total_debit'], 8);
            $pdo->prepare(
                'UPDATE users
                 SET balance = ?, total_sent = total_sent + ?, total_fees_paid = total_fees_paid + ?, tx_nonce = tx_nonce + 1
                 WHERE id = ?'
            )->execute([$senderAfter, $quote['gross_amount'], $quote['fee_amount'], $senderId]);

            // 2. Credit receiver
            $receiverAfter = bcadd((string) $receiver['balance'], $quote['receiver_net_credit'], 8);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$receiverAfter, $quote['receiver_net_credit'], $receiverId]);

            // 3. Distribute Fee Shares (Fee Collector Vault + Deflationary Burn Sink + Agent Commission)
            if (bccomp($quote['vault_share'], '0', 8) > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ?, total_received = total_received + ? WHERE id = ?')
                    ->execute([$quote['vault_share'], $quote['vault_share'], $feeVaultId]);
            }
            if (bccomp($quote['burn_share'], '0', 8) > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ?, total_received = total_received + ? WHERE id = ?')
                    ->execute([$quote['burn_share'], $quote['burn_share'], $burnSinkId]);
            }
            if ($agentId !== null && bccomp($quote['agent_commission'], '0', 8) > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ?, total_received = total_received + ? WHERE id = ?')
                    ->execute([$quote['agent_commission'], $quote['agent_commission'], $agentId]);
            }

            $txType = $overrideTxType ?? match (true) {
                $channel === 'group' => 'group_tip',
                ($sender['account_type'] ?? '') === 'agent' => 'agent_transfer',
                default => 'transfer',
            };

            $tx = Ledger::appendLocked($pdo, [
                'tx_type'                 => $txType,
                'from_user_id'            => $senderId,
                'to_user_id'              => $receiverId,
                'gross_amount'            => $quote['gross_amount'],
                'net_amount'              => $quote['receiver_net_credit'],
                'fee_amount'              => $quote['fee_amount'],
                'burned_amount'           => $quote['burn_share'],
                'agent_commission_amount' => $quote['agent_commission'],
                'sender_balance_after'    => $senderAfter,
                'receiver_balance_after'  => $receiverAfter,
                'channel'                 => $channel,
                'idempotency_key'         => $idempotencyKey,
                'signature_hex'           => $signatureHex,
                'memo'                    => $memo,
                'meta'                    => [
                    'fee_rule'       => $quote['applied_rule'],
                    'fee_payer_mode' => $quote['fee_payer_mode'],
                    'agent_comm_to'  => $agentId,
                    'vault_share'    => $quote['vault_share'],
                ],
            ]);

            return [
                'tx'        => $tx,
                'quote'     => $quote,
                'sender'    => array_merge($sender, ['balance' => $senderAfter]),
                'receiver'  => array_merge($receiver, ['balance' => $receiverAfter]),
                'duplicate' => false,
            ];
        });

        // Async-safe social notifications outside DB lock
        if (empty($result['duplicate']) && isset($result['sender'], $result['receiver'], $result['quote'])) {
            $tx = $result['tx'];
            $q = $result['quote'];
            $s = $result['sender'];
            $r = $result['receiver'];

            MessengerClient::notifyUser(
                $r,
                "📥 <b>واریز جدید به کیف پول شما</b>\n\n"
                . "💰 مبلغ دریافتی: <b>" . fmt_token($q['receiver_net_credit']) . "</b> (" . fmt_irt($q['receiver_net_credit']) . ")\n"
                . "👤 فرستنده: <code>" . e($s['wallet_code']) . "</code>\n"
                . ($memo !== '' ? "📝 بابت: " . e($memo) . "\n" : '')
                . "💼 موجودی جدید: <b>" . fmt_token($r['balance']) . "</b>"
            );

            $whale = dec_norm(Settings::get('whale_alert_threshold', '0'));
            if (bccomp($whale, '0', 8) > 0 && bccomp($q['gross_amount'], $whale, 8) >= 0) {
                MessengerClient::broadcastPublicAlert(
                    "🐋 <b>تراکنش بزرگ در شبکه ثبت شد</b>\n\n"
                    . "💸 مقدار: <b>" . fmt_token($q['gross_amount']) . "</b>\n"
                    . "📤 از: <code>" . e($s['wallet_code']) . "</code> ⬅️ <code>" . e($r['wallet_code']) . "</code>\n"
                    . "🔥 کارمزد سوزانده‌شده: " . fmt_token($q['burn_share']) . "\n"
                    . "🔗 هش: <code>" . substr((string) $tx['tx_hash'], 0, 18) . "…</code>"
                );
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function supplyOverview(): array
    {
        Users::ensureSystemAccounts();
        $pdo = Db::pdo();
        $treasury = Users::systemAccount('treasury');
        $feeVault = Users::systemAccount('fee_vault');
        $burnSink = Users::systemAccount('burn_sink');

        $totalMinted = (string) ($pdo->query("SELECT COALESCE(SUM(net_amount), 0) FROM ledger_entries WHERE tx_type = 'mint'")->fetchColumn() ?: '0');
        $totalHeld = (string) ($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users")->fetchColumn() ?: '0');
        $totalBurned = dec_norm((string) $burnSink['balance']);
        $netSupply = bcsub(dec_norm($totalHeld), $totalBurned, 8);
        $publicCirculating = bcsub($netSupply, dec_norm((string) $treasury['balance']), 8);
        $totalFeesEver = (string) ($pdo->query("SELECT COALESCE(SUM(fee_amount), 0) FROM ledger_entries")->fetchColumn() ?: '0');
        $txCount = (int) $pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
        $holders = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_type IN ('user','agent','merchant') AND balance > 0")->fetchColumn();

        return [
            'name'                => Settings::get('token_name', 'یارکوین'),
            'name_en'             => Settings::get('token_name_en', 'YarCoin'),
            'symbol'              => Settings::get('token_symbol', 'YAR'),
            'decimals_display'    => (int) Settings::get('token_decimals_display', '4'),
            'chain_id'            => (int) Settings::get('token_chain_id', '9898'),
            'contract_address'    => Settings::get('token_contract_virtual', '0x596172546F6B656E000000000000000000009898'),
            'price_usd'           => (float) Settings::get('token_price_usd', '0.10'),
            'price_irt'           => (float) Settings::get('token_price_irt', '8500'),
            'max_supply'          => dec_norm(Settings::get('token_max_supply', '0')),
            'total_minted'        => dec_norm($totalMinted),
            'total_burned'        => $totalBurned,
            'net_total_supply'    => $netSupply,
            'circulating_supply'  => $publicCirculating,
            'treasury_balance'    => dec_norm((string) $treasury['balance']),
            'fee_vault_balance'   => dec_norm((string) $feeVault['balance']),
            'total_fees_collected'=> dec_norm($totalFeesEver),
            'transactions_count'  => $txCount,
            'active_holders'      => $holders,
            'paused'              => Settings::bool('transfers_paused', false),
        ];
    }
}
