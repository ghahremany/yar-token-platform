<?php
declare(strict_types=1);

use App\Audit;
use App\Csrf;
use App\Db;
use App\TokenEngine;
use App\Users;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'create_user') {
            $u = Users::create([
                'full_name' => (string) ($_POST['full_name'] ?? ''),
                'username'  => (string) ($_POST['username'] ?? ''),
                'phone'     => (string) ($_POST['phone'] ?? ''),
                'password'  => (string) ($_POST['password'] ?? ''),
            ]);
            flash_set('success', "کیف پول جدید ساخته شد: {$u['wallet_code']} ({$u['evm_address']})");
        } elseif ($act === 'toggle_freeze') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $u = Users::findById($uid);
            if ($u) {
                $next = ((int) $u['is_frozen'] === 1) ? 0 : 1;
                Db::pdo()->prepare('UPDATE users SET is_frozen = ?, freeze_reason = ? WHERE id = ?')
                    ->execute([$next, $next ? 'مسدودشده توسط مدیر کل' : '', $uid]);
                Audit::record('admin', (int) $admin['id'], 'user.freeze_toggle', "وضعیت انسداد کاربر #{$uid} به {$next} تغییر یافت");
                flash_set('success', $next ? 'حساب کاربر مسدود شد.' : 'رفع مسدودی انجام شد.');
            }
        } elseif ($act === 'set_tier') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $tier = in_array($_POST['fee_tier'] ?? '', ['standard', 'vip', 'exempt'], true) ? $_POST['fee_tier'] : 'standard';
            Db::pdo()->prepare('UPDATE users SET fee_tier = ? WHERE id = ?')->execute([$tier, $uid]);
            flash_set('success', 'سطح تعرفه کارمزد کاربر به‌روزرسانی شد.');
        } elseif ($act === 'treasury_send') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $treasury = Users::systemAccount('treasury');
            $res = TokenEngine::transfer((int) $treasury['id'], $uid, (string) ($_POST['amount'] ?? '0'), 'admin', (string) ($_POST['memo'] ?? 'شارژ از خزانه مرکزی'), null, true, null, 'admin_adjust');
            flash_set('success', 'مبلغ ' . fmt_token($res['tx']['net']) . ' از خزانه مرکزی به کیف پول کاربر واریز شد.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('admin/?r=users'));
}

$search = trim((string) ($_GET['s'] ?? ''));
$pdo = Db::pdo();
if ($search !== '') {
    $like = '%' . $search . '%';
    $st = $pdo->prepare(
        "SELECT * FROM users
         WHERE wallet_code LIKE ? OR evm_address LIKE ? OR full_name LIKE ? OR username LIKE ? OR phone LIKE ?
         ORDER BY id DESC LIMIT 100"
    );
    $st->execute([$like, $like, $like, $like, $like]);
    $users = $st->fetchAll();
} else {
    $users = $pdo->query('SELECT * FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
}
?>
<div class="grid grid-2">
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">➕ افتتاح دستی کیف پول کاربر</h3>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="create_user">
            <div class="field"><label>نام و نام خانوادگی</label><input name="full_name" required></div>
            <div class="field"><label>نام کاربری</label><input name="username" class="ltr" required></div>
            <div class="field"><label>شماره موبایل</label><input name="phone" class="ltr"></div>
            <div class="field"><label>رمز عبور</label><input type="password" name="password" class="ltr" required></div>
            <div class="field" style="grid-column:1/-1;"><button class="btn btn-primary" type="submit">ساخت کیف پول با آدرس EVM اختصاصی</button></div>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">🔍 جستجو و شارژ سریع از خزانه</h3>
        <form method="get" style="display:flex; gap:8px; margin-bottom:14px;">
            <input type="hidden" name="r" value="users">
            <input name="s" value="<?= e($search) ?>" placeholder="نام، کد YAR-...، آدرس 0x... یا موبایل">
            <button class="btn btn-indigo" type="submit">جستجو</button>
        </form>
        <form method="post" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="treasury_send">
            <div class="field"><label>شناسه عددی کاربر (ID)</label><input type="number" name="user_id" class="ltr" required></div>
            <div class="field"><label>مبلغ واریز از خزانه</label><input name="amount" class="ltr" placeholder="500" required></div>
            <div class="field" style="grid-column:1/-1;"><button class="btn btn-accent" type="submit">💸 انتقال بدون کارمزد از خزانه به کاربر</button></div>
        </form>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:12px;">👥 فهرست تمام کیف پول‌ها و حساب‌های سیستمی</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>نام / نقش</th><th>شناسه و آدرس EVM</th><th>موجودی</th><th>اتصال اجتماعی</th><th>سطح کارمزد</th><th>وضعیت</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= fa_digits($u['id']) ?></td>
                        <td>
                            <b><?= e($u['full_name']) ?></b><br>
                            <span class="badge <?= in_array($u['account_type'], ['treasury','fee_vault','burn_sink','escrow'], true) ? 'badge-warning' : ($u['account_type'] === 'agent' ? 'badge-indigo' : 'badge-muted') ?>">
                                <?= e($u['account_type']) ?>
                            </span>
                        </td>
                        <td>
                            <code><?= e($u['wallet_code']) ?></code><br>
                            <small><code><?= e(short_addr((string) $u['evm_address'])) ?></code></small>
                        </td>
                        <td><b><?= fmt_token($u['balance']) ?></b></td>
                        <td style="font-size:12px;">
                            <?= $u['bale_user_id'] ? '🟢 بله ' : '' ?>
                            <?= $u['telegram_user_id'] ? '🔵 تلگرام ' : '' ?>
                            <?= $u['public_key_hex'] !== '' ? '📱 Android ECDSA' : '' ?>
                        </td>
                        <td>
                            <form method="post" style="display:inline-flex; gap:4px;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="form_action" value="set_tier">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <select name="fee_tier" onchange="this.form.submit()" style="padding:3px 6px; font-size:12px;">
                                    <?php foreach (['standard' => 'عادی', 'vip' => 'VIP', 'exempt' => 'معاف ۰٪'] as $tk => $tv): ?>
                                        <option value="<?= $tk ?>" <?= $u['fee_tier'] === $tk ? 'selected' : '' ?>><?= $tv ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <span class="badge <?= (int) $u['is_frozen'] === 1 ? 'badge-danger' : 'badge-success' ?>">
                                <?= (int) $u['is_frozen'] === 1 ? 'مسدود' : 'فعال' ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!in_array($u['account_type'], ['treasury','fee_vault','burn_sink','escrow'], true)): ?>
                                <form method="post" style="display:inline;">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="form_action" value="toggle_freeze">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn <?= (int) $u['is_frozen'] === 1 ? 'btn-primary' : 'btn-danger' ?> btn-sm" type="submit">
                                        <?= (int) $u['is_frozen'] === 1 ? 'رفع مسدودی' : 'مسدودسازی' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
