<?php
declare(strict_types=1);

namespace App;

/**
 * One-Click Deep Cryptographic Proof-of-Reserve & Financial Invariant Auditor (ابزار ممیزی یک‌کلیکی زنجیره و حسابداری).
 * Verifies 7 mathematical invariants across every block, wallet, escrow packet, loan & gateway invoice in milliseconds!
 */
final class Auditor
{
    /** @return array<string,mixed> */
    public static function runFullAudit(): array
    {
        $t0 = microtime(true);
        $pdo = Db::pdo();

        // Check 1 & 2: Full Genesis -> Head SHA-256 Hash Chain + Conservation of Supply
        $chain = Ledger::verifyChainIntegrity();
        $hashChainOk = $chain['broken_at'] === null;
        $supplyConservationOk = bccomp((string) $chain['supply_discrepancy'], '0.00000000', 8) === 0;

        // Check 3: Zero negative wallet or locked balances across all users/vaults
        $negCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE balance < 0 OR locked_balance < 0')->fetchColumn();
        $nonNegativeOk = $negCount === 0;

        // Check 4: Red Packet Escrow Solvency (escrow.balance === SUM(active red_packets.remaining_amount))
        $escrow = Users::systemAccount('escrow');
        $escrowActual = dec_norm((string) $escrow['balance']);
        $activePktSum = dec_norm((string) ($pdo->query("SELECT COALESCE(SUM(remaining_amount), 0) FROM red_packets WHERE status = 'active'")->fetchColumn() ?: '0'));
        $escrowDiff = bcsub($escrowActual, $activePktSum, 8);
        $escrowOk = bccomp($escrowDiff, '0.00000000', 8) === 0;

        // Check 5: Representative Credit Pool Ceiling Check (no agent exceeds credit_pool_limit or goes < 0)
        $badAgents = (int) $pdo->query('SELECT COUNT(*) FROM agents WHERE credit_pool_used < 0 OR credit_pool_used > credit_pool_limit')->fetchColumn();
        $agentPoolOk = $badAgents === 0;

        // Check 6: Active Loan Schedule Consistency (every active/overdue loan remaining_amount matches unpaid installments)
        $activeLoans = $pdo->query("SELECT id, loan_code, remaining_amount FROM loans WHERE status IN ('active', 'overdue')")->fetchAll();
        $loanMismatches = 0;
        $instStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_due + penalty_added - amount_paid), 0) FROM loan_installments WHERE loan_id = ? AND status <> 'paid'");
        foreach ($activeLoans as $ln) {
            $instStmt->execute([(int) $ln['id']]);
            $instSum = dec_norm((string) ($instStmt->fetchColumn() ?: '0'));
            if (bccomp(dec_norm((string) $ln['remaining_amount']), $instSum, 8) !== 0) {
                $loanMismatches++;
            }
        }
        $loansOk = $loanMismatches === 0;

        // Check 7: Merchant Payment Gateway Settlement Receipt Verification
        $orphanInvoices = (int) $pdo->query(
            "SELECT COUNT(*) FROM merchant_invoices i
             LEFT JOIN ledger_entries l ON l.tx_hash = i.paid_tx_hash
             WHERE i.status IN ('paid', 'refunded') AND l.id IS NULL"
        )->fetchColumn();
        $gatewayOk = $orphanInvoices === 0;

        $checks = [
            [
                'id'     => 'hash_chain',
                'title'  => 'یکپارچگی زنجیره هش رمزنگاری (SHA-256 Genesis → Head)',
                'passed' => $hashChainOk,
                'detail' => $hashChainOk
                    ? fa_digits($chain['blocks_checked']) . ' بلوک از جنسیس تا سرزنجیره بدون کوچک‌ترین تغییر بازمحاسبه و تایید شد.'
                    : 'خطا در بلوک شماره ' . ($chain['broken_at']['id'] ?? '?'),
            ],
            [
                'id'     => 'supply_conservation',
                'title'  => 'معادله بقای عرضه کل (Total Minted = کل موجودی حساب‌ها + توکن‌سوزی)',
                'passed' => $supplyConservationOk,
                'detail' => 'اختلاف تراز شبکه در ۸ رقم اعشار: ' . $chain['supply_discrepancy'] . ' ' . Settings::get('token_symbol', 'YAR'),
            ],
            [
                'id'     => 'non_negative_wallets',
                'title'  => 'عدم وجود موجودی منفی در هیچ کیف پول یا خزانه',
                'passed' => $nonNegativeOk,
                'detail' => $nonNegativeOk ? 'تمام کیف پول‌های کاربران، نمایندگان و خزانه‌ها دارای تراز معتبر ≥ ۰ هستند.' : "{$negCount} حساب نامعتبر یافت شد.",
            ],
            [
                'id'     => 'red_packet_escrow',
                'title'  => 'تطبیق ۱۰۰٪ حساب امانی (Escrow) با پاکت‌های هدیه فعال گروهی',
                'passed' => $escrowOk,
                'detail' => "موجودی حساب امانی: {$escrowActual} | مجموع پاکت‌های فعال: {$activePktSum} (اختلاف: {$escrowDiff})",
            ],
            [
                'id'     => 'agent_credit_pools',
                'title'  => 'سلامت سقف استخر اعتباری نمایندگان وام‌دهنده',
                'passed' => $agentPoolOk,
                'detail' => $agentPoolOk ? 'تمام خطوط اعتباری نمایندگان در محدوده مجاز و تراز دقیق هستند.' : "{$badAgents} خطای سقف اعتبار مشاهده شد.",
            ],
            [
                'id'     => 'loan_schedules',
                'title'  => 'تطبیق مانده وام‌های فعال با ریز جدول اقساط',
                'passed' => $loansOk,
                'detail' => fa_digits(count($activeLoans)) . ' پرونده تسهیلات فعال بررسی شد — اختلاف اقساط: ' . fa_digits($loanMismatches),
            ],
            [
                'id'     => 'merchant_gateway',
                'title'  => 'اصالت رسیدهای بلاکچینی درگاه پرداخت فروشگاهی',
                'passed' => $gatewayOk,
                'detail' => $gatewayOk ? 'تمام فاکتورهای تسویه‌شده دارای تراکنش معتبر ثبت‌شده در زنجیره هستند.' : "{$orphanInvoices} فاکتور بدون رسید یافت شد.",
            ],
        ];

        $allPassed = $hashChainOk && $supplyConservationOk && $nonNegativeOk && $escrowOk && $agentPoolOk && $loansOk && $gatewayOk;
        $elapsedMs = round((microtime(true) - $t0) * 1000, 2);
        $auditedAt = date('Y-m-d H:i:s T');

        $certPayload = [
            'platform'           => Settings::get('token_name_en', 'YarCoin') . ' (' . Settings::get('token_symbol', 'YAR') . ')',
            'status'             => $allPassed ? 'VERIFIED_SOLVENT_AND_INTACT' : 'ALERT_DISCREPANCY',
            'audited_at'         => $auditedAt,
            'blocks_verified'    => $chain['blocks_checked'],
            'head_block_hash'    => $chain['head_hash'],
            'total_minted'       => $chain['total_minted'],
            'total_burned'       => $chain['total_burned'],
            'circulating_supply' => $chain['circulating_supply'],
            'supply_discrepancy' => $chain['supply_discrepancy'],
            'escrow_locked'      => $escrowActual,
            'checks_passed'      => count(array_filter($checks, static fn(array $c): bool => $c['passed'])) . '/' . count($checks),
            'duration_ms'        => $elapsedMs,
        ];
        $certSignature = '0x' . hash_hmac('sha256', json_encode($certPayload, JSON_UNESCAPED_SLASHES) ?: '', (string) Config::get('app_secret', 'yartoken'));

        return [
            'ok'            => $allPassed,
            'duration_ms'   => $elapsedMs,
            'audited_at'    => $auditedAt,
            'chain'         => $chain,
            'escrow_locked' => $escrowActual,
            'checks'        => $checks,
            'certificate'   => $certPayload + ['hmac_proof' => $certSignature],
        ];
    }
}
