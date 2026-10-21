<?php
declare(strict_types=1);

namespace App;

/**
 * Merchant Token Payment Gateway Pro Engine (/pay/?code=INV-... + REST API + Signed Webhooks + Atomic Refunds).
 */
final class Invoices
{
    public static function create(
        int $merchantUserId,
        string $rawAmount,
        string $title,
        string $callbackUrl = '',
        string $externalOrderId = '',
        int $ttlMinutes = 30,
        string $returnUrl = ''
    ): array {
        $amount = dec_norm($rawAmount);
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ فاکتور باید بزرگ‌تر از صفر باشد.');
        }
        if ($callbackUrl !== '') {
            IpGuard::assertSafeOutboundUrl($callbackUrl);
        }
        if ($returnUrl !== '' && !preg_match('~^https?://~i', $returnUrl)) {
            throw new \InvalidArgumentException('آدرس بازگشت به فروشگاه باید با http یا https آغاز شود.');
        }

        $code = 'INV-' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = Db::pdo()->prepare(
            'INSERT INTO merchant_invoices (invoice_code, merchant_user_id, amount, title, callback_url, return_url, external_order_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $stmt->execute([
            $code,
            $merchantUserId,
            $amount,
            mb_substr(trim($title) ?: 'فاکتور پرداخت آنلاین', 0, 180),
            mb_substr(trim($callbackUrl), 0, 255),
            mb_substr(trim($returnUrl), 0, 255),
            mb_substr(trim($externalOrderId), 0, 96),
            max(5, min(10080, $ttlMinutes)),
        ]);

        return self::findByCode($code) ?? [];
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.*, (i.expires_at < NOW()) AS is_past_expiry,
                    u.full_name AS merchant_name, u.wallet_code AS merchant_wallet_code, u.evm_address AS merchant_address,
                    pu.full_name AS payer_name, pu.wallet_code AS payer_wallet_code
             FROM merchant_invoices i
             JOIN users u ON u.id = i.merchant_user_id
             LEFT JOIN users pu ON pu.id = i.payer_user_id
             WHERE i.invoice_code = ?
             LIMIT 1'
        );
        $stmt->execute([trim($code)]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed> */
    public static function pay(string $code, int $payerUserId, string $channel = 'web_gateway'): array
    {
        $inv = self::findByCode($code);
        if (!$inv) {
            throw new \RuntimeException('صورت‌حساب یافت نشد.');
        }
        if ($inv['status'] === 'paid') {
            throw new \RuntimeException('این صورت‌حساب قبلاً پرداخت شده است.');
        }
        if ($inv['status'] === 'refunded') {
            throw new \RuntimeException('این صورت‌حساب قبلاً مسترد شده است.');
        }
        if ((int) ($inv['is_past_expiry'] ?? 0) === 1 || $inv['status'] === 'expired') {
            Db::pdo()->prepare('UPDATE merchant_invoices SET status = "expired" WHERE id = ?')->execute([(int) $inv['id']]);
            throw new \RuntimeException('مهلت پرداخت این صورت‌حساب به پایان رسیده است.');
        }

        $txRes = TokenEngine::transfer(
            $payerUserId,
            (int) $inv['merchant_user_id'],
            (string) $inv['amount'],
            $channel,
            'پرداخت صورت‌حساب ' . $inv['invoice_code'] . ' — ' . $inv['title'],
            'inv-pay-' . $inv['invoice_code'],
            false,
            null,
            'invoice_pay'
        );

        Db::pdo()->prepare(
            'UPDATE merchant_invoices SET status = "paid", payer_user_id = ?, paid_tx_hash = ?, paid_at = NOW() WHERE id = ?'
        )->execute([$payerUserId, $txRes['tx']['tx_hash'], (int) $inv['id']]);

        // Notify merchant callback webhook with HMAC-SHA256 verification header
        if (!empty($inv['callback_url'])) {
            $body = json_encode([
                'event'             => 'invoice.paid',
                'invoice_code'      => $inv['invoice_code'],
                'external_order_id' => $inv['external_order_id'],
                'amount'            => $inv['amount'],
                'status'            => 'paid',
                'tx_hash'           => $txRes['tx']['tx_hash'],
                'paid_at'           => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $sig = hash_hmac('sha256', $body, (string) Config::get('app_secret', ''));
            $ch = curl_init((string) $inv['callback_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-YarPay-Signature: sha256=' . $sig,
                ],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 6,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        }

        return $txRes;
    }

    /**
     * Atomic Refund: returns full invoice amount from Merchant back to Customer (zero fee) and records hash-chained block!
     *
     * @return array<string,mixed>
     */
    public static function refund(string $code, ?int $expectedMerchantId = null, ?int $adminId = null): array
    {
        $inv = self::findByCode($code);
        if (!$inv) {
            throw new \RuntimeException('صورت‌حساب موردنظر یافت نشد.');
        }
        if ($inv['status'] !== 'paid' || empty($inv['payer_user_id'])) {
            throw new \RuntimeException('فقط صورت‌حساب‌های پرداخت‌شده قابل عودت وجه (Refund) هستند.');
        }
        if ($expectedMerchantId !== null && (int) $inv['merchant_user_id'] !== $expectedMerchantId) {
            throw new \RuntimeException('شما مجوز استرداد این صورت‌حساب را ندارید.');
        }

        $txRes = TokenEngine::transfer(
            (int) $inv['merchant_user_id'],
            (int) $inv['payer_user_id'],
            (string) $inv['amount'],
            $adminId ? 'admin' : 'web_gateway',
            'استرداد وجه (Refund) صورت‌حساب ' . $inv['invoice_code'],
            'inv-refund-' . $inv['invoice_code'],
            true, // Zero fee on refunds!
            null,
            'invoice_refund'
        );

        Db::pdo()->prepare('UPDATE merchant_invoices SET status = "refunded", refund_tx_hash = ? WHERE id = ?')
            ->execute([$txRes['tx']['tx_hash'], (int) $inv['id']]);

        if ($adminId) {
            Audit::record('admin', $adminId, 'gateway.refund', 'استرداد وجه فاکتور ' . $inv['invoice_code'], [
                'amount'  => $inv['amount'],
                'tx_hash' => $txRes['tx']['tx_hash'],
            ]);
        }

        return $txRes;
    }

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $pdo = Db::pdo();
        return [
            'total_count'   => (int) $pdo->query('SELECT COUNT(*) FROM merchant_invoices')->fetchColumn(),
            'paid_count'    => (int) $pdo->query("SELECT COUNT(*) FROM merchant_invoices WHERE status = 'paid'")->fetchColumn(),
            'pending_count' => (int) $pdo->query("SELECT COUNT(*) FROM merchant_invoices WHERE status = 'pending' AND expires_at >= NOW()")->fetchColumn(),
            'refund_count'  => (int) $pdo->query("SELECT COUNT(*) FROM merchant_invoices WHERE status = 'refunded'")->fetchColumn(),
            'paid_volume'   => dec_norm((string) ($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM merchant_invoices WHERE status = 'paid'")->fetchColumn() ?: '0')),
        ];
    }
}
