<?php
declare(strict_types=1);

use App\Agents;
use App\Csrf;
use App\TokenEngine;
use App\Users;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'create_or_promote') {
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            if ($identifier !== '') {
                $u = Users::resolveRecipient($identifier);
                if (!$u) throw new \RuntimeException('کاربری با این کد/نام کاربری یافت نشد.');
            } else {
                $u = Users::create([
                    'account_type' => 'agent',
                    'full_name'    => (string) ($_POST['full_name'] ?? 'نماینده رسمی'),
                    'username'     => (string) ($_POST['username'] ?? ''),
                    'password'     => (string) ($_POST['password'] ?? ''),
                ]);
            }

            $ag = Agents::upsertAgent((int) $u['id'], [
                'agent_code'                => $_POST['agent_code'] ?? '',
                'title'                     => $_POST['title'] ?? ('نمایندگی ' . $u['full_name']),
                'credit_pool_limit'         => $_POST['credit_pool_limit'] ?? '50000',
                'max_interest_bps'          => $_POST['max_interest_bps'] ?? 1200,
                'max_loan_per_user'         => $_POST['max_loan_per_user'] ?? '10000',
                'max_term_days'             => $_POST['max_term_days'] ?? 180,
                'commission_bps'            => $_POST['commission_bps'] ?? 2000,
                'transfer_fee_discount_bps' => $_POST['transfer_fee_discount_bps'] ?? 5000,
                'can_lend_from_wallet'      => 1,
                'can_lend_from_pool'        => 1,
                'is_active'                 => 1,
            ], (int) $admin['id']);

            // Optional direct initial wallet funding from Treasury
            $initFund = dec_norm($_POST['initial_wallet_fund'] ?? '0');
            if (bccomp($initFund, '0', 8) > 0) {
                $treasury = Users::systemAccount('treasury');
                TokenEngine::transfer((int) $treasury['id'], (int) $u['id'], $initFund, 'admin', 'شارژ اولیه کیف پول نماینده', null, true);
            }
            flash_set('success', "نماینده «{$ag['title']}» با کد {$ag['agent_code']} و اعتبار وام‌دهی " . fmt_token($ag['credit_pool_limit']) . ' ثبت شد!');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('admin/?r=agents'));
}

$agents = Agents::listAll();
?>
<div class="card">
    <h3 class="card-title" style="margin-bottom:14px;">🤝 تعریف یا ارتقای نماینده رسمی وام‌دهنده (Agent / Lender)</h3>
    <form method="post" class="form-grid">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="create_or_promote">

        <div class="field">
            <label>ارتقای کاربر فعلی (کد YAR-... یا نام کاربری — اختیاری)</label>
            <input name="identifier" class="ltr" placeholder="خالی بگذارید تا حساب جدید ساخته شود">
        </div>
        <div class="field"><label>نام کامل (در صورت ساخت جدید)</label><input name="full_name" placeholder="نمایندگی استان تهران"></div>
        <div class="field"><label>نام کاربری ورود به پرتال نماینده</label><input name="username" class="ltr" placeholder="agent_tehran"></div>
        <div class="field"><label>رمز عبور اولیه نماینده</label><input type="password" name="password" class="ltr" placeholder="حداقل ۶ کاراکتر"></div>

        <div class="field"><label>عنوان رسمی نمایندگی</label><input name="title" placeholder="شعبه مرکزی وام و اعتبار" required></div>
        <div class="field"><label>کد اختصاصی جذب زیرمجموعه</label><input name="agent_code" class="ltr" placeholder="AG-TEHRAN"></div>
        <div class="field"><label>سقف استخر اعتبار وام‌دهی (Credit Pool)</label><input name="credit_pool_limit" class="ltr" value="50000" required></div>
        <div class="field"><label>شارژ اولیه کیف پول شخصی نماینده از خزانه</label><input name="initial_wallet_fund" class="ltr" value="5000"></div>

        <div class="field"><label>حداکثر سود مجاز وام (BPS: 1200 = 12%)</label><input name="max_interest_bps" class="ltr" value="1200"></div>
        <div class="field"><label>سقف هر وام برای هر زیرمجموعه</label><input name="max_loan_per_user" class="ltr" value="10000"></div>
        <div class="field"><label>پورسانت نماینده از کارمزد زیرمجموعه‌ها (2000 = 20%)</label><input name="commission_bps" class="ltr" value="2000"></div>
        <div class="field"><label>تخفیف کارمزد انتقال مستقیم نماینده (5000 = 50%)</label><input name="transfer_fee_discount_bps" class="ltr" value="5000"></div>

        <div class="field" style="grid-column:1/-1;">
            <button class="btn btn-primary" type="submit">✅ ثبت / بروزرسانی مجوز نمایندگی و تخصیص خط اعتباری</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h3 class="card-title">📋 فهرست نمایندگان فعال و وضعیت خطوط اعتباری</h3>
        <a class="btn btn-outline btn-sm" href="<?= e(base_url('agent/')) ?>" target="_blank">مشاهده پرتال اختصاصی نمایندگان ↗</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>عنوان نمایندگی</th><th>کد دعوت</th><th>کیف پول شخصی</th>
                    <th>استخر وام‌دهی (مصرف‌شده / سقف)</th><th>زیرمجموعه‌ها</th><th>وام‌های فعال</th><th>پورسانت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $ag): ?>
                    <tr>
                        <td><b><?= e($ag['title']) ?></b><br><small><?= e($ag['full_name']) ?></small></td>
                        <td><code><?= e($ag['agent_code']) ?></code></td>
                        <td><b><?= fmt_token($ag['balance']) ?></b><br><small><code><?= e($ag['wallet_code']) ?></code></small></td>
                        <td>
                            <b><?= fmt_token($ag['credit_pool_used']) ?></b> از <?= fmt_token($ag['credit_pool_limit']) ?>
                        </td>
                        <td><?= fa_digits($ag['subusers_count']) ?> نفر</td>
                        <td><?= fa_digits($ag['active_loans_count']) ?> پرونده</td>
                        <td><?= fa_digits(((int) $ag['commission_bps']) / 100) ?>٪</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
