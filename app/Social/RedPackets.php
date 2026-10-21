<?php
declare(strict_types=1);

namespace App\Social;

use App\Db;
use App\Ledger;
use App\Users;
use PDO;

/**
 * Atomic Group & Web Red Packets / Token Rain Pro Engine (پاکت هدیه گروهی و لینک همگانی).
 * Supports Equal & Lucky modes, optional secret passcode, live winner rankings & 1-click Escrow Refund!
 */
final class RedPackets
{
    public static function create(
        int $creatorUserId,
        string $rawTotalAmount,
        int $maxClaims,
        string $platform = 'bale',
        string $groupChatId = '',
        string $mode = 'equal',
        string $title = 'پاکت هدیه گروهی',
        string $passcode = '',
        int $ttlHours = 24
    ): array {
        $total = dec_norm($rawTotalAmount);
        if (bccomp($total, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ کل پاکت هدیه باید بزرگ‌تر از صفر باشد.');
        }
        $maxClaims = max(1, min(500, $maxClaims));
        $mode = ($mode === 'lucky' || $mode === 'random') ? 'lucky' : 'equal';
        $passHash = trim($passcode) !== '' ? password_hash(trim($passcode), PASSWORD_DEFAULT) : null;
        $ttlHours = max(1, min(720, $ttlHours));

        $escrow = Users::systemAccount('escrow');
        $escrowId = (int) $escrow['id'];
        $code = 'PKT-' . strtoupper(bin2hex(random_bytes(4)));

        Db::tx(function (PDO $pdo) use ($creatorUserId, $escrowId, $total, $maxClaims, $platform, $groupChatId, $mode, $title, $passHash, $ttlHours, $code): void {
            $ids = [$creatorUserId, $escrowId];
            sort($ids);
            $st = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $id) {
                $st->execute([$id]);
                $locked[$id] = $st->fetch();
            }
            $creator = $locked[$creatorUserId] ?? null;
            $esc = $locked[$escrowId] ?? null;
            if (!$creator || !$esc) {
                throw new \RuntimeException('حساب یافت نشد.');
            }
            if ((int) $creator['is_frozen'] === 1) {
                throw new \RuntimeException('حساب شما مسدود است.');
            }
            if (bccomp((string) $creator['balance'], $total, 8) < 0) {
                throw new \RuntimeException('موجودی کیف پول برای ساخت پاکت هدیه کافی نیست.');
            }

            $creatorAfter = bcsub((string) $creator['balance'], $total, 8);
            $escAfter = bcadd((string) $esc['balance'], $total, 8);

            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$creatorAfter, $total, $creatorUserId]);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$escAfter, $total, $escrowId]);

            Ledger::appendLocked($pdo, [
                'tx_type'                => 'red_packet_create',
                'from_user_id'           => $creatorUserId,
                'to_user_id'             => $escrowId,
                'gross_amount'           => $total,
                'net_amount'             => $total,
                'sender_balance_after'   => $creatorAfter,
                'receiver_balance_after' => $escAfter,
                'channel'                => $platform === 'web' ? 'web' : 'group',
                'reference_id'           => $code,
                'memo'                   => "ایجاد پاکت هدیه {$code} ({$maxClaims} نفر)",
            ]);

            $ins = $pdo->prepare(
                'INSERT INTO red_packets (packet_code, creator_user_id, platform, group_chat_id, mode, total_amount, remaining_amount, max_claims, title, passcode_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
            );
            $ins->execute([$code, $creatorUserId, $platform, $groupChatId, $mode, $total, $total, $maxClaims, mb_substr(trim($title) ?: 'پاکت هدیه گروهی', 0, 140), $passHash, $ttlHours]);
        });

        return self::details($code) ?? [];
    }

    /** @return array<string,mixed> */
    public static function claim(string $packetCode, int $claimerUserId, string $passcode = ''): array
    {
        $escrow = Users::systemAccount('escrow');
        $escrowId = (int) $escrow['id'];

        return Db::tx(function (PDO $pdo) use ($packetCode, $claimerUserId, $passcode, $escrowId): array {
            $pStmt = $pdo->prepare('SELECT *, (expires_at < NOW()) AS is_past_expiry FROM red_packets WHERE packet_code = ? FOR UPDATE');
            $pStmt->execute([trim($packetCode)]);
            $pkt = $pStmt->fetch();
            if (!$pkt) {
                throw new \RuntimeException('پاکت هدیه یافت نشد.');
            }
            if ($pkt['status'] !== 'active' || (int) $pkt['claimed_count'] >= (int) $pkt['max_claims'] || bccomp((string) $pkt['remaining_amount'], '0', 8) <= 0) {
                throw new \RuntimeException('ظرفیت این پاکت هدیه تکمیل شده یا به پایان رسیده است.');
            }
            if ((int) ($pkt['is_past_expiry'] ?? 0) === 1) {
                throw new \RuntimeException('مهلت زمانی این پاکت هدیه به پایان رسیده است.');
            }
            if (!empty($pkt['passcode_hash']) && !password_verify(trim($passcode), (string) $pkt['passcode_hash'])) {
                throw new \RuntimeException('رمز مخفی پاکت هدیه نادرست است!');
            }

            $dupStmt = $pdo->prepare('SELECT id FROM red_packet_claims WHERE packet_id = ? AND user_id = ? LIMIT 1');
            $dupStmt->execute([(int) $pkt['id'], $claimerUserId]);
            if ($dupStmt->fetch()) {
                throw new \RuntimeException('شما قبلاً سهم خود را از این پاکت هدیه دریافت کرده‌اید!');
            }

            $leftSlots = (int) $pkt['max_claims'] - (int) $pkt['claimed_count'];
            $rem = (string) $pkt['remaining_amount'];

            if ($leftSlots <= 1) {
                $share = $rem;
            } elseif ($pkt['mode'] === 'lucky') {
                $avg = bcdiv($rem, (string) $leftSlots, 8);
                $factor = (string) random_int(50, 150);
                $share = bcdiv(bcmul($avg, $factor, 12), '100', 8);
                if (bccomp($share, '0.00010000', 8) < 0) $share = $avg;
                if (bccomp($share, $rem, 8) >= 0) $share = $avg;
            } else {
                $share = bcdiv($rem, (string) $leftSlots, 8);
            }

            $ids = [$escrowId, $claimerUserId];
            sort($ids);
            $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $id) {
                $uStmt->execute([$id]);
                $locked[$id] = $uStmt->fetch();
            }

            $escAfter = bcsub((string) $locked[$escrowId]['balance'], $share, 8);
            $userAfter = bcadd((string) $locked[$claimerUserId]['balance'], $share, 8);

            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$escAfter, $share, $escrowId]);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$userAfter, $share, $claimerUserId]);

            $tx = Ledger::appendLocked($pdo, [
                'tx_type'                => 'red_packet_claim',
                'from_user_id'           => $escrowId,
                'to_user_id'             => $claimerUserId,
                'gross_amount'           => $share,
                'net_amount'             => $share,
                'sender_balance_after'   => $escAfter,
                'receiver_balance_after' => $userAfter,
                'channel'                => 'group',
                'reference_id'           => $packetCode,
                'memo'                   => 'دریافت هدیه از پاکت ' . $packetCode,
            ]);

            $newClaimed = (int) $pkt['claimed_count'] + 1;
            $newRem = bcsub($rem, $share, 8);
            $newStatus = ($newClaimed >= (int) $pkt['max_claims'] || bccomp($newRem, '0', 8) <= 0) ? 'completed' : 'active';

            $pdo->prepare('UPDATE red_packets SET claimed_count = ?, remaining_amount = ?, status = ? WHERE id = ?')
                ->execute([$newClaimed, $newRem, $newStatus, (int) $pkt['id']]);

            $pdo->prepare('INSERT INTO red_packet_claims (packet_id, user_id, amount, tx_hash) VALUES (?, ?, ?, ?)')
                ->execute([(int) $pkt['id'], $claimerUserId, $share, $tx['tx_hash']]);

            return [
                'share'         => $share,
                'claimed_count' => $newClaimed,
                'max_claims'    => (int) $pkt['max_claims'],
                'status'        => $newStatus,
                'tx_hash'       => $tx['tx_hash'],
                'balance_after' => $userAfter,
            ];
        });
    }

    /**
     * Atomically refunds any remaining unclaimed balance from Escrow back to the creator's wallet.
     *
     * @return array<string,mixed>
     */
    public static function refundRemaining(string $packetCode, ?int $actorUserId = null, bool $isAdmin = false): array
    {
        $escrow = Users::systemAccount('escrow');
        $escrowId = (int) $escrow['id'];

        return Db::tx(function (PDO $pdo) use ($packetCode, $actorUserId, $isAdmin, $escrowId): array {
            $pStmt = $pdo->prepare('SELECT * FROM red_packets WHERE packet_code = ? FOR UPDATE');
            $pStmt->execute([trim($packetCode)]);
            $pkt = $pStmt->fetch();
            if (!$pkt) {
                throw new \RuntimeException('پاکت هدیه یافت نشد.');
            }
            if (!$isAdmin && $actorUserId !== (int) $pkt['creator_user_id']) {
                throw new \RuntimeException('فقط سازنده پاکت یا مدیر کل مجاز به بازپس‌گیری مانده پاکت است.');
            }
            if ($pkt['status'] !== 'active' || bccomp((string) $pkt['remaining_amount'], '0', 8) <= 0) {
                throw new \RuntimeException('مانده فعالی برای بازپس‌گیری در این پاکت وجود ندارد.');
            }

            $creatorId = (int) $pkt['creator_user_id'];
            $refundAmt = (string) $pkt['remaining_amount'];

            $ids = [$escrowId, $creatorId];
            sort($ids);
            $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $id) {
                $uStmt->execute([$id]);
                $locked[$id] = $uStmt->fetch();
            }

            $escAfter = bcsub((string) $locked[$escrowId]['balance'], $refundAmt, 8);
            $creatorAfter = bcadd((string) $locked[$creatorId]['balance'], $refundAmt, 8);

            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$escAfter, $refundAmt, $escrowId]);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$creatorAfter, $refundAmt, $creatorId]);

            $tx = Ledger::appendLocked($pdo, [
                'tx_type'                => 'red_packet_refund',
                'from_user_id'           => $escrowId,
                'to_user_id'             => $creatorId,
                'gross_amount'           => $refundAmt,
                'net_amount'             => $refundAmt,
                'sender_balance_after'   => $escAfter,
                'receiver_balance_after' => $creatorAfter,
                'channel'                => $isAdmin ? 'admin' : 'web',
                'reference_id'           => $packetCode,
                'memo'                   => 'بازگشت مانده جذب‌نشده پاکت هدیه ' . $packetCode,
            ]);

            $pdo->prepare('UPDATE red_packets SET remaining_amount = 0, status = "refunded" WHERE id = ?')
                ->execute([(int) $pkt['id']]);

            return [
                'refunded_amount' => $refundAmt,
                'tx_hash'         => $tx['tx_hash'],
                'packet_code'     => $packetCode,
            ];
        });
    }

    /** @return ?array<string,mixed> */
    public static function details(string $packetCode): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT rp.*, u.full_name AS creator_name, u.wallet_code AS creator_code
             FROM red_packets rp
             JOIN users u ON u.id = rp.creator_user_id
             WHERE rp.packet_code = ?
             LIMIT 1'
        );
        $stmt->execute([trim($packetCode)]);
        $pkt = $stmt->fetch();
        if (!$pkt) {
            return null;
        }

        $cStmt = Db::pdo()->prepare(
            'SELECT c.*, u.full_name, u.wallet_code
             FROM red_packet_claims c
             JOIN users u ON u.id = c.user_id
             WHERE c.packet_id = ?
             ORDER BY c.id DESC'
        );
        $cStmt->execute([(int) $pkt['id']]);
        $claims = $cStmt->fetchAll();

        $topAmount = '0.00000000';
        foreach ($claims as $cl) {
            if (bccomp((string) $cl['amount'], $topAmount, 8) > 0) {
                $topAmount = (string) $cl['amount'];
            }
        }
        foreach ($claims as &$cl) {
            $cl['is_mvp'] = count($claims) >= 2 && bccomp((string) $cl['amount'], $topAmount, 8) === 0;
        }
        unset($cl);

        $pkt['has_passcode'] = !empty($pkt['passcode_hash']);
        $pkt['claims'] = $claims;
        return $pkt;
    }
}
