<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Auth;
use App\Crypto\QrSvg;
use App\Csrf;
use App\Social\RedPackets;
use App\Web\View;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$pkt = $code !== '' ? RedPackets::details($code) : null;
$user = Auth::user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pkt && $user) {
    Csrf::assertPost();
    try {
        $pass = (string) ($_POST['passcode'] ?? '');
        $res = RedPackets::claim((string) $pkt['packet_code'], (int) $user['id'], $pass);
        flash_set('success', '🎉 تبریک! مبلغ ' . fmt_token($res['share']) . ' به کیف پول شما واریز شد!');
        redirect_to(base_url('packet/?code=' . urlencode((string) $pkt['packet_code'])));
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to(base_url('packet/?code=' . urlencode((string) $pkt['packet_code'])));
    }
}

ob_start();
if (!$pkt): ?>
    <div style="text-align:center; margin-bottom:16px;">
        <div style="width:58px; height:58px; border-radius:18px; background:linear-gradient(135deg,#dc2626,#f59e0b); color:#fff; display:flex; align-items:center; justify-content:center; font-size:28px; margin:0 auto 10px;">🧧</div>
        <h2 style="margin:0;">دریافت پاکت هدیه دیجیتال</h2>
        <p style="color:#64748b; font-size:13px; margin:6px 0 0;">کد پاکت هدیه گروهی یا همگانی (مانند <code>PKT-...</code>) را وارد کنید:</p>
    </div>
    <form method="get">
        <div class="field">
            <input name="code" placeholder="PKT-XXXXXXXX" class="ltr" required autofocus>
        </div>
        <button class="btn btn-primary" style="width:100%; background:#dc2626;" type="submit">🎁 مشاهده و باز کردن پاکت</button>
    </form>
<?php else:
    $pct = $pkt['max_claims'] > 0 ? min(100, round(((int) $pkt['claimed_count'] / (int) $pkt['max_claims']) * 100)) : 100;
    ?>
    <div style="background:linear-gradient(135deg,#991b1b 0%,#dc2626 60%,#d97706 100%); color:#fff; border-radius:18px; padding:22px 18px; text-align:center; margin-bottom:16px; box-shadow:0 12px 28px rgba(220,38,38,.25);">
        <div style="font-size:38px; line-height:1;">🧧</div>
        <div style="font-size:12px; opacity:.9; margin-top:6px;">هدیه ارسالی از طرف <b><?= e($pkt['creator_name']) ?></b></div>
        <h2 style="margin:6px 0 8px; color:#fef08a; font-size:20px;">«<?= e($pkt['title']) ?>»</h2>
        <div style="font-size:24px; font-weight:700;"><?= fmt_token($pkt['total_amount']) ?></div>
        <div style="font-size:12px; opacity:.92; margin-top:4px;">
            نوع توزیع: <b><?= $pkt['mode'] === 'lucky' ? '🎲 شانسی هیجان‌انگیز' : '⚖️ تقسیم مساوی' ?></b>
            | وضعیت: <b><?= fa_digits($pkt['claimed_count']) ?> از <?= fa_digits($pkt['max_claims']) ?> نفر</b>
        </div>
        <div style="background:rgba(255,255,255,.22); height:8px; border-radius:99px; margin-top:12px; overflow:hidden;">
            <div style="background:#fde047; height:100%; width:<?= $pct ?>%;"></div>
        </div>
    </div>

    <?php if ($pkt['status'] === 'active' && (int) $pkt['claimed_count'] < (int) $pkt['max_claims']): ?>
        <?php if ($user): ?>
            <form method="post" style="margin-bottom:18px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="code" value="<?= e($pkt['packet_code']) ?>">
                <?php if (!empty($pkt['has_passcode'])): ?>
                    <div class="field">
                        <label>🔑 این پاکت دارای رمز مخفی اعلام‌شده در گروه است:</label>
                        <input name="passcode" placeholder="رمز پاکت هدیه را وارد کنید..." required>
                    </div>
                <?php endif; ?>
                <button class="btn btn-primary" style="width:100%; padding:13px; background:linear-gradient(135deg,#dc2626,#b91c1c); font-size:15px;" type="submit">
                    🎉 باز کردن پاکت و واریز آنی به کیف پول من!
                </button>
            </form>
        <?php else: ?>
            <a class="btn btn-primary" style="width:100%; padding:12px; background:#dc2626; margin-bottom:16px;" href="<?= e(base_url('wallet/?r=login')) ?>">
                🔐 ورود / ثبت‌نام فوری کیف پول برای دریافت این هدیه
            </a>
        <?php endif; ?>

        <div style="text-align:center; margin-bottom:14px;">
            <div class="qr-plate"><?= QrSvg::render(base_url('packet/?code=' . urlencode((string) $pkt['packet_code'])), 132) ?></div>
            <div style="font-size:11.5px; color:#64748b; margin-top:6px;">اسکن یا اشتراک‌گذاری در گروه‌های بله، تلگرام، ایتا و روبیکا</div>
        </div>
    <?php else: ?>
        <div class="alert alert-info" style="text-align:center;">
            این پاکت هدیه به پایان رسیده است (وضعیت: <b><?= e($pkt['status']) ?></b>).
        </div>
    <?php endif; ?>

    <?php if (!empty($pkt['claims'])): ?>
        <div style="border-top:1px solid #e2e8f0; padding-top:12px;">
            <b style="font-size:13.5px; display:block; margin-bottom:8px;">🏆 لیست برندگان این پاکت هدیه:</b>
            <?php foreach ($pkt['claims'] as $cl): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px dashed #f1f5f9; font-size:13px;">
                    <span>
                        <?= e($cl['full_name']) ?>
                        <?php if (!empty($cl['is_mvp'])): ?>
                            <span class="badge badge-warning">👑 خوش‌شانس‌ترین</span>
                        <?php endif; ?>
                    </span>
                    <b style="color:#059669;"><?= fmt_token($cl['amount']) ?></b>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif;

View::authPage('پاکت هدیه گروهی ' . ($pkt ? $pkt['packet_code'] : ''), (string) ob_get_clean());
