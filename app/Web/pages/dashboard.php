<?php
declare(strict_types=1);

use App\Db;
use App\Ledger;
use App\TokenEngine;

$sup = TokenEngine::supplyOverview();
$chain = Ledger::verifyChainIntegrity();
$pdo = Db::pdo();

$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_type IN ('user','agent','merchant')")->fetchColumn();
$agentsCount = (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE is_active = 1")->fetchColumn();
$activeLoansSum = (string) ($pdo->query("SELECT COALESCE(SUM(remaining_amount), 0) FROM loans WHERE status IN ('active','overdue')")->fetchColumn() ?: '0');
$groupsCount = (int) $pdo->query("SELECT COUNT(*) FROM social_groups WHERE is_active = 1")->fetchColumn();

// 14-day daily volume & fees for inline SVG chart
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - ($i * 86400));
    $days[$d] = ['vol' => 0.0, 'fees' => 0.0];
}
$chartRows = $pdo->query(
    "SELECT DATE(created_at) AS d,
            COALESCE(SUM(CASE WHEN tx_type <> 'mint' THEN gross_amount ELSE 0 END), 0) AS vol,
            COALESCE(SUM(fee_amount), 0) AS fees
     FROM ledger_entries
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(created_at)"
)->fetchAll();
foreach ($chartRows as $cr) {
    if (isset($days[$cr['d']])) {
        $days[$cr['d']] = ['vol' => (float) $cr['vol'], 'fees' => (float) $cr['fees']];
    }
}
$maxVol = max(1.0, max(array_column($days, 'vol')));

$topHolders = $pdo->query(
    "SELECT wallet_code, full_name, account_type, balance, credit_score
     FROM users
     WHERE account_type IN ('user','agent','merchant')
     ORDER BY balance DESC LIMIT 6"
)->fetchAll();

$recentTxs = $pdo->query(
    "SELECT l.*, su.wallet_code AS from_code, ru.wallet_code AS to_code
     FROM ledger_entries l
     LEFT JOIN users su ON su.id = l.from_user_id
     LEFT JOIN users ru ON ru.id = l.to_user_id
     ORDER BY l.id DESC LIMIT 8"
)->fetchAll();
?>
<div class="grid grid-4" style="margin-bottom:18px;">
    <div class="stat-card">
        <div class="stat-label"><span>عرضه خالص در گردش</span><span class="badge <?= $sup['paused'] ? 'badge-danger' : 'badge-success' ?>"><?= $sup['paused'] ? 'متوقف' : 'فعال' ?></span></div>
        <div class="stat-value"><?= fmt_token($sup['net_total_supply']) ?></div>
        <div class="stat-sub">موجودی خزانه مرکزی: <?= fmt_token($sup['treasury_balance']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>درآمد کل کارمزد شبکه</span><span class="badge badge-indigo">Fee Vault</span></div>
        <div class="stat-value"><?= fmt_token($sup['total_fees_collected']) ?></div>
        <div class="stat-sub">توکن‌سوزی شده: <?= fmt_token($sup['total_burned']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>تسهیلات وام در جریان</span><span class="badge badge-warning"><?= fa_digits($agentsCount) ?> نماینده</span></div>
        <div class="stat-value"><?= fmt_token($activeLoansSum) ?></div>
        <div class="stat-sub">کل کیف پول‌های کاربران: <?= fa_digits($totalUsers) ?> حساب</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">
            <span>سلامت رمزنگاری زنجیره</span>
            <span class="badge <?= $chain['ok'] ? 'badge-success' : 'badge-danger' ?>"><?= $chain['ok'] ? '✓ ۱۰۰٪ سالم' : 'خطا' ?></span>
        </div>
        <div class="stat-value"><?= fa_digits($chain['blocks_checked']) ?> بلاک</div>
        <div class="stat-sub">گروه‌های اجتماعی متصل: <?= fa_digits($groupsCount) ?> گروه</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h3 class="card-title">📈 نمودار ۱۴ روزه حجم انتقال توکن در شبکه</h3>
            <span class="badge badge-success">ارزش واحد: <?= fa_digits(number_format($sup['price_irt'])) ?> تومان</span>
        </div>
        <svg viewBox="0 0 560 195" style="width:100%; height:auto; display:block;">
            <line x1="20" y1="162" x2="545" y2="162" stroke="#e2e8f0" stroke-width="1"/>
            <?php
            $idx = 0;
            foreach ($days as $dateKey => $vals):
                $x = 28 + ($idx * 37);
                $barH = max(4, (int) round(($vals['vol'] / $maxVol) * 132));
                $y = 162 - $barH;
                $shortDay = substr($dateKey, 8, 2);
            ?>
                <rect x="<?= $x ?>" y="<?= $y ?>" width="22" height="<?= $barH ?>" rx="5" fill="#10b981" opacity="0.88"/>
                <text x="<?= $x + 11 ?>" y="180" text-anchor="middle" fill="#64748b" font-size="10" font-family="Vazirmatn"><?= fa_digits($shortDay) ?></text>
            <?php $idx++; endforeach; ?>
        </svg>
    </div>

    <div class="card">
        <div class="card-head">
            <h3 class="card-title">🏆 برترین کیف پول‌های شبکه</h3>
            <a class="btn btn-outline btn-sm" href="?r=users">مشاهده همه</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>نام حساب</th><th>کد</th><th>نوع</th><th>موجودی</th><th>امتیاز</th></tr></thead>
                <tbody>
                    <?php foreach ($topHolders as $th): ?>
                        <tr>
                            <td><b><?= e($th['full_name']) ?></b></td>
                            <td><code><?= e($th['wallet_code']) ?></code></td>
                            <td><span class="badge <?= $th['account_type'] === 'agent' ? 'badge-indigo' : 'badge-muted' ?>"><?= e($th['account_type']) ?></span></td>
                            <td><b><?= fmt_token($th['balance']) ?></b></td>
                            <td><?= fa_digits($th['credit_score']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h3 class="card-title">⛓ آخرین بلاک‌های دفتر کل زنجیره‌ای</h3>
        <a class="btn btn-outline btn-sm" href="?r=ledger">بازرس کامل زنجیره</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>هش بلاک</th><th>عملیات</th><th>مسیر انتقال</th><th>مبلغ خالص</th><th>کارمزد</th><th>کانال</th><th>زمان</th></tr></thead>
            <tbody>
                <?php foreach ($recentTxs as $tx): ?>
                    <tr>
                        <td><?= fa_digits($tx['id']) ?></td>
                        <td><code><?= e(substr((string) $tx['tx_hash'], 0, 14)) ?>…</code></td>
                        <td><span class="badge badge-indigo"><?= e($tx['tx_type']) ?></span></td>
                        <td><code><?= e($tx['from_code'] ?: 'MINT') ?></code> ⬅️ <code><?= e($tx['to_code'] ?: 'BURN') ?></code></td>
                        <td><b><?= fmt_token($tx['net_amount']) ?></b></td>
                        <td><?= fmt_token($tx['fee_amount']) ?></td>
                        <td><span class="badge badge-muted"><?= e($tx['channel']) ?></span></td>
                        <td class="ltr"><?= e($tx['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
