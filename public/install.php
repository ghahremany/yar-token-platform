<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Crypto\QrSvg;
use App\Csrf;
use App\Installer;

if (is_installed() && empty($_SESSION['_install_result'])) {
    redirect_to(base_url('admin/'));
}

$reqs = Installer::requirements();
$error = '';
$done = $_SESSION['_install_result'] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$done) {
    Csrf::assertPost();
    try {
        $res = Installer::install($_POST);
        $_SESSION['_install_result'] = $res;
        redirect_to(base_url('install.php?done=1'));
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['finish'])) {
    unset($_SESSION['_install_result']);
    redirect_to(base_url('admin/'));
}

$assetsUrl = base_url('assets');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>راه‌اندازی خودکار سامانه توکن اختصاصی</title>
    <link rel="stylesheet" href="<?= e($assetsUrl) ?>/app.css">
</head>
<body style="background: radial-gradient(circle at top right, #064e3b 0%, #0b1120 70%); min-height: 100vh; padding: 36px 16px;">
<div style="max-width: 820px; margin: 0 auto;">
    <div class="card" style="padding: 30px 34px; border-radius: 22px;">
        <?php if ($done): ?>
            <div class="alert alert-success">
                🎉 <b>نصب و ضرب اولیه شبکه با موفقیت کامل انجام شد!</b> فایل قفل <code>storage/installed.lock</code> فعال گردید.
            </div>
            <div class="grid grid-2">
                <div style="text-align:center; background:#f8fafc; padding:18px; border-radius:14px; border:1px solid #e2e8f0;">
                    <h3 style="margin-top:0; font-size:15px;">📱 اسکن در احرازکننده اپ اندروید (Wallet Yar / Authenticator)</h3>
                    <div class="qr-plate"><?= QrSvg::render((string) $done['totp_uri'], 186) ?></div>
                    <div style="margin-top:10px;">
                        <small style="display:block; color:#64748b;">کلید دستی TOTP (ذخیره رمزنگاری‌شده با AES-256-GCM):</small>
                        <code><?= e($done['totp_secret']) ?></code>
                    </div>
                </div>
                <div style="background:#fffbeb; padding:18px; border-radius:14px; border:1px solid #fde68a;">
                    <h3 style="margin-top:0; font-size:15px; color:#92400e;">🔑 ۸ کد بازیابی اضطراری (فقط یک‌بار نمایش داده می‌شوند!)</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:12px 0;">
                        <?php foreach ($done['recovery_codes'] as $rc): ?>
                            <code style="text-align:center; font-weight:700; padding:6px; background:#fff;"><?= e($rc) ?></code>
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#92400e;">این کدها با الگوریتم Argon2id هش شده‌اند. آن‌ها را در جای امن یادداشت نمایید.</small>
                </div>
            </div>
            <div style="margin-top: 22px; text-align: left;">
                <a class="btn btn-primary" href="<?= e(base_url('install.php?finish=1')) ?>">ورود به پنل مدیریت کل ⬅️</a>
            </div>
        <?php else: ?>
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:20px;">
                <div class="brand-coin" style="width:52px; height:52px; font-size:22px;">YAR</div>
                <div>
                    <h1 style="margin:0; font-size:21px;">نصب خودکار هسته توکن، کیف پول و وام‌دهی نمایندگان</h1>
                    <span style="color:#64748b; font-size:13px;">پشتیبانی بومی از اپلیکیشن اندروید Wallet Yar، بله، تلگرام، گروه‌ها و رمز یکبار مصرف</span>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">❌ <?= e($error) ?></div>
            <?php endif; ?>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
                <?php foreach ($reqs as $name => $ok): ?>
                    <span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>">
                        <?= $ok ? '✓' : '✗' ?> <?= e($name) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <form method="post">
                <?= Csrf::field() ?>
                <h3 class="card-title" style="margin-bottom:12px;">۱. اتصال پایگاه داده MySQL / MariaDB</h3>
                <div class="form-grid">
                    <div class="field">
                        <label>میزبان دیتابیس (Host)</label>
                        <input name="db_host" value="<?= e($_POST['db_host'] ?? '127.0.0.1') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>پورت (Port)</label>
                        <input name="db_port" value="<?= e($_POST['db_port'] ?? '3306') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>نام دیتابیس</label>
                        <input name="db_name" value="<?= e($_POST['db_name'] ?? 'yartoken_db') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>نام کاربری دیتابیس</label>
                        <input name="db_user" value="<?= e($_POST['db_user'] ?? 'root') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>رمز عبور دیتابیس</label>
                        <input type="password" name="db_pass" value="" class="ltr">
                    </div>
                    <div class="field">
                        <label>آدرس عمومی سایت (اختیاری)</label>
                        <input name="app_url" value="<?= e($_POST['app_url'] ?? '') ?>" placeholder="https://coin.yourdomain.ir" class="ltr">
                    </div>
                </div>

                <hr style="border:none; border-top:1px solid #e2e8f0; margin:18px 0;">
                <h3 class="card-title" style="margin-bottom:12px;">۲. طراحی توکن / کوین اختصاصی و فرمول کارمزد</h3>
                <div class="form-grid">
                    <div class="field">
                        <label>نام فارسی کوین / توکن</label>
                        <input name="token_name" value="<?= e($_POST['token_name'] ?? 'یارکوین') ?>" required>
                    </div>
                    <div class="field">
                        <label>نام انگلیسی (EVM Name)</label>
                        <input name="token_name_en" value="<?= e($_POST['token_name_en'] ?? 'YarCoin') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>نماد توکن (Symbol)</label>
                        <input name="token_symbol" value="<?= e($_POST['token_symbol'] ?? 'YAR') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>عرضه اولیه خزانه (Genesis Supply)</label>
                        <input name="genesis_supply" value="<?= e($_POST['genesis_supply'] ?? '1000000') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>کارمزد درصدی (BPS: 100 = 1%)</label>
                        <input name="fee_bps" value="<?= e($_POST['fee_bps'] ?? '100') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>کارمزد ثابت پایه + نرخ مرجع تومانی</label>
                        <div style="display:flex; gap:8px;">
                            <input name="fee_fixed" value="<?= e($_POST['fee_fixed'] ?? '0.25') ?>" placeholder="ثابت" class="ltr">
                            <input name="token_price_irt" value="<?= e($_POST['token_price_irt'] ?? '8500') ?>" placeholder="تومان" class="ltr">
                        </div>
                    </div>
                </div>

                <hr style="border:none; border-top:1px solid #e2e8f0; margin:18px 0;">
                <h3 class="card-title" style="margin-bottom:12px;">۳. حساب مدیر کل، رمز یکبار مصرف (OTP) و ربات‌های اجتماعی</h3>
                <div class="form-grid">
                    <div class="field">
                        <label>نام کاربری مدیر کل</label>
                        <input name="admin_username" value="<?= e($_POST['admin_username'] ?? 'admin') ?>" class="ltr" required>
                    </div>
                    <div class="field">
                        <label>رمز عبور مدیر کل (حداقل ۸ کاراکتر)</label>
                        <input type="password" name="admin_password" required minlength="8" class="ltr">
                    </div>
                    <div class="field">
                        <label>شناسه چت بله مدیر (برای دریافت کد OTP)</label>
                        <input name="admin_bale_id" value="<?= e($_POST['admin_bale_id'] ?? '') ?>" placeholder="مثال: 194827162" class="ltr">
                    </div>
                    <div class="field">
                        <label>شناسه چت تلگرام مدیر (اختیاری)</label>
                        <input name="admin_telegram_id" value="<?= e($_POST['admin_telegram_id'] ?? '') ?>" class="ltr">
                    </div>
                    <div class="field">
                        <label>توکن ربات بله (اختیاری — قابل تنظیم از پنل)</label>
                        <input name="bale_bot_token" value="<?= e($_POST['bale_bot_token'] ?? '') ?>" class="ltr">
                    </div>
                    <div class="field">
                        <label>توکن ربات تلگرام (اختیاری — قابل تنظیم از پنل)</label>
                        <input name="telegram_bot_token" value="<?= e($_POST['telegram_bot_token'] ?? '') ?>" class="ltr">
                    </div>
                </div>

                <div style="margin-top:18px; display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-size: 15px;">
                        🚀 نصب خودکار، ساخت خزانه و راه‌اندازی شبکه
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
