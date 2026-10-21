<?php
declare(strict_types=1);

use App\Csrf;
use App\Db;
use App\Invoices;
use App\Users;

/** @var array<string,mixed> $admin */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    $act = (string) ($_POST['form_action'] ?? '');

    try {
        if ($act === 'create_invoice') {
            $merchant = Users::resolveRecipient((string) ($_POST['merchant_id'] ?? ''));
            if (!$merchant) {
                throw new RuntimeException('کیف پول پذیرنده یافت نشد (شناسه YAR-... یا آدرس 0x... معتبر وارد کنید).');
            }
            $inv = Invoices::create(
                (int) $merchant['id'],
                (string) ($_POST['amount'] ?? '0'),
                (string) ($_POST['title'] ?? 'فاکتور فروشگاهی'),
                (string) ($_POST['callback_url'] ?? ''),
                (string) ($_POST['external_order_id'] ?? ''),
                (int) ($_POST['ttl_minutes'] ?? 60),
                (string) ($_POST['return_url'] ?? '')
            );
            flash_set('success', 'صورت‌حساب درگاه پرداخت صادر شد: ' . $inv['invoice_code']);
        } elseif ($act === 'refund_invoice') {
            $code = (string) ($_POST['invoice_code'] ?? '');
            Invoices::refund($code, null, (int) $admin['id']);
            flash_set('success', "مبلغ صورت‌حساب {$code} به صورت اتمیک و بدون کارمزد به کیف پول خریدار مسترد شد.");
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('?r=gateway');
}

$stats = Invoices::stats();
$invoices = Db::pdo()->query(
    'SELECT i.*, u.full_name AS merchant_name, u.wallet_code AS merchant_code, pu.wallet_code AS payer_code
     FROM merchant_invoices i
     JOIN users u ON u.id = i.merchant_user_id
     LEFT JOIN users pu ON pu.id = i.payer_user_id
     ORDER BY i.id DESC LIMIT 50'
)->fetchAll();
?>

<div class="grid grid-4" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-label"><span>حجم فروش تسویه‌شده درگاه</span><span class="badge badge-success">IPG</span></div>
        <div class="stat-value"><?= fmt_token($stats['paid_volume']) ?></div>
        <div class="stat-sub">معادل: <?= fmt_irt($stats['paid_volume']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>فاکتورهای موفق</span><span class="badge badge-success">Paid</span></div>
        <div class="stat-value"><?= fa_digits($stats['paid_count']) ?></div>
        <div class="stat-sub">از مجموع <?= fa_digits($stats['total_count']) ?> صورت‌حساب</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>در انتظار پرداخت</span><span class="badge badge-warning">Pending</span></div>
        <div class="stat-value"><?= fa_digits($stats['pending_count']) ?></div>
        <div class="stat-sub">فاکتورهای باز و معتبر</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>مسترد شده (Refund)</span><span class="badge badge-indigo">↩</span></div>
        <div class="stat-value"><?= fa_digits($stats['refund_count']) ?></div>
        <div class="stat-sub">عودت آنی روی زنجیره</div>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title">💳 صدور لینک پرداخت / فاکتور جدید فروشگاهی</h3>
        <form method="post" style="margin-top:14px;">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="create_invoice">
            <div class="form-grid">
                <div class="field">
                    <label>شناسه یا نام کاربری پذیرنده (فروشنده)</label>
                    <input name="merchant_id" class="ltr" placeholder="YAR-... یا digishop یا 0x..." required>
                </div>
                <div class="field">
                    <label>مبلغ قابل پرداخت</label>
                    <input name="amount" class="ltr" placeholder="150" required>
                </div>
                <div class="field">
                    <label>عنوان سفارش / محصول</label>
                    <input name="title" placeholder="اشتراک یک‌ساله ویژه" required>
                </div>
                <div class="field">
                    <label>شماره سفارش سایت پذیرنده (اختیاری)</label>
                    <input name="external_order_id" class="ltr" placeholder="ORD-1024">
                </div>
                <div class="field">
                    <label>آدرس بازگشت خودکار مشتری پس از پرداخت (Return URL)</label>
                    <input name="return_url" class="ltr" placeholder="https://shop.example.com/callback">
                </div>
                <div class="field">
                    <label>وب‌هوک تایید آنی سروربه‌سرور (HMAC Signed Webhook)</label>
                    <input name="callback_url" class="ltr" placeholder="https://shop.example.com/api/yarpay-webhook">
                </div>
            </div>
            <button class="btn btn-primary" style="margin-top:12px;" type="submit">➕ ایجاد لینک پرداخت هوشمند</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">🔌 راهنمای اتصال سایت‌ها و فروشگاه‌ها (REST API + Webhook)</h3>
        <p style="font-size:13px; color:#475569; line-height:1.8;">
            هر فروشگاه اینترنتی (وردپرس/ووکامرس، لاراول، PHP اختصاصی یا ربات فروشگاهی) می‌تواند با ارسال درخواست <code>POST</code> با توکن پذیرنده، لینک پرداخت اختصاصی بسازد و پس از پرداخت امضای هدر <code>X-YarPay-Signature</code> را بررسی نماید:
        </p>
        <pre class="ltr" style="background:#0f172a; color:#e2e8f0; padding:12px; border-radius:12px; font-size:11.5px; overflow-x:auto; direction:ltr; text-align:left;">POST <?= e(base_url('api/v1/?action=invoices.create')) ?>

Authorization: Bearer ytk_MERCHANT_API_TOKEN
Content-Type: application/json

{
  "amount": "250.00",
  "title": "سفارش شماره ۴۰۹۲",
  "external_order_id": "WC-4092",
  "return_url": "https://yourshop.ir/thank-you",
  "callback_url": "https://yourshop.ir/wc-api/yartoken"
}</pre>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:14px;">🧾 فهرست صورت‌حساب‌های درگاه پرداخت و مدیریت استرداد وجه (Refund)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>کد فاکتور</th>
                    <th>پذیرنده</th>
                    <th>عنوان سفارش</th>
                    <th>مبلغ</th>
                    <th>وضعیت</th>
                    <th>لینک درگاه / عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $row):
                $payLink = base_url('pay/?code=' . urlencode((string) $row['invoice_code']));
                ?>
                <tr>
                    <td><code class="ltr"><?= e($row['invoice_code']) ?></code></td>
                    <td><?= e($row['merchant_name']) ?> <small>(<code><?= e($row['merchant_code']) ?></code>)</small></td>
                    <td><?= e($row['title']) ?></td>
                    <td><b><?= fmt_token($row['amount']) ?></b></td>
                    <td>
                        <?php if ($row['status'] === 'paid'): ?>
                            <span class="badge badge-success">✓ پرداخت شده (<?= e($row['payer_code'] ?? '') ?>)</span>
                        <?php elseif ($row['status'] === 'refunded'): ?>
                            <span class="badge badge-indigo">↩ مسترد شده</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= e($row['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <a class="btn btn-outline btn-sm" target="_blank" href="<?= e($payLink) ?>">مشاهده درگاه ↗</a>
                        <?php if ($row['status'] === 'paid'): ?>
                            <form method="post" onsubmit="return confirm('آیا از عودت کامل مبلغ به خریدار اطمینان دارید؟');" style="display:inline;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="form_action" value="refund_invoice">
                                <input type="hidden" name="invoice_code" value="<?= e($row['invoice_code']) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">↩ عودت وجه</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
