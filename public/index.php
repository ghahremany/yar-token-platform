<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Auditor;
use App\Db;
use App\Ledger;
use App\TokenEngine;
use App\Users;

if (!is_installed()) {
    redirect_to(base_url('install.php'));
}

$sup = TokenEngine::supplyOverview();
$chain = Ledger::verifyChainIntegrity();
$liveAudit = !empty($_GET['audit']) ? Auditor::runFullAudit() : null;
$q = trim((string) ($_GET['q'] ?? $_GET['address'] ?? $_GET['tx'] ?? ''));
if ($q === '' && !empty($_SERVER['REQUEST_URI'])) {
    $uriPath = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('~/(?:address|tx)/(0x[a-fA-F0-9]{40,64})~', $uriPath, $mUri)) {
        $q = $mUri[1];
    }
}
$searchedWallet = null;
$searchedTx = null;

if ($q !== '') {
    if (str_starts_with($q, '0x') && strlen($q) === 66) {
        $st = Db::pdo()->prepare(
            'SELECT l.*, su.wallet_code AS from_code, ru.wallet_code AS to_code
             FROM ledger_entries l
             LEFT JOIN users su ON su.id = l.from_user_id
             LEFT JOIN users ru ON ru.id = l.to_user_id
             WHERE l.tx_hash = ? LIMIT 1'
        );
        $st->execute([$q]);
        $searchedTx = $st->fetch() ?: null;
    } else {
        $searchedWallet = Users::resolveRecipient($q);
    }
}

$recentBlocks = Db::pdo()->query(
    'SELECT l.*, su.wallet_code AS from_code, ru.wallet_code AS to_code
     FROM ledger_entries l
     LEFT JOIN users su ON su.id = l.from_user_id
     LEFT JOIN users ru ON ru.id = l.to_user_id
     ORDER BY l.id DESC LIMIT 12'
)->fetchAll();

$assetsUrl = base_url('assets');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($sup['name']) ?> (<?= e($sup['symbol']) ?>) — کاوشگر شفافیت زنجیره و درگاه کیف پول</title>
    <link rel="stylesheet" href="<?= e($assetsUrl) ?>/app.css">
</head>
<body>
<div style="background: linear-gradient(135deg, #0b1120 0%, #064e3b 100%); color: #fff; padding: 36px 24px 44px;">
    <div style="max-width: 1260px; margin: 0 auto;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:28px;">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="brand-coin" style="width:48px; height:48px; font-size:19px;"><?= e($sup['symbol']) ?></div>
                <div>
                    <h1 style="margin:0; font-size:22px;"><?= e($sup['name']) ?> (<?= e($sup['name_en']) ?>)</h1>
                    <small style="color:#a7f3d0;">دفتر کل رمزنگاری‌شده زنجیره‌ای + پل غیرحضانتی اندروید Wallet Yar + بله و تلگرام</small>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a class="btn btn-accent" href="<?= e(base_url('?audit=1')) ?>">⚡ ممیزی یک‌کلیکی شبکه</a>
                <a class="btn btn-outline" style="color:#fff; border-color:rgba(255,255,255,.35);" href="<?= e(base_url('pay/')) ?>">💳 درگاه پرداخت توکنی</a>
                <a class="btn btn-outline" style="color:#fef08a; border-color:rgba(253,224,71,.45);" href="<?= e(base_url('packet/')) ?>">🧧 پاکت هدیه گروهی</a>
                <a class="btn btn-outline" style="color:#fff; border-color:rgba(255,255,255,.35);" href="<?= e(base_url('wallet/')) ?>">💼 کیف پول وب</a>
                <a class="btn btn-outline" style="color:#fff; border-color:rgba(255,255,255,.35);" href="<?= e(base_url('agent/')) ?>">🤝 نمایندگان</a>
                <a class="btn btn-primary" href="<?= e(base_url('admin/')) ?>">🛡️ پنل مدیر کل</a>
            </div>
        </div>

        <form method="get" style="display:flex; gap:10px; max-width:740px;">
            <input name="q" value="<?= e($q) ?>" placeholder="جستجوی هش تراکنش (0x...)، آدرس کیف پول (0x...) یا شناسه (YAR-...)"
                   style="flex:1; padding:12px 16px; border-radius:12px; border:none; font-family:inherit; font-size:14px;">
            <button class="btn btn-accent" type="submit">🔍 کاوش در زنجیره</button>
        </form>
    </div>
</div>

<div class="content" style="margin-top: -22px;">
    <div class="grid grid-4" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="stat-label"><span>عرضه خالص شبکه</span><span class="badge badge-success">زنده</span></div>
            <div class="stat-value"><?= fmt_token($sup['net_total_supply']) ?></div>
            <div class="stat-sub">نرخ مرجع: <?= fa_digits(number_format($sup['price_irt'])) ?> تومان</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><span>توکن‌سوزی ضدتورمی</span><span class="badge badge-danger">🔥 Burned</span></div>
            <div class="stat-value"><?= fmt_token($sup['total_burned']) ?></div>
            <div class="stat-sub">خزانه مرکزی: <?= fmt_token($sup['treasury_balance']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><span>تراکنش‌های زنجیره</span><span class="badge badge-indigo">SHA-256</span></div>
            <div class="stat-value"><?= fa_digits(number_format($sup['transactions_count'])) ?></div>
            <div class="stat-sub">کیف پول‌های فعال: <?= fa_digits($sup['active_holders']) ?> حساب</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><span>اثبات سلامت زنجیره (PoR)</span>
                <span class="badge <?= $chain['ok'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $chain['ok'] ? '✓ ۱۰۰٪ تاییدشده' : 'هشدار' ?>
                </span>
            </div>
            <div class="stat-value" style="font-size:14px;"><code class="ltr"><?= e(substr((string) $chain['head_hash'], 0, 22)) ?>…</code></div>
            <div class="stat-sub">اختلاف تراز عرضه: <span class="ltr"><?= e($chain['supply_discrepancy']) ?></span></div>
        </div>
    </div>

    <?php if ($liveAudit): ?>
        <div class="card" style="border:2px solid <?= $liveAudit['ok'] ? '#10b981' : '#ef4444' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <h3 class="card-title" style="margin:0;">🛡️ گزارش ممیزی یک‌کلیکی زنجیره و اثبات ذخیره زنده (اجرا در <?= fa_digits((string) $liveAudit['duration_ms']) ?> میلی‌ثانیه)</h3>
                <span class="badge <?= $liveAudit['ok'] ? 'badge-success' : 'badge-danger' ?>"><?= $liveAudit['ok'] ? '✓ ۷ از ۷ شاخص ریاضی تایید شد' : 'هشدار مغایرت' ?></span>
            </div>
            <div class="grid grid-2">
                <?php foreach ($liveAudit['checks'] as $chk): ?>
                    <div style="background:#f8fafc; padding:12px; border-radius:12px; border-right:3px solid <?= $chk['passed'] ? '#10b981' : '#ef4444' ?>;">
                        <b><?= e($chk['title']) ?></b>
                        <div style="font-size:12.5px; color:#64748b; margin-top:4px;"><?= e($chk['detail']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($q !== ''): ?>
        <div class="card">
            <h3 class="card-title" style="margin-bottom:12px;">🔎 نتیجه جستجو برای <code><?= e($q) ?></code></h3>
            <?php if ($searchedTx): ?>
                <div class="form-grid">
                    <div><b>نوع تراکنش:</b> <span class="badge badge-indigo"><?= e($searchedTx['tx_type']) ?></span></div>
                    <div><b>مبلغ ناخالص:</b> <?= fmt_token($searchedTx['gross_amount']) ?></div>
                    <div><b>خالص دریافتی:</b> <?= fmt_token($searchedTx['net_amount']) ?></div>
                    <div><b>کارمزد شبکه:</b> <?= fmt_token($searchedTx['fee_amount']) ?></div>
                    <div><b>از حساب:</b> <code><?= e($searchedTx['from_code'] ?: 'GENESIS / MINT') ?></code></div>
                    <div><b>به حساب:</b> <code><?= e($searchedTx['to_code'] ?: '—') ?></code></div>
                    <div style="grid-column:1/-1;"><b>هش بلاک قبلی:</b> <code><?= e($searchedTx['prev_hash']) ?></code></div>
                </div>
            <?php elseif ($searchedWallet): ?>
                <div class="form-grid">
                    <div><b>شناسه کیف پول:</b> <code><?= e($searchedWallet['wallet_code']) ?></code></div>
                    <div><b>آدرس EVM:</b> <code><?= e($searchedWallet['evm_address']) ?></code></div>
                    <div><b>موجودی تاییدشده:</b> <b><?= fmt_token($searchedWallet['balance']) ?></b></div>
                    <div><b>نوع حساب:</b> <span class="badge badge-success"><?= e($searchedWallet['account_type']) ?></span></div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin:0;">رکوردی با این مشخصات در دفتر کل یافت نشد.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head">
            <h3 class="card-title">⛓ آخرین تراکنش‌های تاییدشده روی زنجیره دفتر کل</h3>
            <div>
                <span style="font-size:12px; color:#64748b;">EVM RPC Endpoint:</span>
                <code><?= e(base_url('rpc.php')) ?></code>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>هش تراکنش</th>
                        <th>نوع عملیات</th>
                        <th>فرستنده ⬅️ گیرنده</th>
                        <th>مقدار خالص</th>
                        <th>کارمزد</th>
                        <th>کانال</th>
                        <th>زمان ثبت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBlocks as $b): ?>
                        <tr>
                            <td><?= fa_digits($b['id']) ?></td>
                            <td><a href="?q=<?= urlencode((string) $b['tx_hash']) ?>"><code><?= e(substr((string) $b['tx_hash'], 0, 14)) ?>…</code></a></td>
                            <td><span class="badge badge-indigo"><?= e($b['tx_type']) ?></span></td>
                            <td>
                                <code><?= e($b['from_code'] ?: 'MINT') ?></code>
                                ⬅️
                                <code><?= e($b['to_code'] ?: 'BURN') ?></code>
                            </td>
                            <td><b><?= fmt_token($b['net_amount']) ?></b></td>
                            <td><?= fmt_token($b['fee_amount']) ?></td>
                            <td><span class="badge badge-muted"><?= e($b['channel']) ?></span></td>
                            <td class="ltr"><?= e($b['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="<?= e($assetsUrl) ?>/app.js"></script>
</body>
</html>
