<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Db;
use App\IpGuard;
use App\Otp;
use App\RateLimiter;
use App\Settings;
use App\Web\View;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$r = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['r'] ?? 'dashboard'))) ?: 'dashboard';

if ($r === 'logout') {
    Auth::logoutAdmin();
    redirect_to(base_url('admin/?r=login'));
}

// Public Admin Auth Routes: login & otp
if ($r === 'login' || $r === 'otp') {
    if ($r === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        if (!RateLimiter::allow('admin_login:' . IpGuard::clientIp(), 10, 300)) {
            flash_set('error', 'تعداد تلاش‌های ورود بیش از حد مجاز است. ۵ دقیقه صبر کنید.');
            redirect_to(base_url('admin/?r=login'));
        }
        $mode = Settings::get('admin_auth_mode', 'password_and_otp');
        $uname = strtolower(trim((string) ($_POST['username'] ?? '')));
        $stmt = Db::pdo()->prepare('SELECT * FROM admins WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$uname]);
        $adm = $stmt->fetch();

        if ($mode === 'passwordless_otp') {
            if ($adm) {
                $_SESSION['pending_admin_otp'] = Otp::issueAdminChallenge($adm);
            }
            redirect_to(base_url('admin/?r=otp'));
        }

        if (!$adm || !password_verify((string) ($_POST['password'] ?? ''), (string) $adm['password_hash'])) {
            flash_set('error', 'نام کاربری یا رمز عبور مدیر اشتباه است.');
            redirect_to(base_url('admin/?r=login'));
        }

        if ($mode === 'password_only') {
            Auth::loginAdminSession($adm);
            redirect_to(base_url('admin/'));
        }

        $_SESSION['pending_admin_otp'] = Otp::issueAdminChallenge($adm);
        redirect_to(base_url('admin/?r=otp'));
    }

    if ($r === 'otp' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        $pending = $_SESSION['pending_admin_otp'] ?? null;
        $adm = is_array($pending)
            ? Otp::verifyAdminChallenge((string) ($pending['selector'] ?? ''), (string) ($_POST['code'] ?? ''))
            : null;
        if (!$adm) {
            flash_set('error', 'کد یکبار مصرف، کد TOTP یا کد بازیابی واردشده معتبر نیست.');
            redirect_to(base_url('admin/?r=otp'));
        }
        Auth::loginAdminSession($adm);
        redirect_to(base_url('admin/'));
    }

    $mode = Settings::get('admin_auth_mode', 'password_and_otp');
    ob_start();
    if ($r === 'otp'):
        $pending = $_SESSION['pending_admin_otp'] ?? ['channels_sent' => []];
        ?>
        <div style="text-align:center; margin-bottom:18px;">
            <div class="brand-coin" style="margin:0 auto 10px; width:50px; height:50px;">🔐</div>
            <h2 style="margin:0;">تایید دو مرحله‌ای مدیر کل</h2>
            <p style="color:#64748b; font-size:13px; margin:8px 0 0;">
                کانال‌های فعال تایید: <b><?= e(implode(' | ', $pending['channels_sent'] ?? ['TOTP / بله / تلگرام'])) ?></b>
            </p>
        </div>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field">
                <label>کد ۶ رقمی یکبار مصرف، کد اپ Wallet Yar یا کد بازیابی اضطراری</label>
                <input name="code" class="ltr" style="text-align:center; font-size:21px; letter-spacing:4px; font-weight:700;" placeholder="123456 یا XXXX-XXXX" required autofocus>
            </div>
            <button class="btn btn-primary" style="width:100%; padding:11px;" type="submit">تایید هویت و ورود به پنل فرماندهی</button>
        </form>
        <p style="text-align:center; margin-bottom:0; margin-top:14px;"><a href="?r=login">بازگشت به مرحله قبل</a></p>
    <?php else: ?>
        <div style="text-align:center; margin-bottom:20px;">
            <div class="brand-coin" style="margin:0 auto 10px; width:50px; height:50px; font-size:18px;"><?= e(Settings::get('token_symbol', 'YAR')) ?></div>
            <h2 style="margin:0;">پنل فرماندهی مدیر کل</h2>
            <small style="color:#64748b;">مدیریت عرضه توکن، کارمزد، نمایندگان وام‌دهنده، شبکه‌های اجتماعی و پل اندروید</small>
        </div>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field">
                <label>نام کاربری مدیر</label>
                <input name="username" class="ltr" required autofocus>
            </div>
            <?php if ($mode !== 'passwordless_otp'): ?>
                <div class="field">
                    <label>رمز عبور</label>
                    <input type="password" name="password" class="ltr" required>
                </div>
            <?php endif; ?>
            <button class="btn btn-primary" style="width:100%; padding:11px;" type="submit">
                <?= $mode === 'passwordless_otp' ? 'ارسال کد یکبار مصرف ورود' : 'ادامه ورود امن' ?>
            </button>
        </form>
    <?php endif;
    View::authPage('ورود مدیر کل', (string) ob_get_clean());
    exit;
}

$admin = Auth::requireAdmin();

$allowedPages = [
    'dashboard'   => ['داشبورد تحلیلی و سلامت زنجیره',   '📊', 'فرماندهی شبکه'],
    'token'       => ['ضرب توکن، توکن‌سوزی و کارمزد',   '💎', null],
    'users'       => ['کیف پول‌ها و دارندگان توکن',     '👥', null],
    'ledger'      => ['دفتر کل زنجیره‌ای و بازرسی هش',   '⛓', null],
    'gateway'     => ['درگاه پرداخت توکنی (IPG)',       '💳', 'درگاه پرداخت و هدایا'],
    'red-packets' => ['پاکت‌های هدیه گروهی و همگانی',    '🧧', null],
    'agents'      => ['نمایندگان رسمی و سقف اعتبار',    '🤝', 'وام‌دهی و نمایندگان'],
    'loans'       => ['نظارت تسهیلات و اقساط وام',       '🏦', null],
    'social'      => ['اتصال بله، تلگرام و گروه‌ها',     '💬', 'اکوسیستم و اپلیکیشن'],
    'android'     => ['اتصال اپ اندروید Wallet Yar',     '📱', null],
    'audit'       => ['ابزار ممیزی یک‌کلیکی (PoR)',      '🛡️', 'امنیت و ممیزی'],
    'otp-center'  => ['مرکز رمز یکبار مصرف و مدیران',   '🔐', null],
    'tools'       => ['پشتیبان‌گیری SQL، کرون و گزارش',  '🛠️', null],
];

if (!isset($allowedPages[$r])) {
    $r = 'dashboard';
}

$nav = [];
foreach ($allowedPages as $route => [$label, $icon, $sec]) {
    $item = ['route' => $route, 'label' => $label, 'icon' => $icon];
    if ($sec !== null) $item['section'] = $sec;
    $nav[] = $item;
}

$pageFile = root_path("app/Web/pages/{$r}.php");
ob_start();
require $pageFile;
$bodyHtml = (string) ob_get_clean();

$topRight = '<span class="badge badge-indigo">🛡️ ' . e($admin['display_name']) . '</span>'
          . '<a class="btn btn-outline btn-sm" href="?r=logout">خروج امن</a>';

View::layout('پنل فرماندهی مدیر کل', $allowedPages[$r][0], $r, $nav, $bodyHtml, 'admin/', $topRight);
