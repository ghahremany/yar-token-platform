<?php
declare(strict_types=1);

namespace App;

use App\Social\MessengerClient;
use PDO;

/**
 * Representative / Lender Lending, Installment Amortization & Auto-Collection Engine.
 * Conserves 100.00000000% token supply at every stage:
 * - Pool-backed loans disburse principal from Treasury against Agent's approved credit line,
 *   returning principal to Treasury upon repayment while crediting interest directly to Agent's wallet!
 * - Wallet-backed loans disburse principal directly from Agent's wallet and return full principal + interest to Agent!
 */
final class Loans
{
    /**
     * Sub-user submits a formal loan request to their assigned Agent (from Android App, Bot, or Web Wallet).
     *
     * @return array<string,mixed>
     */
    public static function requestLoan(
        int $borrowerUserId,
        string $rawPrincipal,
        int $installmentsCount = 3,
        int $intervalDays = 30,
        string $purpose = ''
    ): array {
        $borrower = Users::findById($borrowerUserId);
        if (!$borrower || empty($borrower['parent_agent_id'])) {
            throw new \RuntimeException('برای ثبت درخواست وام باید زیرمجموعه یک نماینده فعال باشید.');
        }
        $agentId = (int) $borrower['parent_agent_id'];
        $agent = Agents::getProfile($agentId);
        if (!$agent || (int) $agent['is_active'] !== 1) {
            throw new \RuntimeException('نماینده معرف شما در حال حاضر فعال نیست.');
        }

        $principal = dec_norm($rawPrincipal);
        if (bccomp($principal, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ وام درخواستی نامعتبر است.');
        }
        if (bccomp($principal, (string) $agent['max_loan_per_user'], 8) > 0) {
            throw new \InvalidArgumentException('حداکثر سقف وام قابل درخواست از نماینده شما ' . fmt_token($agent['max_loan_per_user']) . ' است.');
        }

        $installmentsCount = max(1, min(36, $installmentsCount));
        $intervalDays = max(1, min(90, $intervalDays));
        if ($installmentsCount * $intervalDays > (int) $agent['max_term_days']) {
            throw new \InvalidArgumentException('مدت بازپرداخت درخواستی بیشتر از سقف مجاز نماینده (' . fa_digits($agent['max_term_days']) . ' روز) است.');
        }

        $interestBps = (int) $agent['max_interest_bps'];
        $interestAmt = bcdiv(bcmul($principal, (string) $interestBps, 12), '10000', 8);
        $totalRepay = bcadd($principal, $interestAmt, 8);
        $code = 'LN-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO loans (
                loan_code, agent_user_id, borrower_user_id, funding_source, principal_amount,
                interest_bps, interest_amount, total_repayable, remaining_amount, penalty_bps_per_late,
                installments_count, interval_days, auto_deduct, status, purpose
             ) VALUES (?, ?, ?, "credit_pool", ?, ?, ?, ?, ?, ?, ?, ?, 1, "requested", ?)'
        );
        $stmt->execute([
            $code,
            $agentId,
            $borrowerUserId,
            $principal,
            $interestBps,
            $interestAmt,
            $totalRepay,
            $totalRepay,
            (int) Settings::get('default_penalty_bps', '200'),
            $installmentsCount,
            $intervalDays,
            mb_substr(trim($purpose), 0, 255),
        ]);

        $loanId = (int) $pdo->lastInsertId();
        $agentUser = Users::findById($agentId);
        if ($agentUser) {
            MessengerClient::notifyUser(
                $agentUser,
                "📋 <b>درخواست وام جدید از زیرمجموعه</b>\n\n"
                . "👤 متقاضی: <b>" . e($borrower['full_name']) . "</b> (<code>" . e($borrower['wallet_code']) . "</code>)\n"
                . "💰 مبلغ اصل وام: <b>" . fmt_token($principal) . "</b>\n"
                . "🗓 تعداد اقساط: " . fa_digits($installmentsCount) . " قسط\n"
                . "🆔 کد پرونده: <code>{$code}</code>"
            );
        }

        return self::findById($loanId) ?? [];
    }

    /**
     * Agent directly grants & disburses a loan to a sub-user (or approves a pending request).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function grantLoan(
        int $agentUserId,
        int $borrowerUserId,
        string $rawPrincipal,
        array $opts = []
    ): array {
        $principal = dec_norm($rawPrincipal);
        if (bccomp($principal, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ اصل وام باید بزرگ‌تر از صفر باشد.');
        }

        $fundingSource = ($opts['funding_source'] ?? 'credit_pool') === 'agent_wallet' ? 'agent_wallet' : 'credit_pool';
        $interestBps = max(0, (int) ($opts['interest_bps'] ?? 500));
        $installmentsCount = max(1, min(36, (int) ($opts['installments_count'] ?? 3)));
        $intervalDays = max(1, min(90, (int) ($opts['interval_days'] ?? 30)));
        $autoDeduct = !isset($opts['auto_deduct']) || (bool) $opts['auto_deduct'] ? 1 : 0;
        $purpose = mb_substr(trim((string) ($opts['purpose'] ?? 'تسهیلات نمایندگی')), 0, 255);
        $requestLoanId = !empty($opts['request_loan_id']) ? (int) $opts['request_loan_id'] : null;

        Users::ensureSystemAccounts();
        $treasury = Users::systemAccount('treasury');
        $treasuryId = (int) $treasury['id'];

        $loan = Db::tx(function (PDO $pdo) use (
            $agentUserId, $borrowerUserId, $principal, $fundingSource,
            $interestBps, $installmentsCount, $intervalDays, $autoDeduct,
            $purpose, $requestLoanId, $treasuryId
        ): array {
            $agStmt = $pdo->prepare('SELECT * FROM agents WHERE user_id = ? FOR UPDATE');
            $agStmt->execute([$agentUserId]);
            $agent = $agStmt->fetch();
            if (!$agent || (int) $agent['is_active'] !== 1) {
                throw new \RuntimeException('نمایندگی فعال یافت نشد.');
            }

            if ($interestBps > (int) $agent['max_interest_bps']) {
                throw new \InvalidArgumentException('نرخ کارمزد/سود وام بیش از سقف مجاز نماینده است.');
            }
            if (bccomp($principal, (string) $agent['max_loan_per_user'], 8) > 0) {
                throw new \InvalidArgumentException('مبلغ وام بیش از سقف مجاز هر کاربر (' . fmt_token($agent['max_loan_per_user']) . ') است.');
            }
            if ($installmentsCount * $intervalDays > (int) $agent['max_term_days']) {
                throw new \InvalidArgumentException('مدت بازپرداخت بیش از سقف مجاز نماینده است.');
            }

            $sourceUserId = $fundingSource === 'credit_pool' ? $treasuryId : $agentUserId;
            if ($fundingSource === 'credit_pool') {
                if ((int) $agent['can_lend_from_pool'] !== 1) {
                    throw new \RuntimeException('اعطای وام از استخر اعتباری برای این نماینده غیرفعال است.');
                }
                $availPool = bcsub((string) $agent['credit_pool_limit'], (string) $agent['credit_pool_used'], 8);
                if (bccomp($principal, $availPool, 8) > 0) {
                    throw new \RuntimeException('اعتبار وام‌دهی نماینده کافی نیست. اعتبار آزاد: ' . fmt_token($availPool));
                }
            } else {
                if ((int) $agent['can_lend_from_wallet'] !== 1) {
                    throw new \RuntimeException('اعطای وام از کیف پول شخصی برای این نماینده غیرفعال است.');
                }
            }

            // Lock funding account & borrower in ascending ID order
            $ids = array_values(array_unique([$sourceUserId, $borrowerUserId]));
            sort($ids);
            $uLock = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $uid) {
                $uLock->execute([$uid]);
                $locked[$uid] = $uLock->fetch();
            }

            $borrower = $locked[$borrowerUserId] ?? null;
            $funder = $locked[$sourceUserId] ?? null;
            if (!$borrower || !$funder) {
                throw new \RuntimeException('حساب وام‌گیرنده یا تامین‌کننده یافت نشد.');
            }
            if ((int) $borrower['is_frozen'] === 1) {
                throw new \RuntimeException('حساب وام‌گیرنده مسدود است.');
            }
            if ((int) ($borrower['parent_agent_id'] ?? 0) !== $agentUserId) {
                throw new \RuntimeException('وام‌گیرنده باید زیرمجموعه مستقیم همین نماینده باشد.');
            }
            if (bccomp((string) $funder['balance'], $principal, 8) < 0) {
                throw new \RuntimeException('موجودی منبع تامین وام (' . ($fundingSource === 'credit_pool' ? 'خزانه مرکزی' : 'کیف پول نماینده') . ') کافی نیست.');
            }

            // Update balances atomically
            $funderAfter = bcsub((string) $funder['balance'], $principal, 8);
            $borrowerAfter = bcadd((string) $borrower['balance'], $principal, 8);

            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$funderAfter, $principal, $sourceUserId]);
            $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                ->execute([$borrowerAfter, $principal, $borrowerUserId]);

            if ($fundingSource === 'credit_pool') {
                $pdo->prepare('UPDATE agents SET credit_pool_used = credit_pool_used + ? WHERE user_id = ?')
                    ->execute([$principal, $agentUserId]);
            }

            $interestAmt = bcdiv(bcmul($principal, (string) $interestBps, 12), '10000', 8);
            $totalRepay = bcadd($principal, $interestAmt, 8);
            $firstDue = date('Y-m-d H:i:s', time() + ($intervalDays * 86400));

            if ($requestLoanId !== null) {
                $rq = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND agent_user_id = ? AND status = "requested" FOR UPDATE');
                $rq->execute([$requestLoanId, $agentUserId]);
                $reqRow = $rq->fetch();
                if (!$reqRow) {
                    throw new \RuntimeException('درخواست وام موردنظر معتبر نیست.');
                }
                $loanId = $requestLoanId;
                $code = (string) $reqRow['loan_code'];
            } else {
                $code = 'LN-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $ins = $pdo->prepare(
                    'INSERT INTO loans (
                        loan_code, agent_user_id, borrower_user_id, funding_source, principal_amount,
                        interest_bps, interest_amount, total_repayable, remaining_amount, penalty_bps_per_late,
                        installments_count, interval_days, auto_deduct, status, purpose, next_due_at, approved_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, NOW())'
                );
                $ins->execute([
                    $code, $agentUserId, $borrowerUserId, $fundingSource, $principal,
                    $interestBps, $interestAmt, $totalRepay, $totalRepay,
                    (int) Settings::get('default_penalty_bps', '200'),
                    $installmentsCount, $intervalDays, $autoDeduct, $purpose, $firstDue,
                ]);
                $loanId = (int) $pdo->lastInsertId();
            }

            $tx = Ledger::appendLocked($pdo, [
                'tx_type'                => 'loan_disburse',
                'from_user_id'           => $sourceUserId,
                'to_user_id'             => $borrowerUserId,
                'gross_amount'           => $principal,
                'net_amount'             => $principal,
                'sender_balance_after'   => $funderAfter,
                'receiver_balance_after' => $borrowerAfter,
                'channel'                => 'agent',
                'reference_id'           => $code,
                'memo'                   => "اعطای وام {$code} توسط نماینده — {$purpose}",
                'meta'                   => [
                    'loan_id'        => $loanId,
                    'agent_user_id'  => $agentUserId,
                    'funding_source' => $fundingSource,
                    'total_repay'    => $totalRepay,
                ],
            ]);

            if ($requestLoanId !== null) {
                $pdo->prepare(
                    'UPDATE loans SET funding_source = ?, principal_amount = ?, interest_bps = ?, interest_amount = ?,
                                      total_repayable = ?, remaining_amount = ?, installments_count = ?, interval_days = ?,
                                      auto_deduct = ?, status = "active", purpose = ?, disbursed_tx_hash = ?, next_due_at = ?, approved_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $fundingSource, $principal, $interestBps, $interestAmt,
                    $totalRepay, $totalRepay, $installmentsCount, $intervalDays,
                    $autoDeduct, $purpose, $tx['tx_hash'], $firstDue, $loanId,
                ]);
            } else {
                $pdo->prepare('UPDATE loans SET disbursed_tx_hash = ? WHERE id = ?')->execute([$tx['tx_hash'], $loanId]);
            }

            // Build exact amortized installment schedule (final installment absorbs rounding delta!)
            $perInst = bcdiv($totalRepay, (string) $installmentsCount, 8);
            $allocated = '0.00000000';
            $instStmt = $pdo->prepare(
                'INSERT INTO loan_installments (loan_id, installment_no, due_at, amount_due) VALUES (?, ?, ?, ?)'
            );
            for ($n = 1; $n <= $installmentsCount; $n++) {
                $amt = ($n === $installmentsCount)
                    ? bcsub($totalRepay, $allocated, 8)
                    : $perInst;
                $allocated = bcadd($allocated, $amt, 8);
                $dueAt = date('Y-m-d H:i:s', time() + ($n * $intervalDays * 86400));
                $instStmt->execute([$loanId, $n, $dueAt, $amt]);
            }

            return ['loan_id' => $loanId, 'code' => $code, 'borrower' => $borrower, 'borrower_after' => $borrowerAfter, 'total_repay' => $totalRepay];
        });

        MessengerClient::notifyUser(
            $loan['borrower'],
            "🏦 <b>وام جدید به کیف پول شما واریز شد!</b>\n\n"
            . "🆔 شماره تسهیلات: <code>" . e($loan['code']) . "</code>\n"
            . "💰 مبلغ واریز شده: <b>" . fmt_token($principal) . "</b>\n"
            . "📊 مجموع بازپرداخت: <b>" . fmt_token($loan['total_repay']) . "</b> در " . fa_digits($installmentsCount) . " قسط\n"
            . "💼 موجودی فعلی شما: <b>" . fmt_token($loan['borrower_after']) . "</b>"
        );

        return self::findById((int) $loan['loan_id']) ?? [];
    }

    /**
     * Pays the next unpaid installment (or full remaining balance when $fullPayoff = true) atomically!
     * Returns principal to Treasury (restoring Agent's revolving credit pool!) + credits interest profit directly to Agent's wallet!
     *
     * @return array<string,mixed>
     */
    public static function repayInstallment(int $loanId, ?int $expectedBorrowerId = null, bool $fullPayoff = false, string $channel = 'wallet'): array
    {
        Users::ensureSystemAccounts();
        $treasuryId = (int) Users::systemAccount('treasury')['id'];

        $res = Db::tx(function (PDO $pdo) use ($loanId, $expectedBorrowerId, $fullPayoff, $channel, $treasuryId): array {
            $lnStmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
            $lnStmt->execute([$loanId]);
            $loan = $lnStmt->fetch();
            if (!$loan || !in_array($loan['status'], ['active', 'overdue'], true)) {
                throw new \RuntimeException('پرونده وام فعال یا قابل پرداخت نیست.');
            }
            $borrowerId = (int) $loan['borrower_user_id'];
            $agentId = (int) $loan['agent_user_id'];
            if ($expectedBorrowerId !== null && $expectedBorrowerId !== $borrowerId) {
                throw new \RuntimeException('این وام متعلق به حساب کاربری شما نیست.');
            }

            $instStmt = $pdo->prepare(
                'SELECT * FROM loan_installments WHERE loan_id = ? AND status IN ("pending","overdue") ORDER BY installment_no ASC FOR UPDATE'
            );
            $instStmt->execute([$loanId]);
            $unpaid = $instStmt->fetchAll();
            if (empty($unpaid)) {
                throw new \RuntimeException('تمامی اقساط این وام قبلاً تسویه شده‌اند.');
            }

            $targets = $fullPayoff ? $unpaid : [$unpaid[0]];
            $payTotal = '0.00000000';
            foreach ($targets as $inst) {
                $remInst = bcsub(bcadd((string) $inst['amount_due'], (string) $inst['penalty_added'], 8), (string) $inst['amount_paid'], 8);
                $payTotal = bcadd($payTotal, $remInst, 8);
            }

            $agStmt = $pdo->prepare('SELECT * FROM agents WHERE user_id = ? FOR UPDATE');
            $agStmt->execute([$agentId]);
            $agent = $agStmt->fetch();

            $ids = array_values(array_unique([$borrowerId, $agentId, $treasuryId]));
            sort($ids);
            $uLock = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($ids as $uid) {
                $uLock->execute([$uid]);
                $locked[$uid] = $uLock->fetch();
            }

            $borrower = $locked[$borrowerId];
            if (bccomp((string) $borrower['balance'], $payTotal, 8) < 0) {
                throw new \RuntimeException('موجودی کیف پول برای پرداخت قسط کافی نیست (' . fmt_token($payTotal) . ' مورد نیاز است).');
            }

            $borrowerAfter = bcsub((string) $borrower['balance'], $payTotal, 8);
            $pdo->prepare('UPDATE users SET balance = ?, total_sent = total_sent + ? WHERE id = ?')
                ->execute([$borrowerAfter, $payTotal, $borrowerId]);

            // Calculate exact principal portion vs profit/penalty portion (zero rounding drift!)
            $unpaidCountStmt = $pdo->prepare('SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND status <> "paid"');
            $unpaidCountStmt->execute([$loanId]);
            $willCloseLoan = count($targets) >= (int) $unpaidCountStmt->fetchColumn();

            if ($loan['funding_source'] === 'credit_pool') {
                $ratioPrincipal = bccomp((string) $loan['total_repayable'], '0', 8) > 0
                    ? bcdiv((string) $loan['principal_amount'], (string) $loan['total_repayable'], 12)
                    : '1';

                $paidRowsStmt = $pdo->prepare('SELECT amount_due FROM loan_installments WHERE loan_id = ? AND status = "paid"');
                $paidRowsStmt->execute([$loanId]);
                $priorPrincipalRecovered = '0.00000000';
                foreach ($paidRowsStmt->fetchAll() as $pr) {
                    $priorPrincipalRecovered = bcadd($priorPrincipalRecovered, bcmul((string) $pr['amount_due'], $ratioPrincipal, 8), 8);
                }

                if ($willCloseLoan) {
                    $principalRecovered = bcsub((string) $loan['principal_amount'], $priorPrincipalRecovered, 8);
                } else {
                    $baseThisCall = '0.00000000';
                    foreach ($targets as $tRow) {
                        $baseThisCall = bcadd($baseThisCall, bcmul((string) $tRow['amount_due'], $ratioPrincipal, 8), 8);
                    }
                    $principalRecovered = $baseThisCall;
                }
                if (bccomp($principalRecovered, '0', 8) < 0) {
                    $principalRecovered = '0.00000000';
                }
                if (bccomp($principalRecovered, $payTotal, 8) > 0) {
                    $principalRecovered = $payTotal;
                }
                $agentProfit = bcsub($payTotal, $principalRecovered, 8);

                if (bccomp($principalRecovered, '0', 8) > 0) {
                    $pdo->prepare('UPDATE users SET balance = balance + ?, total_received = total_received + ? WHERE id = ?')
                        ->execute([$principalRecovered, $principalRecovered, $treasuryId]);
                    $pdo->prepare('UPDATE agents SET credit_pool_used = GREATEST(0, credit_pool_used - ?) WHERE user_id = ?')
                        ->execute([$principalRecovered, $agentId]);
                }
                if (bccomp($agentProfit, '0', 8) > 0) {
                    $pdo->prepare('UPDATE users SET balance = balance + ?, total_received = total_received + ? WHERE id = ?')
                        ->execute([$agentProfit, $agentProfit, $agentId]);
                }
                $primaryReceiverId = bccomp($principalRecovered, '0', 8) > 0 ? $treasuryId : $agentId;
                $receiverAfter = bcadd((string) $locked[$primaryReceiverId]['balance'], $principalRecovered, 8);
            } else {
                // 100% funded by Agent's personal wallet -> 100% repaid back to Agent's wallet!
                $agentAfter = bcadd((string) $locked[$agentId]['balance'], $payTotal, 8);
                $pdo->prepare('UPDATE users SET balance = ?, total_received = total_received + ? WHERE id = ?')
                    ->execute([$agentAfter, $payTotal, $agentId]);
                $primaryReceiverId = $agentId;
                $receiverAfter = $agentAfter;
            }

            $tx = Ledger::appendLocked($pdo, [
                'tx_type'                => 'loan_repay',
                'from_user_id'           => $borrowerId,
                'to_user_id'             => $primaryReceiverId,
                'gross_amount'           => $payTotal,
                'net_amount'             => $payTotal,
                'sender_balance_after'   => $borrowerAfter,
                'receiver_balance_after' => $receiverAfter,
                'channel'                => $channel,
                'reference_id'           => (string) $loan['loan_code'],
                'memo'                   => 'بازپرداخت وام ' . $loan['loan_code'],
            ]);

            foreach ($targets as $inst) {
                $fullInst = bcadd((string) $inst['amount_due'], (string) $inst['penalty_added'], 8);
                $pdo->prepare('UPDATE loan_installments SET amount_paid = ?, status = "paid", paid_at = NOW(), paid_tx_hash = ? WHERE id = ?')
                    ->execute([$fullInst, $tx['tx_hash'], (int) $inst['id']]);
            }

            // Recompute remaining loan balance cleanly from remaining installments
            $remStmt = $pdo->prepare('SELECT due_at, amount_due, penalty_added, amount_paid FROM loan_installments WHERE loan_id = ? AND status <> "paid" ORDER BY installment_no ASC');
            $remStmt->execute([$loanId]);
            $leftRows = $remStmt->fetchAll();

            $newRemaining = '0.00000000';
            $nextDue = null;
            foreach ($leftRows as $lr) {
                if ($nextDue === null) $nextDue = (string) $lr['due_at'];
                $newRemaining = bcadd($newRemaining, bcsub(bcadd((string) $lr['amount_due'], (string) $lr['penalty_added'], 8), (string) $lr['amount_paid'], 8), 8);
            }

            $newRepaid = bcadd((string) $loan['repaid_amount'], $payTotal, 8);
            $newStatus = empty($leftRows) ? 'repaid' : 'active';

            $pdo->prepare(
                'UPDATE loans SET repaid_amount = ?, remaining_amount = ?, status = ?, next_due_at = ?, completed_at = IF(? = "repaid", NOW(), completed_at)
                 WHERE id = ?'
            )->execute([$newRepaid, $newRemaining, $newStatus, $nextDue, $newStatus, $loanId]);

            // Reward punctual repayment with Credit Score increase (+15 up to 850)
            $pdo->prepare('UPDATE users SET credit_score = LEAST(850, credit_score + 15) WHERE id = ?')->execute([$borrowerId]);

            return [
                'tx'           => $tx,
                'paid_amount'  => $payTotal,
                'loan_status'  => $newStatus,
                'remaining'    => $newRemaining,
                'borrower'     => $borrower,
                'loan_code'    => (string) $loan['loan_code'],
            ];
        });

        MessengerClient::notifyUser(
            $res['borrower'],
            "✅ <b>رسید بازپرداخت قسط وام</b>\n\n"
            . "🆔 پرونده: <code>" . e($res['loan_code']) . "</code>\n"
            . "💸 مبلغ پرداخت‌شده: <b>" . fmt_token($res['paid_amount']) . "</b>\n"
            . "📊 مانده بدهی: <b>" . fmt_token($res['remaining']) . "</b>\n"
            . "🌟 امتیاز خوش‌حسابی شما ارتقا یافت!"
        );

        return $res;
    }

    public static function rejectRequest(int $loanId, int $agentUserId): void
    {
        $stmt = Db::pdo()->prepare('UPDATE loans SET status = "rejected" WHERE id = ? AND agent_user_id = ? AND status = "requested"');
        $stmt->execute([$loanId, $agentUserId]);
    }

    public static function findById(int $loanId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT l.*, u.full_name AS borrower_name, u.wallet_code AS borrower_code, u.evm_address AS borrower_address,
                    ag.title AS agent_title, au.wallet_code AS agent_wallet_code
             FROM loans l
             JOIN users u ON u.id = l.borrower_user_id
             JOIN users au ON au.id = l.agent_user_id
             LEFT JOIN agents ag ON ag.user_id = l.agent_user_id
             WHERE l.id = ?
             LIMIT 1'
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan) return null;

        $inst = Db::pdo()->prepare('SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_no ASC');
        $inst->execute([$loanId]);
        $loan['installments'] = $inst->fetchAll();
        return $loan;
    }

    /**
     * Cron Engine: Auto-collects matured auto-deduct installments, sends upcoming due notifications,
     * applies one-time late penalty & marks overdue loans.
     *
     * @return array<string,int>
     */
    public static function processScheduledInstallments(): array
    {
        $pdo = Db::pdo();
        $stats = ['auto_paid' => 0, 'marked_overdue' => 0, 'reminders_sent' => 0];

        // 1. Send 24-hour reminders for pending installments not yet reminded
        $remHours = max(1, (int) Settings::get('loan_reminder_hours_before', '24'));
        $remStmt = $pdo->prepare(
            'SELECT li.*, l.loan_code, l.borrower_user_id
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.status = "pending" AND li.reminded_at IS NULL
               AND li.due_at > NOW() AND li.due_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
             LIMIT 50'
        );
        $remStmt->execute([$remHours]);
        foreach ($remStmt->fetchAll() as $r) {
            $u = Users::findById((int) $r['borrower_user_id']);
            if ($u) {
                MessengerClient::notifyUser(
                    $u,
                    "⏰ <b>یادآوری سررسید قسط وام</b>\n\n"
                    . "🆔 وام: <code>" . e($r['loan_code']) . "</code> — قسط #" . fa_digits($r['installment_no']) . "\n"
                    . "💰 مبلغ قسط: <b>" . fmt_token($r['amount_due']) . "</b>\n"
                    . "📅 سررسید: <code>" . e($r['due_at']) . "</code>"
                );
            }
            $pdo->prepare('UPDATE loan_installments SET reminded_at = NOW() WHERE id = ?')->execute([(int) $r['id']]);
            $stats['reminders_sent']++;
        }

        // 2. Process due installments
        $dueRows = $pdo->query(
            'SELECT li.*, l.auto_deduct, l.penalty_bps_per_late, l.borrower_user_id, l.loan_code
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.status IN ("pending", "overdue")
               AND l.status IN ("active", "overdue")
               AND li.due_at <= NOW()
             ORDER BY li.due_at ASC
             LIMIT 100'
        )->fetchAll();

        foreach ($dueRows as $row) {
            $loanId = (int) $row['loan_id'];
            if ((int) $row['auto_deduct'] === 1) {
                try {
                    self::repayInstallment($loanId, null, false, 'cron');
                    $stats['auto_paid']++;
                    continue;
                } catch (\Throwable) {
                    // Insufficient balance -> fall through to overdue handling
                }
            }

            if ($row['status'] === 'pending') {
                $penBps = (int) $row['penalty_bps_per_late'];
                $pen = $penBps > 0 ? bcdiv(bcmul((string) $row['amount_due'], (string) $penBps, 12), '10000', 8) : '0.00000000';
                Db::tx(function (PDO $pdo) use ($row, $loanId, $pen): void {
                    $pdo->prepare('UPDATE loan_installments SET status = "overdue", penalty_added = ? WHERE id = ?')
                        ->execute([$pen, (int) $row['id']]);
                    $pdo->prepare('UPDATE loans SET status = "overdue", remaining_amount = remaining_amount + ? WHERE id = ?')
                        ->execute([$pen, $loanId]);
                    $pdo->prepare('UPDATE users SET credit_score = GREATEST(300, credit_score - 25) WHERE id = ?')
                        ->execute([(int) $row['borrower_user_id']]);
                });
                $stats['marked_overdue']++;
            }
        }

        return $stats;
    }
}
