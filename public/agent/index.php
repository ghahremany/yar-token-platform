<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Agents;
use App\Auth;
use App\Crypto\QrSvg;
use App\Csrf;
use App\Db;
use App\Loans;
use App\Otp;
use App\Settings;
use App\TokenEngine;
use App\Users;
use App\Web\View;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$r = (string) ($_GET['r'] ?? 'dashboard');

if ($r === 'logout') {
    Auth::logoutUser();
    redirect_to(base_url('agent/?r=login'));
}

if (in_array($r, ['login', 'otp'], true)) {
    if ($r === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        $u = Users::resolveRecipient((string) ($_POST['identifier'] ?? ''));
        if (!$u || empty($u['password_hash']) || !password_verify((string) ($_POST['password'] ?? ''), (string) $u['password_hash'])) {
            flash_set('error', 'نام کاربری یا رمز عبور نادرست است.');
            redirect_to(base_url('agent/?r=login'));
        }
        $ag = Agents::getProfile((int) $u['id']);
        if (!$ag || (int) $ag['is_active'] !== 1) {
            flash_set('error', 'این حساب دارای مجوز فعال نمایندگی و وام‌دهی نیست.');
            redirect_to(base_url('agent/?r=login'));
        }
        if (Settings::bool('user_web_otp_required', false) || !empty($u['totp_enabled'])) {
            $_SESSION['pending_user_otp'] = Otp::issueUserChallenge($u, 'user_login');
            redirect_to(base_url('agent/?r=otp'));
        }
        Auth::loginUserSession($u);
        redirect_to(base_url('agent/'));
    }

    if ($r === 'otp' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::assertPost();
        $pending = $_SESSION['pending_user_otp'] ?? null;
        $u = is_array($pending) ? Otp::verifyUserChallenge((string) $pending['selector'], (string) ($_POST['code'] ?? '')) : null;
        if (!$u) {
            flash_set('error', 'کد یکبار مصرف نامعتبر است.');
            redirect_to(base_url('agent/?r=otp'));
        }
        Auth::loginUserSession($u);
        redirect_to(base_url('agent/'));
    }

    ob_start();
    if ($r === 'otp'): ?>
        <h2 style="margin-top:0;">🔐 تایید رمز یکبار مصرف نماینده</h2>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field"><input name="code" class="ltr" style="text-align:center; font-size:22px; letter-spacing:6px;" required autofocus></div>
            <button class="btn btn-primary" style="width:100%;" type="submit">ورود به پرتال نمایندگی</button>
        </form>
    <?php else: ?>
        <h2 style="margin-top:0;">🤝 پرتال نمایندگان و وام‌دهندگان رسمی</h2>
        <p style="color:#64748b;">مدیریت زیرمجموعه‌ها، اعطای وام اقساطی و انتقال مستقیم به کیف پول کاربران</p>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="field"><label>نام کاربری یا کد کیف پول نماینده</label><input name="identifier" class="ltr" required></div>
            <div class="field"><label>رمز عبور</label><input type="password" name="password" class="ltr" required></div>
            <button class="btn btn-primary" style="width:100%;" type="submit">ورود به میز کار نماینده</button>
        </form>
    <?php endif;
    View::authPage('پرتال نمایندگان وام‌دهنده', (string) ob_get_clean());
    exit;
}

['user' => $agentUser, 'agent' => $agent] = Auth::requireAgent();
$agentId = (int) $agentUser['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'attach_sub') {
            $sub = Agents::attachSubUser($agentId, (string) ($_POST['identifier'] ?? ''));
            flash_set('success', "کاربر «{$sub['full_name']}» ({$sub['wallet_code']}) با موفقیت به زیرمجموعه‌های شما اضافه شد.");
        } elseif ($act === 'create_sub') {
            $sub = Agents::registerSubUser($agentId, [
                'full_name' => (string) ($_POST['full_name'] ?? ''),
                'username'  => (string) ($_POST['username'] ?? ''),
                'phone'     => (string) ($_POST['phone'] ?? ''),
                'password'  => (string) ($_POST['password'] ?? ''),
            ]);
            flash_set('success', "حساب جدید زیرمجموعه ساخته شد! کد کیف پول: {$sub['wallet_code']}");
        } elseif ($act === 'direct_transfer') {
            $sub = Users::resolveRecipient((string) ($_POST['to'] ?? ''));
            if (!$sub) throw new \RuntimeException('کیف پول مقصد یافت نشد.');
            $res = TokenEngine::transfer($agentId, (int) $sub['id'], (string) ($_POST['amount'] ?? '0'), 'agent_web', (string) ($_POST['memo'] ?? 'شارژ توسط نماینده'));
            flash_set('success', 'انتقال مستقیم با تخفیف ویژه نمایندگی انجام شد! خالص واریزی: ' . fmt_token($res['quote']['receiver_net_credit']));
        } elseif ($act === 'grant_loan') {
            $sub = Users::resolveRecipient((string) ($_POST['borrower'] ?? ''));
            if (!$sub) throw new \RuntimeException('وام‌گیرنده یافت نشد.');
            $ln = Loans::grantLoan($agentId, (int) $sub['id'], (string) ($_POST['amount'] ?? '0'), [
                'funding_source'     => $_POST['funding_source'] ?? 'credit_pool',
                'interest_bps'       => (int) ($_POST['interest_bps'] ?? 500),
                'installments_count' => (int) ($_POST['installments_count'] ?? 3),
                'interval_days'      => (int) ($_POST['interval_days'] ?? 30),
                'auto_deduct'        => !empty($_POST['auto_deduct']),
                'purpose'            => (string) ($_POST['purpose'] ?? 'تسهیلات نمایندگی'),
                'request_loan_id'    => !empty($_POST['request_loan_id']) ? (int) $_POST['request_loan_id'] : null,
            ]);
            flash_set('success', "وام {$ln['loan_code']} به مبلغ " . fmt_token($ln['principal_amount']) . ' صادر و به کیف پول کاربر واریز شد!');
        } elseif ($act === 'reject_loan') {
            Loans::rejectRequest((int) ($_POST['loan_id'] ?? 0), $agentId);
            flash_set('success', 'درخواست وام رد شد.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('agent/?r=' . urlencode($r)));
}

$metrics = Agents::dashboardMetrics($agentId);
$subs = Agents::listSubUsers($agentId);
$loansStmt = Db::pdo()->prepare(
    'SELECT l.*, u.full_name AS borrower_name, u.wallet_code AS borrower_code, u.credit_score
     FROM loans l JOIN users u ON u.id = l.borrower_user_id
     WHERE l.agent_user_id = ? ORDER BY l.id DESC LIMIT 50'
);
$loansStmt->execute([$agentId]);
$agentLoans = $loansStmt->fetchAll();

$nav = [
    ['route' => 'dashboard', 'label' => 'میز کار و اعطای وام',     'icon' => '🏦', 'section' => 'عملیات نمایندگی'],
    ['route' => 'subusers',  'label' => 'مدیریت زیرمجموعه‌ها',     'icon' => '👥'],
    ['route' => 'transfers', 'label' => 'انتقال به کیف پول اعضا',  'icon' => '💸'],
];

ob_start();
$inviteUrl = base_url('wallet/?r=register&ref=' . urlencode((string) $agent['agent_code']));
?>
<div class="grid grid-4" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-label"><span>اعتبار آزاد وام‌دهی</span><span class="badge badge-success">Credit Pool</span></div>
        <div class="stat-value"><?= fmt_token($metrics['available_pool']) ?></div>
        <div class="stat-sub">سقف کل استخر: <?= fmt_token($agent['credit_pool_limit']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>موجودی کیف پول نماینده</span><span class="badge badge-indigo">Personal</span></div>
        <div class="stat-value"><?= fmt_token($metrics['agent']['balance']) ?></div>
        <div class="stat-sub">پورسانت دریافتی: <?= fmt_token($metrics['commissions_earned']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>مطالبات وام‌های فعال</span><span class="badge badge-warning">Receivables</span></div>
        <div class="stat-value"><?= fmt_token($metrics['active_receivables']) ?></div>
        <div class="stat-sub">درخواست در انتظار تایید: <?= fa_digits($metrics['pending_requests']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>زیرمجموعه‌های متصل</span><span class="badge badge-indigo">کد: <?= e($agent['agent_code']) ?></span></div>
        <div class="stat-value"><?= fa_digits($metrics['subusers_count']) ?> نفر</div>
        <div class="stat-sub">پورسانت از کارمزد اعضا: <?= fa_digits(((int) $agent['commission_bps']) / 100) ?>٪</div>
    </div>
</div>

<?php if ($r === 'subusers'): ?>
    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title" style="margin-bottom:12px;">➕ افزودن یا ساخت زیرمجموعه جدید</h3>
            <form method="post" style="margin-bottom:18px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="attach_sub">
                <div class="field">
                    <label>افزودن کاربر فعلی با کد کیف پول (YAR-...) یا آدرس (0x...)</label>
                    <div style="display:flex; gap:8px;">
                        <input name="identifier" class="ltr" placeholder="YAR-XXXXXX" required>
                        <button class="btn btn-primary" type="submit">اتصال</button>
                    </div>
                </div>
            </form>
            <hr style="border:none; border-top:1px solid #e2e8f0; margin:14px 0;">
            <form method="post" class="form-grid">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="create_sub">
                <div class="field"><label>نام کامل کاربر جدید</label><input name="full_name" required></div>
                <div class="field"><label>نام کاربری انگلیسی</label><input name="username" class="ltr" required></div>
                <div class="field"><label>رمز عبور اولیه</label><input type="password" name="password" class="ltr" required></div>
                <div class="field" style="display:flex; align-items:flex-end;"><button class="btn btn-indigo" style="width:100%;" type="submit">افتتاح کیف پول زیرمجموعه</button></div>
            </form>
        </div>
        <div class="card" style="text-align:center;">
            <h3 class="card-title" style="margin-bottom:10px;">🔗 QR لینک جذب خودکار زیرمجموعه</h3>
            <div class="qr-plate"><?= QrSvg::render($inviteUrl, 165) ?></div>
            <div style="margin-top:10px;"><code style="font-size:11.5px;"><?= e($inviteUrl) ?></code></div>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">👥 فهرست زیرمجموعه‌های من</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>نام کاربر</th><th>کد کیف پول</th><th>آدرس EVM</th><th>موجودی فعلی</th><th>بدهی وام فعال</th><th>امتیاز اعتباری</th></tr></thead>
                <tbody>
                    <?php foreach ($subs as $s): ?>
                        <tr>
                            <td><b><?= e($s['full_name']) ?></b></td>
                            <td><code><?= e($s['wallet_code']) ?></code></td>
                            <td><code><?= e(short_addr((string) $s['evm_address'])) ?></code></td>
                            <td><?= fmt_token($s['balance']) ?></td>
                            <td><?= fmt_token($s['active_debt']) ?></td>
                            <td><span class="badge badge-success"><?= fa_digits($s['credit_score']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($r === 'transfers'): ?>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">💸 انتقال آنی از کیف پول نماینده به کیف پول کاربر (با <?= fa_digits(((int) $agent['transfer_fee_discount_bps']) / 100) ?>٪ تخفیف کارمزد)</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="direct_transfer">
            <div class="field">
                <label>انتخاب زیرمجموعه یا ورود کد کیف پول هر کاربر</label>
                <input name="to" list="sub_list" class="ltr" placeholder="YAR-XXXXXX یا 0x..." required>
                <datalist id="sub_list">
                    <?php foreach ($subs as $s): ?>
                        <option value="<?= e($s['wallet_code']) ?>"><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="field"><label>مبلغ انتقال</label><input name="amount" class="ltr" placeholder="250" required></div>
            <div class="field"><label>شرح / بابت</label><input name="memo" placeholder="شارژ کیف پول زیرمجموعه"></div>
            <div class="field" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" style="width:100%;" type="submit">ارسال فوری توکن</button></div>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:14px;">🏦 اعطای وام جدید به زیرمجموعه (اقساطی با کسر خودکار سررسید)</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="grant_loan">
            <div class="field">
                <label>زیرمجموعه وام‌گیرنده</label>
                <select name="borrower" required>
                    <option value="">-- انتخاب زیرمجموعه --</option>
                    <?php foreach ($subs as $s): ?>
                        <option value="<?= e($s['wallet_code']) ?>"><?= e($s['full_name']) ?> (<?= e($s['wallet_code']) ?> — امتیاز: <?= fa_digits($s['credit_score']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>منبع تامین وجه وام</label>
                <select name="funding_source">
                    <option value="credit_pool">استخر اعتباری نمایندگی (آزاد: <?= fmt_token($metrics['available_pool']) ?>)</option>
                    <option value="agent_wallet">کیف پول شخصی نماینده (موجودی: <?= fmt_token($metrics['agent']['balance']) ?>)</option>
                </select>
            </div>
            <div class="field"><label>مبلغ اصل وام</label><input name="amount" class="ltr" placeholder="1000" required></div>
            <div class="field">
                <label>کارمزد / سود وام (BPS — حداکثر <?= fa_digits($agent['max_interest_bps']) ?>)</label>
                <input type="number" name="interest_bps" value="<?= min(500, (int) $agent['max_interest_bps']) ?>" max="<?= (int) $agent['max_interest_bps'] ?>" class="ltr">
            </div>
            <div class="field"><label>تعداد اقساط</label><input type="number" name="installments_count" value="3" min="1" max="36" class="ltr"></div>
            <div class="field"><label>فاصله هر قسط (روز)</label><input type="number" name="interval_days" value="30" min="1" max="90" class="ltr"></div>
            <div class="field">
                <label>بابت / شرح تسهیلات</label>
                <input name="purpose" value="تسهیلات اعتباری زیرمجموعه">
            </div>
            <div class="field" style="display:flex; align-items:flex-end; gap:10px;">
                <label style="display:flex; align-items:center; gap:6px; margin:0; white-space:nowrap;">
                    <input type="checkbox" name="auto_deduct" value="1" checked style="width:auto;"> کسر خودکار سررسید
                </label>
                <button class="btn btn-primary" style="flex:1;" type="submit">✅ صدور و واریز آنی وام</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">📋 پرونده‌های وام و درخواست‌های در انتظار تایید</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>شماره وام</th><th>وام‌گیرنده</th><th>اصل وام</th><th>کل بازپرداخت</th><th>پرداخت‌شده</th><th>مانده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($agentLoans as $ln): ?>
                        <tr>
                            <td><code><?= e($ln['loan_code']) ?></code></td>
                            <td><b><?= e($ln['borrower_name']) ?></b><br><small><code><?= e($ln['borrower_code']) ?></code></small></td>
                            <td><?= fmt_token($ln['principal_amount']) ?></td>
                            <td><?= fmt_token($ln['total_repayable']) ?></td>
                            <td><?= fmt_token($ln['repaid_amount']) ?></td>
                            <td><b><?= fmt_token($ln['remaining_amount']) ?></b></td>
                            <td>
                                <span class="badge <?= $ln['status'] === 'repaid' ? 'badge-success' : ($ln['status'] === 'requested' ? 'badge-indigo' : 'badge-warning') ?>">
                                    <?= e($ln['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ln['status'] === 'requested'): ?>
                                    <form method="post" style="display:inline-flex; gap:5px;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="grant_loan">
                                        <input type="hidden" name="request_loan_id" value="<?= (int) $ln['id'] ?>">
                                        <input type="hidden" name="borrower" value="<?= e($ln['borrower_code']) ?>">
                                        <input type="hidden" name="amount" value="<?= e($ln['principal_amount']) ?>">
                                        <input type="hidden" name="interest_bps" value="<?= (int) $ln['interest_bps'] ?>">
                                        <input type="hidden" name="installments_count" value="<?= (int) $ln['installments_count'] ?>">
                                        <input type="hidden" name="interval_days" value="<?= (int) $ln['interval_days'] ?>">
                                        <input type="hidden" name="auto_deduct" value="1">
                                        <button class="btn btn-primary btn-sm" type="submit">تایید و واریز</button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="form_action" value="reject_loan">
                                        <input type="hidden" name="loan_id" value="<?= (int) $ln['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">رد</button>
                                    </form>
                                <?php else: ?>
                                    <small class="ltr"><?= e($ln['next_due_at'] ?: 'تسویه') ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif;

$topRight = '<span class="badge badge-indigo">' . e($agent['title']) . '</span>'
          . '<a class="btn btn-outline btn-sm" href="?r=logout">خروج</a>';
View::layout('پرتال نمایندگان وام‌دهنده', 'میز کار نماینده رسمی', $r, $nav, (string) ob_get_clean(), 'agent/', $topRight);
