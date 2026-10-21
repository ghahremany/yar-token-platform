<?php
declare(strict_types=1);

use App\Audit;
use App\Crypto\QrSvg;
use App\Crypto\Totp;
use App\Csrf;
use App\Db;
use App\Otp;
use App\Settings;
use App\Social\MessengerClient;

$freshRecovery = $_SESSION['_fresh_recovery_codes'] ?? null;
unset($_SESSION['_fresh_recovery_codes']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'save_otp_policy') {
            Settings::setMany([
                'admin_auth_mode'       => in_array($_POST['admin_auth_mode'] ?? '', ['password_and_otp','passwordless_otp','password_only'], true)
                    ? $_POST['admin_auth_mode'] : 'password_and_otp',
                'user_web_otp_required' => !empty($_POST['user_web_otp_required']) ? '1' : '0',
                'otp_delivery_default'  => in_array($_POST['otp_delivery_default'] ?? '', ['totp_or_messenger','bale','telegram','all','file'], true)
                    ? $_POST['otp_delivery_default'] : 'totp_or_messenger',
                'otp_digits'            => max(4, min(8, (int) ($_POST['otp_digits'] ?? 6))),
                'otp_ttl_seconds'       => max(60, min(900, (int) ($_POST['otp_ttl_seconds'] ?? 180))),
                'otp_max_attempts'      => max(3, min(10, (int) ($_POST['otp_max_attempts'] ?? 5))),
            ]);
            Db::pdo()->prepare('UPDATE admins SET bale_chat_id = ?, telegram_chat_id = ? WHERE id = ?')
                ->execute([
                    trim((string) ($_POST['bale_chat_id'] ?? '')),
                    trim((string) ($_POST['telegram_chat_id'] ?? '')),
                    (int) $admin['id'],
                ]);
            Audit::record('admin', (int) $admin['id'], 'otp.policy_updated', 'سیاست رمز یکبار مصرف پنل به‌روزرسانی شد');
            flash_set('success', 'سیاست‌های ورود با رمز یکبار مصرف ذخیره شد.');
        } elseif ($act === 'rotate_totp') {
            $newSec = Totp::generateSecret();
            Db::pdo()->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')
                ->execute([encrypt_secret($newSec), (int) $admin['id']]);
            flash_set('success', 'کلید TOTP جدید ساخته شد — هم‌اکنون بارکد QR را در اپ Wallet Yar یا Google Authenticator اسکن کنید.');
        } elseif ($act === 'regen_recovery') {
            $_SESSION['_fresh_recovery_codes'] = Otp::regenerateRecoveryCodes((int) $admin['id']);
            flash_set('success', '۸ کد بازیابی اضطراری جدید صادر شد!');
        } elseif ($act === 'test_otp_send') {
            $msg = "✅ پیام تست رمز یکبار مصرف پنل مدیریت با موفقیت ارسال شد! کد نمونه: <code>" . random_int(100000, 999999) . "</code>";
            $sentAny = false;
            if (!empty($admin['bale_chat_id'])) {
                $r1 = MessengerClient::sendMessage('bale', (string) $admin['bale_chat_id'], $msg);
                $sentAny = $sentAny || !empty($r1['ok']);
            }
            if (!empty($admin['telegram_chat_id'])) {
                $r2 = MessengerClient::sendMessage('telegram', (string) $admin['telegram_chat_id'], $msg);
                $sentAny = $sentAny || !empty($r2['ok']);
            }
            flash_set($sentAny ? 'success' : 'error', $sentAny ? 'پیام تست OTP با موفقیت به پیام‌رسان شما ارسال شد!' : 'ارسال ناموفق بود؛ توکن ربات و شناسه چت خود را بررسی کنید.');
        } elseif ($act === 'add_admin') {
            $un = strtolower(trim((string) ($_POST['new_username'] ?? '')));
            $pw = (string) ($_POST['new_password'] ?? '');
            if (strlen($un) < 3 || strlen($pw) < 8) {
                throw new \InvalidArgumentException('نام کاربری حداقل ۳ و رمز عبور حداقل ۸ کاراکتر باشد.');
            }
            Db::pdo()->prepare(
                'INSERT INTO admins (username, display_name, password_hash, role, bale_chat_id, telegram_chat_id, totp_secret_enc, totp_enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $un,
                trim((string) ($_POST['display_name'] ?? 'اپراتور')),
                password_hash($pw, PASSWORD_ARGON2ID),
                in_array($_POST['role'] ?? '', ['superadmin','operator','auditor'], true) ? $_POST['role'] : 'operator',
                trim((string) ($_POST['new_bale_id'] ?? '')),
                trim((string) ($_POST['new_tg_id'] ?? '')),
                encrypt_secret(Totp::generateSecret()),
            ]);
            flash_set('success', "مدیر جدید «{$un}» با موفقیت اضافه شد.");
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('admin/?r=otp-center'));
}

// Refresh current admin record
$stAdm = Db::pdo()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
$stAdm->execute([(int) $admin['id']]);
$admin = $stAdm->fetch() ?: $admin;

$totpSecret = decrypt_secret((string) ($admin['totp_secret_enc'] ?? ''));
if ($totpSecret === '') {
    $totpSecret = Totp::generateSecret();
    Db::pdo()->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')
        ->execute([encrypt_secret($totpSecret), (int) $admin['id']]);
}
$totpUri = Totp::uri($totpSecret, (string) $admin['username'], Settings::get('token_name_en', 'YarCoin'));
$adminsList = Db::pdo()->query('SELECT id, username, display_name, role, bale_chat_id, telegram_chat_id, last_login_at, last_login_ip FROM admins ORDER BY id ASC')->fetchAll();
?>
<?php if (is_array($freshRecovery)): ?>
    <div class="alert alert-warning">
        <b>🔑 ۸ کد بازیابی اضطراری جدید (یکبارمصرف — همین حالا کپی یا چاپ کنید!):</b>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
            <?php foreach ($freshRecovery as $rc): ?>
                <code><?= e($rc) ?></code>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h3 class="card-title">🔐 سیاست‌های ورود امن و رمز یکبار مصرف (OTP Policy)</h3>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="test_otp_send">
                <button class="btn btn-indigo btn-sm" type="submit">📨 ارسال پیام تست OTP به بله/تلگرام</button>
            </form>
        </div>

        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="save_otp_policy">
            <div class="form-grid">
                <div class="field">
                    <label>حالت احراز هویت پنل مدیریت</label>
                    <select name="admin_auth_mode">
                        <option value="password_and_otp" <?= Settings::get('admin_auth_mode') === 'password_and_otp' ? 'selected' : '' ?>>دو مرحله‌ای کامل (رمز عبور + کد یکبار مصرف OTP / TOTP)</option>
                        <option value="passwordless_otp" <?= Settings::get('admin_auth_mode') === 'passwordless_otp' ? 'selected' : '' ?>>ورود مستقیم فقط با کد یکبار مصرف (Passwordless OTP)</option>
                        <option value="password_only" <?= Settings::get('admin_auth_mode') === 'password_only' ? 'selected' : '' ?>>تک مرحله‌ای (فقط رمز عبور)</option>
                    </select>
                </div>
                <div class="field">
                    <label>کانال ارسال کد یکبار مصرف</label>
                    <select name="otp_delivery_default">
                        <option value="totp_or_messenger" <?= Settings::get('otp_delivery_default') === 'totp_or_messenger' ? 'selected' : '' ?>>هوشمند همزمان (بله + تلگرام + Wallet Yar TOTP)</option>
                        <option value="bale" <?= Settings::get('otp_delivery_default') === 'bale' ? 'selected' : '' ?>>فقط پیام‌رسان بله + TOTP</option>
                        <option value="telegram" <?= Settings::get('otp_delivery_default') === 'telegram' ? 'selected' : '' ?>>فقط تلگرام + TOTP</option>
                        <option value="file" <?= Settings::get('otp_delivery_default') === 'file' ? 'selected' : '' ?>>فایل امن سرور (ویژه شبکه محلی آفلاین)</option>
                    </select>
                </div>
                <div class="field"><label>تعداد ارقام کد (۴ تا ۸ رقم)</label><input type="number" name="otp_digits" class="ltr" value="<?= e(Settings::get('otp_digits', '6')) ?>"></div>
                <div class="field"><label>مدت اعتبار کد (ثانیه)</label><input type="number" name="otp_ttl_seconds" class="ltr" value="<?= e(Settings::get('otp_ttl_seconds', '180')) ?>"></div>
                <div class="field"><label>شناسه چت بله شما (دریافت OTP)</label><input name="bale_chat_id" class="ltr" value="<?= e($admin['bale_chat_id']) ?>"></div>
                <div class="field"><label>شناسه چت تلگرام شما (دریافت OTP)</label><input name="telegram_chat_id" class="ltr" value="<?= e($admin['telegram_chat_id']) ?>"></div>
            </div>
            <div class="field">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="user_web_otp_required" value="1" <?= Settings::bool('user_web_otp_required') ? 'checked' : '' ?> style="width:auto;">
                    اجباری کردن رمز یکبار مصرف برای ورود همه کاربران وب‌کیف‌پول و پرتال نمایندگان
                </label>
            </div>
            <button class="btn btn-primary" type="submit">💾 ذخیره سیاست امنیتی OTP</button>
        </form>
    </div>

    <div class="card" style="text-align:center;">
        <h3 class="card-title" style="margin-bottom:10px;">📱 اتصال مستقیم به بخش Authenticator اپ اندروید Wallet Yar</h3>
        <div class="qr-plate"><?= QrSvg::render($totpUri, 184) ?></div>
        <div style="margin:10px 0;">
            <small style="display:block; color:#64748b;">کلید مخفی TOTP حساب شما:</small>
            <code><?= e($totpSecret) ?></code>
        </div>
        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:12px;">
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="rotate_totp">
                <button class="btn btn-outline btn-sm" type="submit">🔄 تعویض کلید TOTP</button>
            </form>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="regen_recovery">
                <button class="btn btn-accent btn-sm" type="submit">🔑 صدور ۸ کد بازیابی اضطراری جدید</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:12px;">👮 مدیریت مدیران و اپراتورها</h3>
    <form method="post" class="form-grid" style="margin-bottom:16px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="add_admin">
        <div class="field"><label>نام نمایشی</label><input name="display_name" required></div>
        <div class="field"><label>نام کاربری</label><input name="new_username" class="ltr" required></div>
        <div class="field"><label>رمز عبور (حداقل ۸ کاراکتر)</label><input type="password" name="new_password" class="ltr" required></div>
        <div class="field">
            <label>نقش دسترسی</label>
            <select name="role">
                <option value="operator">اپراتور اجرایی</option>
                <option value="auditor">بازرس مالی</option>
                <option value="superadmin">مدیر ارشد</option>
            </select>
        </div>
        <div class="field"><label>چت آیدی بله</label><input name="new_bale_id" class="ltr"></div>
        <div class="field" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" style="width:100%;" type="submit">➕ افزودن مدیر</button></div>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>نام</th><th>نام کاربری</th><th>نقش</th><th>بله / تلگرام</th><th>آخرین ورود</th></tr></thead>
            <tbody>
                <?php foreach ($adminsList as $a): ?>
                    <tr>
                        <td><?= fa_digits($a['id']) ?></td>
                        <td><b><?= e($a['display_name']) ?></b></td>
                        <td><code><?= e($a['username']) ?></code></td>
                        <td><span class="badge badge-indigo"><?= e($a['role']) ?></span></td>
                        <td><?= Otp::maskId((string) $a['bale_chat_id']) ?> / <?= Otp::maskId((string) $a['telegram_chat_id']) ?></td>
                        <td class="ltr"><?= e(($a['last_login_at'] ?: '—') . ' ' . ($a['last_login_ip'] ?: '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
