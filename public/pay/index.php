<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Auth;
use App\Crypto\QrSvg;
use App\Csrf;
use App\FeeCalculator;
use App\Invoices;
use App\Web\View;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$inv = $code !== '' ? Invoices::findByCode($code) : null;
$user = Auth::user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $inv && $user) {
    Csrf::assertPost();
    try {
        Invoices::pay((string) $inv['invoice_code'], (int) $user['id'], 'web_gateway');
        flash_set('success', 'پرداخت صورت‌حساب با موفقیت انجام و رسید آن روی زنجیره ثبت شد!');
        redirect_to(base_url('pay/?code=' . urlencode((string) $inv['invoice_code'])));
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to(base_url('pay/?code=' . urlencode((string) $inv['invoice_code'])));
    }
}

$quote = null;
if ($inv && $inv['status'] === 'pending' && $user && (int) $user['id'] !== (int) $inv['merchant_user_id']) {
    try {
        $quote = FeeCalculator::quote($user, ['id' => $inv['merchant_user_id'], 'account_type' => 'merchant', 'fee_tier' => 'standard', 'parent_agent_id' => null], (string) $inv['amount']);
    } catch (\Throwable) {
        $quote = null;
    }
}

ob_start();
if (!$inv): ?>
    <div style="text-align:center; margin-bottom:14px;">
        <div class="brand-coin" style="margin:0 auto 10px; width:52px; height:52px; font-size:22px;">💳</div>
        <h2 style="margin:0;">درگاه پرداخت هوشمند توکنی (IPG)</h2>
        <p style="color:#64748b; font-size:13px; margin:6px 0 0;">کد صورت‌حساب پذیرنده (مانند <code>INV-...</code>) را جهت استعلام و پرداخت آنی وارد کنید:</p>
    </div>
    <form method="get">
        <div class="field">
            <label>شناسه یکتای صورت‌حساب</label>
            <input name="code" placeholder="INV-XXXXXXXXXX" class="ltr" required autofocus>
        </div>
        <button class="btn btn-primary" style="width:100%; padding:12px;" type="submit">🔍 استعلام صورت‌حساب</button>
    </form>
<?php else:
    $statusMap = [
        'paid'     => ['badge-success', '✓ پرداخت و تایید نهایی شده'],
        'refunded' => ['badge-indigo',  '↩ مبلغ به حساب خریدار مسترد شد'],
        'expired'  => ['badge-danger',  '⏰ مهلت پرداخت منقضی شده'],
        'pending'  => ['badge-warning', '⏳ در انتظار پرداخت امن'],
    ];
    $effStatus = ($inv['status'] === 'pending' && (int) ($inv['is_past_expiry'] ?? 0) === 1) ? 'expired' : (string) $inv['status'];
    [$bCls, $bText] = $statusMap[$effStatus] ?? $statusMap['pending'];
    ?>
    <div style="text-align:center;">
        <span class="badge <?= $bCls ?>"><?= $bText ?></span>
        <h2 style="margin:12px 0 4px;"><?= e($inv['title']) ?></h2>
        <div style="color:#64748b; font-size:13px;">
            پذیرنده رسمی: <b><?= e($inv['merchant_name']) ?></b> (<code class="ltr"><?= e($inv['merchant_wallet_code']) ?></code>)
            <?php if (!empty($inv['external_order_id'])): ?>
                | سفارش: <code class="ltr">#<?= e($inv['external_order_id']) ?></code>
            <?php endif; ?>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin:16px 0;">
            <div style="font-size:28px; font-weight:700; color:#059669;"><?= fmt_token($inv['amount']) ?></div>
            <div style="font-size:12.5px; color:#64748b; margin-top:4px;">ارزش معادل تقریبی: <?= fmt_irt($inv['amount']) ?></div>
            <?php if ($quote): ?>
                <div style="font-size:12px; color:#475569; margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1;">
                    کارمزد شبکه: <b><?= fmt_token($quote['fee_amount']) ?></b> | جمع کل قابل کسر: <b><?= fmt_token($quote['sender_total_debit']) ?></b>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($effStatus === 'paid'): ?>
            <div class="alert alert-success" style="text-align:right;">
                ✅ <b>تراکنش با موفقیت روی زنجیره ثبت شد!</b><br>
                <div style="margin-top:6px; font-size:12.5px;">
                    کد پیگیری بلاکچین: <a href="<?= e(base_url('?q=' . urlencode((string) $inv['paid_tx_hash']))) ?>"><code class="ltr"><?= e(substr((string) $inv['paid_tx_hash'], 0, 24)) ?>…</code></a>
                </div>
            </div>
            <?php if (!empty($inv['return_url'])): ?>
                <a class="btn btn-primary" style="width:100%; padding:12px;" href="<?= e($inv['return_url'] . (str_contains((string) $inv['return_url'], '?') ? '&' : '?') . 'invoice=' . urlencode((string) $inv['invoice_code']) . '&status=paid') ?>">
                    🏪 تکمیل خرید و بازگشت به سایت پذیرنده
                </a>
            <?php endif; ?>
        <?php elseif ($effStatus === 'refunded'): ?>
            <div class="alert alert-info">
                ↩ وجه این صورت‌حساب به صورت کامل به کیف پول خریدار مسترد (Refund) شده است.
            </div>
        <?php elseif ($effStatus === 'expired'): ?>
            <div class="alert alert-error">
                ⏰ مهلت زمانی پرداخت این صورت‌حساب به پایان رسیده است. لطفاً فاکتور جدید صادر کنید.
            </div>
        <?php else: ?>
            <div class="qr-plate" style="margin-bottom:14px;">
                <?= QrSvg::render('yartoken:' . $inv['merchant_address'] . '?amount=' . $inv['amount'] . '&invoice=' . $inv['invoice_code'], 176) ?>
            </div>
            <div style="font-size:12px; color:#64748b; margin-bottom:14px;">
                مهلت اعتبار فاکتور تا: <code class="ltr"><?= e($inv['expires_at']) ?></code>
            </div>

            <?php if ($user): ?>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="code" value="<?= e($inv['invoice_code']) ?>">
                    <button class="btn btn-primary" style="width:100%; padding:12px;" type="submit">
                        🚀 تایید و پرداخت آنی از کیف پول من (موجودی: <?= fmt_token($user['balance']) ?>)
                    </button>
                </form>
            <?php else: ?>
                <a class="btn btn-indigo" style="width:100%; padding:12px;" href="<?= e(base_url('wallet/?r=login')) ?>">
                    🔐 ورود به کیف پول وب جهت پرداخت آنی
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <div style="margin-top:14px;"><a href="<?= e(base_url()) ?>" style="font-size:12.5px;">بازگشت به کاوشگر شبکه</a></div>
    </div>
<?php endif;

View::authPage('درگاه پرداخت هوشمند توکنی', (string) ob_get_clean());
