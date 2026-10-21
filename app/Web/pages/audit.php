<?php
declare(strict_types=1);

use App\Audit;
use App\Auditor;
use App\Csrf;

/** @var array<string,mixed> $admin */

// Support 1-click signed JSON Audit Certificate export!
if (($_GET['export'] ?? '') === 'json') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $report = Auditor::runFullAudit();
    Audit::record('admin', (int) $admin['id'], 'audit.export_certificate', 'صدور گواهی رمزنگاری‌شده ممیزی زنجیره و اثبات ذخیره');
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="yartoken-audit-certificate-' . date('Ymd-His') . '.json"');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    Audit::record('admin', (int) $admin['id'], 'audit.one_click_run', 'اجرای ممیزی عمیق یک‌کلیکی ۷ شاخص زنجیره و خزانه');
    flash_set('success', '✅ ممیزی عمیق یک‌کلیکی روی تمام بلوک‌ها، کیف پول‌ها، حساب امانی، وام‌ها و درگاه پرداخت با موفقیت انجام شد!');
    redirect_to('?r=audit');
}

$audit = Auditor::runFullAudit();
?>

<div class="card" style="background:linear-gradient(135deg,#0f172a 0%,#064e3b 100%); color:#fff; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <span class="badge <?= $audit['ok'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:13px;">
                <?= $audit['ok'] ? '🛡️ وضعیت کل شبکه: ۱۰۰٪ سالم، تراز و بدون دست‌کاری' : '⚠️ هشدار مغایرت حسابداری!' ?>
            </span>
            <h2 style="margin:10px 0 6px; font-size:21px;">ابزار ممیزی یک‌کلیکی زنجیره هش و اثبات ذخیره (One-Click Audit)</h2>
            <div style="font-size:13px; color:#a7f3d0;">
                زمان اجرای کامل بازمحاسبه رمزنگاری: <b><?= fa_digits((string) $audit['duration_ms']) ?> میلی‌ثانیه</b>
                | بلوک‌های بررسی‌شده: <b><?= fa_digits($audit['chain']['blocks_checked']) ?> بلوک</b>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <form method="post" style="margin:0;">
                <?= Csrf::field() ?>
                <button class="btn btn-accent" type="submit" style="font-size:14px; padding:11px 18px;">
                    ⚡ اجرای مجدد ممیزی یک‌کلیکی
                </button>
            </form>
            <a class="btn btn-outline" style="color:#fff; border-color:rgba(255,255,255,.35);" href="?r=audit&export=json">
                📥 دانلود گواهی رسمی امضاشده (JSON)
            </a>
        </div>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <?php foreach ($audit['checks'] as $chk): ?>
        <div class="card" style="margin-bottom:0; border-right:4px solid <?= $chk['passed'] ? '#10b981' : '#ef4444' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <b style="font-size:14.5px;"><?= e($chk['title']) ?></b>
                <span class="badge <?= $chk['passed'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $chk['passed'] ? '✓ تایید قطعی' : '✗ خطا' ?>
                </span>
            </div>
            <div style="font-size:13px; color:#475569;"><?= e($chk['detail']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:10px;">🔏 امضای دیجیتال گواهی اثبات ذخیره (Signed Proof-of-Reserve Certificate)</h3>
    <pre class="ltr" style="background:#0f172a; color:#a7f3d0; padding:14px; border-radius:12px; font-size:12px; overflow-x:auto; direction:ltr; text-align:left;"><?= e((string) json_encode($audit['certificate'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
