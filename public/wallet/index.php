<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Agents;
use App\Auth;
use App\Crypto\QrSvg;
use App\Crypto\Totp;
use App\Csrf;
use App\Db;
use App\Invoices;
use App\IpGuard;
use App\Loans;
use App\Otp;
use App\RateLimiter;
use App\Settings;
use App\TokenEngine;
use App\Users;
use App\Web\View;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$r = (string) ($_GET['r'] ?? 'home');

if ($r === 'logout') {
    Auth::logoutUser();
    redirect_to(base_url('wallet/?r=login'));
}

// Public routes: register, login, otp
if (in_array($r, ['login', 'register', 'otp'], true)) {
    if ($r === 'register' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        if (!RateLimiter::allow('reg:' . IpGuard::clientIp(), 10, 600)) {
            flash_set('error', 'تعداد ثبت‌نام بیش از حد مجاز است.');
            redirect_to(base_url('wallet/?r=register'));
        }
        try {
            $uname = strtolower(trim((string) ($_POST['username'] ?? '')));
            if (!preg_match('/^[a-z0-9_]{3,32}$/', $uname)) {
                throw new \InvalidArgumentException('نام کاربری باید بین ۳ تا ۳۲ کاراکتر انگلیسی/عدد باشد.');
            }
            $pass = (string) ($_POST['password'] ?? '');
            if (strlen($pass) < 6) {
                throw new \InvalidArgumentException('رمز عبور باید حداقل ۶ کاراکتر باشد.');
            }
            $parentAgentId = null;
            $agCode = trim((string) ($_POST['agent_code'] ?? ''));
            if ($agCode !== '') {
                $ag = Agents::findByCode($agCode);
                if (!$ag) {
                    throw new \InvalidArgumentException('کد معرف نماینده معتبر نیست.');
                }
                $parentAgentId = (int) $ag['user_id'];
            }
            $u = Users::create([
                'username'        => $uname,
                'full_name'       => (string) ($_POST['full_name'] ?? $uname),
                'phone'           => (string) ($_POST['phone'] ?? ''),
                'password'        => $pass,
                'parent_agent_id' => $parentAgentId,
            ]);
            Auth::loginUserSession($u);
            flash_set('success', 'کیف پول اختصاصی شما ساخته شد! کد کیف پول: ' . $u['wallet_code']);
            redirect_to(base_url('wallet/'));
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            redirect_to(base_url('wallet/?r=register'));
        }
    }

    if ($r === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        $u = Users::resolveRecipient((string) ($_POST['identifier'] ?? ''));
        if (!$u || empty($u['password_hash']) || !password_verify((string) ($_POST['password'] ?? ''), (string) $u['password_hash'])) {
            flash_set('error', 'مشخصات ورود صحیح نیست.');
            redirect_to(base_url('wallet/?r=login'));
        }
        if ((int) $u['is_frozen'] === 1) {
            flash_set('error', 'این کیف پول مسدود شده است.');
            redirect_to(base_url('wallet/?r=login'));
        }
        if (Settings::bool('user_web_otp_required', false) || !empty($u['totp_enabled'])) {
            $ch = Otp::issueUserChallenge($u, 'user_login');
            $_SESSION['pending_user_otp'] = $ch;
            redirect_to(base_url('wallet/?r=otp'));
        }
        Auth::loginUserSession($u);
        redirect_to(base_url('wallet/'));
    }

    if ($r === 'otp' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        $pending = $_SESSION['pending_user_otp'] ?? null;
        $verified = is_array($pending) ? Otp::verifyUserChallenge((string) $pending['selector'], (string) ($_POST['code'] ?? '')) : null;
        if (!$verified) {
            flash_set('error', 'کد یکبار مصرف نامعتبر یا منقضی است.');
            redirect_to(base_url('wallet/?r=otp'));
        }
        Auth::loginUserSession($verified);
        redirect_to(base_url('wallet/'));
    }

    ob_start();
    if ($r === 'register'): ?>
        <h2 style="margin-top:0;">✨ افتتاح کیف پول جدید</h2>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field"><label>نام و نام خانوادگی</label><input name="full_name" required></div>
            <div class="field"><label>نام کاربری انگلیسی (یکتا)</label><input name="username" class="ltr" placeholder="ali_reza" required></div>
            <div class="field"><label>شماره همراه (اختیاری)</label><input name="phone" class="ltr" placeholder="0912..."></div>
            <div class="field"><label>رمز عبور</label><input type="password" name="password" class="ltr" required minlength="6"></div>
            <div class="field"><label>کد نماینده معرف (اختیاری — برای دریافت تسهیلات وام)</label><input name="agent_code" class="ltr" value="<?= e($_GET['ref'] ?? '') ?>" placeholder="AG-..."></div>
            <button class="btn btn-primary" style="width:100%;" type="submit">ساخت کیف پول آنی</button>
        </form>
        <p style="text-align:center; margin-bottom:0; margin-top:14px;">قبلاً حساب ساخته‌اید؟ <a href="?r=login">ورود به کیف پول</a></p>
    <?php elseif ($r === 'otp'):
        $pending = $_SESSION['pending_user_otp'] ?? ['channels_sent' => []];
        ?>
        <h2 style="margin-top:0;">🔐 تایید رمز یکبار مصرف</h2>
        <p style="color:#64748b;">کد امنیتی ارسال‌شده از طریق <b><?= e(implode('، ', $pending['channels_sent'] ?? [])) ?></b> را وارد کنید:</p>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field"><input name="code" class="ltr" style="text-align:center; font-size:22px; letter-spacing:6px;" maxlength="8" autofocus required></div>
            <button class="btn btn-primary" style="width:100%;" type="submit">تایید و ورود به کیف پول</button>
        </form>
    <?php else: ?>
        <h2 style="margin-top:0;">💼 ورود به کیف پول تحت وب</h2>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field"><label>نام کاربری، کد کیف پول (YAR-...) یا شماره همراه</label><input name="identifier" class="ltr" required></div>
            <div class="field"><label>رمز عبور</label><input type="password" name="password" class="ltr" required></div>
            <button class="btn btn-primary" style="width:100%;" type="submit">ورود امن</button>
        </form>
        <div style="display:flex; justify-content:space-between; margin-top:14px; font-size:13px;">
            <a href="?r=register">✨ افتتاح کیف پول جدید</a>
            <a href="<?= e(base_url()) ?>">🌐 بازگشت به کاوشگر</a>
        </div>
    <?php endif;
    View::authPage('کیف پول من', (string) ob_get_clean());
    exit;
}

$user = Auth::requireUser();
$uid = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'transfer') {
            $rec = Users::resolveRecipient((string) ($_POST['to'] ?? ''));
            if (!$rec) throw new \RuntimeException('کیف پول مقصد یافت نشد.');
            $res = TokenEngine::transfer($uid, (int) $rec['id'], (string) ($_POST['amount'] ?? '0'), 'web', (string) ($_POST['memo'] ?? ''));
            flash_set('success', 'انتقال با موفقیت انجام شد! خالص واریزی: ' . fmt_token($res['quote']['receiver_net_credit']) . ' | کارمزد: ' . fmt_token($res['quote']['fee_amount']));
        } elseif ($act === 'loan_request') {
            $ln = Loans::requestLoan($uid, (string) ($_POST['amount'] ?? '0'), (int) ($_POST['installments_count'] ?? 3), 30, (string) ($_POST['purpose'] ?? ''));
            flash_set('success', 'درخواست وام با کد ' . $ln['loan_code'] . ' برای نماینده شما ارسال شد.');
        } elseif ($act === 'loan_repay') {
            $rep = Loans::repayInstallment((int) ($_POST['loan_id'] ?? 0), $uid, !empty($_POST['full']), 'web');
            flash_set('success', 'قسط وام پرداخت شد! مبلغ تسویه: ' . fmt_token($rep['paid_amount']));
        } elseif ($act === 'join_agent') {
            $ag = Agents::findByCode((string) ($_POST['agent_code'] ?? ''));
            if (!$ag) throw new \RuntimeException('کد نمایندگی معتبر نیست.');
            Agents::attachSubUser((int) $ag['user_id'], (string) $user['wallet_code']);
            flash_set('success', 'شما با موفقیت زیرمجموعه «' . $ag['title'] . '» شدید و اکنون امکان دریافت وام دارید.');
        } elseif ($act === 'enable_totp') {
            $secret = (string) ($_POST['totp_secret'] ?? '');
            if (!Totp::verify($secret, (string) ($_POST['code'] ?? ''))) {
                throw new \RuntimeException('کد ۶ رقمی TOTP صحیح نیست.');
            }
            Db::pdo()->prepare('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')
                ->execute([encrypt_secret($secret), $uid]);
            flash_set('success', 'احراز هویت دو مرحله‌ای (سازگار با اپ اندروید Wallet Yar) فعال شد!');
        } elseif ($act === 'create_invoice') {
            $inv = Invoices::create(
                $uid,
                (string) ($_POST['amount'] ?? '0'),
                (string) ($_POST['title'] ?? 'فاکتور فروش'),
                (string) ($_POST['callback_url'] ?? ''),
                (string) ($_POST['external_order_id'] ?? ''),
                60,
                (string) ($_POST['return_url'] ?? '')
            );
            flash_set('success', 'لینک درگاه پرداخت ساخته شد: ' . base_url('pay/?code=' . $inv['invoice_code']));
        } elseif ($act === 'refund_invoice') {
            Invoices::refund((string) ($_POST['invoice_code'] ?? ''), $uid, null);
            flash_set('success', 'مبلغ فاکتور به صورت کامل به کیف پول خریدار مسترد شد.');
        } elseif ($act === 'create_packet') {
            $pkt = \App\Social\RedPackets::create(
                $uid,
                (string) ($_POST['total_amount'] ?? '0'),
                (int) ($_POST['max_claims'] ?? 5),
                'web',
                '',
                (string) ($_POST['mode'] ?? 'lucky'),
                (string) ($_POST['title'] ?? 'پاکت هدیه دوستانه 🎉'),
                (string) ($_POST['passcode'] ?? ''),
                24
            );
            flash_set('success', '🧧 پاکت هدیه آماده ارسال به گروه است: ' . base_url('packet/?code=' . $pkt['packet_code']));
        } elseif ($act === 'refund_packet') {
            $ref = \App\Social\RedPackets::refundRemaining((string) ($_POST['packet_code'] ?? ''), $uid, false);
            flash_set('success', 'مانده جذب‌نشده (' . fmt_token($ref['refunded_amount']) . ') به کیف پول شما بازگشت.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('wallet/?r=' . urlencode($r)));
}

$user = Users::findById($uid) ?? $user;
$nav = [
    ['route' => 'home',     'label' => 'خلاصه کیف پول و انتقال', 'icon' => '💼', 'section' => 'کیف پول شخصی'],
    ['route' => 'packets',  'label' => 'ساخت پاکت هدیه گروهی',   'icon' => '🧧'],
    ['route' => 'loans',    'label' => 'وام‌ها و اقساط من',      'icon' => '🏦'],
    ['route' => 'connect',  'label' => 'اتصال اپ اندروید و ربات', 'icon' => '📱', 'section' => 'همگام‌سازی و درگاه'],
    ['route' => 'merchant', 'label' => 'درگاه پذیرنده فروشگاهی',  'icon' => '💳'],
];
if ($user['account_type'] === 'agent') {
    $nav[] = ['route' => 'go_agent', 'label' => 'ورود به میز کار نمایندگی', 'icon' => '🤝', 'section' => 'مدیریت نمایندگی'];
}
if ($r === 'go_agent') {
    redirect_to(base_url('agent/'));
}

ob_start();
if ($r === 'loans'):
    $loansStmt = Db::pdo()->prepare('SELECT * FROM loans WHERE borrower_user_id = ? ORDER BY id DESC');
    $loansStmt->execute([$uid]);
    $myLoans = $loansStmt->fetchAll();
    ?>
    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title" style="margin-bottom:12px;">🏦 درخواست وام توکنی از نماینده</h3>
            <?php if (empty($user['parent_agent_id'])): ?>
                <p style="color:#64748b;">شما هنوز به نمایندگی متصل نیستید. کد نماینده خود را ثبت کنید:</p>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="form_action" value="join_agent">
                    <div class="field"><label>کد نمایندگی (مثال: AG-TEHRAN)</label><input name="agent_code" class="ltr" required></div>
                    <button class="btn btn-primary" type="submit">ثبت اتصال به نماینده</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="form_action" value="loan_request">
                    <div class="field"><label>مبلغ وام درخواستی</label><input name="amount" class="ltr" placeholder="500" required></div>
                    <div class="field"><label>تعداد اقساط بازپرداخت</label><input type="number" name="installments_count" value="3" min="1" max="24" class="ltr"></div>
                    <div class="field"><label>علت درخواست وام</label><input name="purpose" placeholder="خرید تجهیزات / سرمایه در گردش"></div>
                    <button class="btn btn-primary" type="submit">ارسال درخواست وام به نماینده</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-label"><span>امتیاز اعتباری خوش‌حسابی شما</span><span class="badge badge-success">Credit Score</span></div>
            <div class="stat-value"><?= fa_digits($user['credit_score']) ?> / ۸۵۰</div>
            <div class="stat-sub">با بازپرداخت به‌موقع هر قسط، ۱۵ امتیاز به رتبه اعتباری شما افزوده می‌شود.</div>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">📋 فهرست تسهیلات و اقساط من</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>کد وام</th><th>اصل وام</th><th>کل بازپرداخت</th><th>مانده بدهی</th><th>وضعیت</th><th>عملیات تسویه</th></tr></thead>
                <tbody>
                    <?php foreach ($myLoans as $ln): ?>
                        <tr>
                            <td><code><?= e($ln['loan_code']) ?></code></td>
                            <td><?= fmt_token($ln['principal_amount']) ?></td>
                            <td><?= fmt_token($ln['total_repayable']) ?></td>
                            <td><b><?= fmt_token($ln['remaining_amount']) ?></b></td>
                            <td><span class="badge <?= $ln['status'] === 'repaid' ? 'badge-success' : 'badge-warning' ?>"><?= e($ln['status']) ?></span></td>
                            <td>
                                <?php if (in_array($ln['status'], ['active', 'overdue'], true)): ?>
                                    <form method="post" style="display:inline-flex; gap:6px;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="loan_repay">
                                        <input type="hidden" name="loan_id" value="<?= (int) $ln['id'] ?>">
                                        <button class="btn btn-primary btn-sm" type="submit">پرداخت ۱ قسط</button>
                                        <button class="btn btn-outline btn-sm" type="submit" name="full" value="1">تسویه کامل</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($r === 'connect'):
    $pairCode = Users::issueSocialLinkCode($uid);
    $totpSecret = Totp::generateSecret();
    $totpUri = Totp::uri($totpSecret, $user['wallet_code'], Settings::get('token_name_en', 'YarCoin'));
    $hubPayload = json_encode(['hub' => base_url(), 'rpc' => base_url('rpc.php'), 'pair' => $pairCode], JSON_UNESCAPED_SLASHES) ?: '';
    ?>
    <div class="grid grid-2">
        <div class="card" style="text-align:center;">
            <h3 class="card-title" style="margin-bottom:10px;">📱 همگام‌سازی با اپ اندروید (Wallet Yar) و ربات بله/تلگرام</h3>
            <div class="qr-plate"><?= QrSvg::render($hubPayload, 190) ?></div>
            <div style="margin-top:12px;">
                <div>کد اتصال ۱۰ دقیقه‌ای ربات بله / تلگرام: <code>/link <?= e($pairCode) ?></code></div>
                <div style="margin-top:6px;">آدرس EVM RPC اختصاصی: <code><?= e(base_url('rpc.php')) ?></code></div>
            </div>
        </div>
        <div class="card" style="text-align:center;">
            <h3 class="card-title" style="margin-bottom:10px;">🔐 رمز یکبار مصرف دو مرحله‌ای (TOTP Authenticator)</h3>
            <div class="qr-plate"><?= QrSvg::render($totpUri, 190) ?></div>
            <form method="post" style="margin-top:12px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="enable_totp">
                <input type="hidden" name="totp_secret" value="<?= e($totpSecret) ?>">
                <div class="field"><input name="code" class="ltr" placeholder="کد ۶ رقمی اپ Wallet Yar را وارد کنید" required></div>
                <button class="btn btn-primary" type="submit">فعال‌سازی ورود دو مرحله‌ای TOTP</button>
            </form>
        </div>
    </div>
<?php elseif ($r === 'merchant'):
    $invStmt = Db::pdo()->prepare('SELECT * FROM merchant_invoices WHERE merchant_user_id = ? ORDER BY id DESC LIMIT 20');
    $invStmt->execute([$uid]);
    $invs = $invStmt->fetchAll();
    ?>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">💳 ساخت لینک پرداخت هوشمند فروشگاهی (IPG)</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="create_invoice">
            <div class="field"><label>عنوان کالا یا خدمت</label><input name="title" placeholder="اشتراک یک‌ماهه VIP" required></div>
            <div class="field"><label>مبلغ به توکن</label><input name="amount" class="ltr" placeholder="120" required></div>
            <div class="field"><label>آدرس بازگشت پس از پرداخت (اختیاری)</label><input name="return_url" class="ltr" placeholder="https://shop.example.com/callback"></div>
            <div class="field"><label>وب‌هوک اطلاع‌رسانی آنی سرور (اختیاری)</label><input name="callback_url" class="ltr" placeholder="https://shop.example.com/api/webhook"></div>
            <div class="field" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" type="submit">صدور لینک و QR پرداخت</button></div>
        </form>
    </div>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">صورت‌حساب‌های صادرشده و استرداد وجه</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>کد فاکتور</th><th>عنوان</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($invs as $iv): ?>
                        <tr>
                            <td><code><?= e($iv['invoice_code']) ?></code></td>
                            <td><?= e($iv['title']) ?></td>
                            <td><?= fmt_token($iv['amount']) ?></td>
                            <td><span class="badge <?= $iv['status'] === 'paid' ? 'badge-success' : ($iv['status'] === 'refunded' ? 'badge-indigo' : 'badge-warning') ?>"><?= e($iv['status']) ?></span></td>
                            <td style="display:flex; gap:6px;">
                                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pay/?code=' . $iv['invoice_code'])) ?>" target="_blank">درگاه ↗</a>
                                <?php if ($iv['status'] === 'paid'): ?>
                                    <form method="post" style="display:inline;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="refund_invoice">
                                        <input type="hidden" name="invoice_code" value="<?= e($iv['invoice_code']) ?>">
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
<?php elseif ($r === 'packets'):
    $pktStmt = Db::pdo()->prepare('SELECT * FROM red_packets WHERE creator_user_id = ? ORDER BY id DESC LIMIT 20');
    $pktStmt->execute([$uid]);
    $myPkts = $pktStmt->fetchAll();
    ?>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">🧧 ساخت پاکت هدیه گروهی و لینک اشتراک‌گذاری</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="create_packet">
            <div class="field"><label>مبلغ کل پاکت هدیه</label><input name="total_amount" class="ltr" placeholder="100" required></div>
            <div class="field"><label>تعداد نفرات برنده</label><input type="number" name="max_claims" class="ltr" value="5" min="1" max="200" required></div>
            <div class="field">
                <label>مدل تقسیم هدیه</label>
                <select name="mode">
                    <option value="lucky">🎲 شانسی هیجان‌انگیز</option>
                    <option value="equal">⚖️ تقسیم مساوی</option>
                </select>
            </div>
            <div class="field"><label>پیام تبریک روی پاکت</label><input name="title" value="هدیه ویژه اعضای گروه 🎉"></div>
            <div class="field"><label>رمز مخفی پاکت (اختیاری)</label><input name="passcode" placeholder="بدون رمز = عمومی"></div>
            <div class="field" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" type="submit">🎁 قفل در امانی و دریافت لینک گروه</button></div>
        </form>
    </div>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">پاکت‌های هدیه من</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>کد پاکت</th><th>عنوان</th><th>کل / مانده</th><th>نفرات</th><th>لینک گروه / بازپس‌گیری</th></tr></thead>
                <tbody>
                    <?php foreach ($myPkts as $mp): ?>
                        <tr>
                            <td><code><?= e($mp['packet_code']) ?></code></td>
                            <td><?= e($mp['title']) ?></td>
                            <td><?= fmt_token($mp['total_amount']) ?> / <?= fmt_token($mp['remaining_amount']) ?></td>
                            <td><?= fa_digits($mp['claimed_count']) ?> از <?= fa_digits($mp['max_claims']) ?></td>
                            <td style="display:flex; gap:6px;">
                                <a class="btn btn-outline btn-sm" target="_blank" href="<?= e(base_url('packet/?code=' . $mp['packet_code'])) ?>">صفحه پاکت ↗</a>
                                <?php if ($mp['status'] === 'active' && bccomp((string) $mp['remaining_amount'], '0', 8) > 0): ?>
                                    <form method="post" style="display:inline;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="refund_packet">
                                        <input type="hidden" name="packet_code" value="<?= e($mp['packet_code']) ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">↩ بازپس‌گیری مانده</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else:
    $txStmt = Db::pdo()->prepare('SELECT * FROM ledger_entries WHERE from_user_id = ? OR to_user_id = ? ORDER BY id DESC LIMIT 12');
    $txStmt->execute([$uid, $uid]);
    $txs = $txStmt->fetchAll();
    ?>
    <div class="hero-wallet">
        <div>
            <div style="font-size:13px; color:#a7f3d0;">موجودی قابل برداشت کیف پول شما</div>
            <div style="font-size:32px; font-weight:700; margin:4px 0;"><?= fmt_token($user['balance']) ?></div>
            <div style="font-size:13.5px; color:#cbd5e1;">ارزش معادل: <b><?= fmt_irt($user['balance']) ?></b></div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <code style="background:rgba(255,255,255,0.12); color:#fff; border-color:rgba(255,255,255,0.2);"><?= e($user['wallet_code']) ?></code>
                <code style="background:rgba(255,255,255,0.12); color:#fff; border-color:rgba(255,255,255,0.2);"><?= e($user['evm_address']) ?></code>
            </div>
        </div>
        <div class="qr-plate"><?= QrSvg::render($user['evm_address'], 132) ?></div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:14px;">💸 انتقال آنی توکن به کیف پول دیگر</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="transfer">
            <div class="field">
                <label>مقصد (آدرس 0x... / کد YAR-... / نام کاربری)</label>
                <input name="to" class="ltr" placeholder="YAR-XXXXXX یا 0x..." required>
            </div>
            <div class="field">
                <label>مقدار توکن</label>
                <input name="amount" class="ltr" placeholder="50.00" required>
            </div>
            <div class="field">
                <label>یادداشت تراکنش (اختیاری)</label>
                <input name="memo" placeholder="بابت تسویه حساب">
            </div>
            <div class="field" style="display:flex; align-items:flex-end;">
                <button class="btn btn-primary" style="width:100%;" type="submit">🚀 امضا و ارسال آنی</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">📜 تاریخچه گردش‌های کیف پول</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>هش زنجیره</th><th>نوع</th><th>جهت</th><th>مقدار خالص</th><th>کارمزد</th><th>توضیحات</th><th>تاریخ</th></tr></thead>
                <tbody>
                    <?php foreach ($txs as $t): $in = ((int) ($t['to_user_id'] ?? 0) === $uid); ?>
                        <tr>
                            <td><code><?= e(substr((string) $t['tx_hash'], 0, 14)) ?>…</code></td>
                            <td><span class="badge badge-indigo"><?= e($t['tx_type']) ?></span></td>
                            <td><span class="badge <?= $in ? 'badge-success' : 'badge-danger' ?>"><?= $in ? 'واریز +' : 'برداشت -' ?></span></td>
                            <td><b><?= fmt_token($t['net_amount']) ?></b></td>
                            <td><?= fmt_token($t['fee_amount']) ?></td>
                            <td><?= e($t['memo']) ?></td>
                            <td class="ltr"><?= e($t['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif;

$topRight = '<span class="badge badge-success">' . e($user['full_name']) . '</span>'
          . '<a class="btn btn-outline btn-sm" href="?r=logout">خروج</a>';
View::layout('پرتال کیف پول کاربران', 'کیف پول دیجیتال من', $r, $nav, (string) ob_get_clean(), 'wallet/', $topRight);
