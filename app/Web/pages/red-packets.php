<?php
declare(strict_types=1);

use App\Csrf;
use App\Db;
use App\Social\RedPackets;
use App\Users;

/** @var array<string,mixed> $admin */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    $act = (string) ($_POST['form_action'] ?? '');

    try {
        if ($act === 'create_packet') {
            $src = trim((string) ($_POST['creator_identifier'] ?? ''));
            $creator = $src !== '' ? Users::resolveRecipient($src) : Users::systemAccount('treasury');
            if (!$creator) {
                throw new RuntimeException('حساب مبدا یافت نشد.');
            }
            $pkt = RedPackets::create(
                (int) $creator['id'],
                (string) ($_POST['total_amount'] ?? '0'),
                (int) ($_POST['max_claims'] ?? 5),
                'web',
                '',
                (string) ($_POST['mode'] ?? 'lucky'),
                (string) ($_POST['title'] ?? 'پاکت هدیه ویژه شبکه'),
                (string) ($_POST['passcode'] ?? ''),
                (int) ($_POST['ttl_hours'] ?? 24)
            );
            flash_set('success', '🧧 پاکت هدیه ' . $pkt['packet_code'] . ' ساخته شد و مبلغ در حساب امانی (Escrow) قفل گردید!');
        } elseif ($act === 'refund_packet') {
            $res = RedPackets::refundRemaining((string) ($_POST['packet_code'] ?? ''), null, true);
            flash_set('success', 'مبلغ جذب‌نشده (' . fmt_token($res['refunded_amount']) . ') به حساب سازنده بازگردانده شد.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('?r=red-packets');
}

$escrow = Users::systemAccount('escrow');
$packets = Db::pdo()->query(
    'SELECT rp.*, u.full_name AS creator_name, u.wallet_code AS creator_code
     FROM red_packets rp
     JOIN users u ON u.id = rp.creator_user_id
     ORDER BY rp.id DESC LIMIT 50'
)->fetchAll();
$totalDistributed = (string) (Db::pdo()->query('SELECT COALESCE(SUM(amount), 0) FROM red_packet_claims')->fetchColumn() ?: '0');
?>

<div class="grid grid-3" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-label"><span>موجودی قفل‌شده حساب امانی (Escrow)</span><span class="badge badge-warning">🔒 امانی</span></div>
        <div class="stat-value"><?= fmt_token($escrow['balance']) ?></div>
        <div class="stat-sub">پشتوانه ۱۰۰٪ پاکت‌های هدیه فعال</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>کل جوایز توزیع‌شده بین اعضا</span><span class="badge badge-success">🎁 Claimed</span></div>
        <div class="stat-value"><?= fmt_token($totalDistributed) ?></div>
        <div class="stat-sub">واریز آنی در گروه‌ها و لینک وب</div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>تعداد کل پاکت‌های ساخته شده</span><span class="badge badge-indigo">🧧</span></div>
        <div class="stat-value"><?= fa_digits(count($packets)) ?></div>
        <div class="stat-sub">پشتیبانی در بله، تلگرام و لینک مستقیم وب</div>
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title">🧧 ساخت پاکت هدیه گروهی / همگانی جدید</h3>
        <form method="post" style="margin-top:14px;">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="create_packet">
            <div class="form-grid">
                <div class="field">
                    <label>حساب تامین‌کننده هدیه (خالی = خزانه مرکزی)</label>
                    <input name="creator_identifier" class="ltr" placeholder="خالی = Treasury یا کد YAR-...">
                </div>
                <div class="field">
                    <label>مبلغ کل پاکت هدیه</label>
                    <input name="total_amount" class="ltr" placeholder="500" required>
                </div>
                <div class="field">
                    <label>تعداد نفرات برنده</label>
                    <input type="number" name="max_claims" class="ltr" value="10" min="1" max="500" required>
                </div>
                <div class="field">
                    <label>مدل تقسیم هدیه</label>
                    <select name="mode">
                        <option value="lucky">🎲 شانسی (هر نفر سهم تصادفی هیجان‌انگیز!)</option>
                        <option value="equal">⚖️ مساوی (سهم کاملاً برابر برای همه)</option>
                    </select>
                </div>
                <div class="field">
                    <label>پیام تبریک روی پاکت</label>
                    <input name="title" value="هدیه ویژه اعضای فعال گروه 🎉">
                </div>
                <div class="field">
                    <label>رمز مخفی پاکت (اختیاری — برای مسابقه در گروه)</label>
                    <input name="passcode" placeholder="بدون رمز = عمومی">
                </div>
            </div>
            <button class="btn btn-primary" style="margin-top:12px;" type="submit">🎁 قفل در حساب امانی و تولید لینک پاکت</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">💬 دستورات ساخت سریع پاکت در گروه‌های بله و تلگرام</h3>
        <p style="font-size:13px; color:#475569; line-height:1.9;">
            هر یک از اعضای گروه‌های بله یا تلگرام نیز می‌توانند مستقیماً در داخل گروه با موجودی کیف پول خود پاکت هدیه بسازند؛ ربات بلافاصله دکمه شیشه‌ای دریافت را در گروه نمایش می‌دهد:
        </p>
        <div class="alert alert-info" style="font-size:13px; line-height:1.9;">
            🔹 <b>پاکت هدیه فارسی در گروه:</b><br>
            <code>هدیه 100 5</code> ← قفل کردن ۱۰۰ توکن برای ۵ نفر اول گروه!<br>
            🔹 <b>دستور انگلیسی معادل:</b><br>
            <code class="ltr">/packet 100 5</code><br>
            🔹 <b>لینک اشتراک‌گذاری همگانی وب (مخصوص کانال‌ها، ایتا، روبیکا و اینستاگرام):</b><br>
            هر پاکت دارای صفحه اختصاصی <code>/packet/?code=PKT-...</code> با جدول زنده برندگان و تاج 👑 خوش‌شانس‌ترین برنده است!
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:14px;">📋 فهرست پاکت‌های هدیه و مدیریت بازگشت مانده امانی</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>کد پاکت</th>
                    <th>سازنده</th>
                    <th>عنوان / نوع</th>
                    <th>کل مبلغ / مانده</th>
                    <th>دریافت‌شده</th>
                    <th>وضعیت</th>
                    <th>لینک پاکت / بازپس‌گیری</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($packets as $p):
                $pktUrl = base_url('packet/?code=' . urlencode((string) $p['packet_code']));
                ?>
                <tr>
                    <td><code class="ltr"><?= e($p['packet_code']) ?></code> <?= !empty($p['passcode_hash']) ? '🔑' : '' ?></td>
                    <td><?= e($p['creator_name']) ?></td>
                    <td><?= e($p['title']) ?> <small>(<?= $p['mode'] === 'lucky' ? '🎲 شانسی' : '⚖️ مساوی' ?>)</small></td>
                    <td><b><?= fmt_token($p['total_amount']) ?></b> / <span style="color:#64748b;"><?= fmt_token($p['remaining_amount']) ?></span></td>
                    <td><?= fa_digits($p['claimed_count']) ?> از <?= fa_digits($p['max_claims']) ?></td>
                    <td><span class="badge <?= $p['status'] === 'active' ? 'badge-success' : 'badge-indigo' ?>"><?= e($p['status']) ?></span></td>
                    <td style="display:flex; gap:6px; flex-wrap:wrap;">
                        <a class="btn btn-outline btn-sm" target="_blank" href="<?= e($pktUrl) ?>">باز کردن صفحه پاکت ↗</a>
                        <?php if ($p['status'] === 'active' && bccomp((string) $p['remaining_amount'], '0', 8) > 0): ?>
                            <form method="post" style="display:inline;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="form_action" value="refund_packet">
                                <input type="hidden" name="packet_code" value="<?= e($p['packet_code']) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">↩ بازگشت مانده</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
