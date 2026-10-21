<?php
declare(strict_types=1);

use App\Crypto\QrSvg;
use App\Csrf;
use App\Db;
use App\TokenEngine;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    $tokId = (int) ($_POST['token_id'] ?? 0);
    Db::pdo()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ?')->execute([$tokId]);
    flash_set('success', 'نشست دستگاه موبایل لغو شد.');
    redirect_to(base_url('admin/?r=android'));
}

$sup = TokenEngine::supplyOverview();
$connectQrJson = json_encode([
    'hub'      => base_url(),
    'api'      => base_url('api/v1/'),
    'rpc'      => base_url('rpc.php'),
    'chain_id' => $sup['chain_id'],
    'symbol'   => $sup['symbol'],
    'contract' => $sup['contract_address'],
], JSON_UNESCAPED_SLASHES) ?: '{}';

$sessions = Db::pdo()->query(
    'SELECT t.*, u.wallet_code, u.evm_address, u.full_name
     FROM api_tokens t JOIN users u ON u.id = t.user_id
     WHERE t.revoked_at IS NULL
     ORDER BY t.id DESC LIMIT 40'
)->fetchAll();
?>
<div class="grid grid-2">
    <div class="card" style="text-align:center;">
        <h3 class="card-title" style="margin-bottom:10px;">📱 اسکن اتصال خودکار اپ اندروید کیف پول (Wallet Yar v2.0)</h3>
        <div class="qr-plate"><?= QrSvg::render($connectQrJson, 200) ?></div>
        <p style="font-size:12.5px; color:#64748b; margin:12px 0 0;">
            با اسکن این بارکد در بخش <b>«توکن اختصاصی و وام (Hub)»</b> اپلیکیشن اندروید شما،
            آدرس سرور، درگاه RPC، نرخ لحظه‌ای تومانی، انتقال با پیش‌نمایش کارمزد و پنل نمایندگی به‌صورت خودکار متصل می‌شوند!
        </p>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">⚙️ مشخصات فنی اتصال دولایه (JSON-RPC 2.0 + Non-Custodial ECDSA API)</h3>
        <div class="field">
            <label>۱. آدرس REST API v1 (احراز هویت امضای دیجیتال secp256k1 بدون خروج کلید خصوصی)</label>
            <input readonly class="ltr" value="<?= e(base_url('api/v1/')) ?>">
        </div>
        <div class="field">
            <label>۲. آدرس استاندارد Ethereum / BSC JSON-RPC (سازگار با بخش Settings → Endpoints اپ فعلی و MetaMask)</label>
            <input readonly class="ltr" value="<?= e(base_url('rpc.php')) ?>">
        </div>
        <div class="form-grid">
            <div><b>Chain ID:</b> <code><?= (int) $sup['chain_id'] ?> (0x<?= dechex((int) $sup['chain_id']) ?>)</code></div>
            <div><b>Symbol / Decimals:</b> <code><?= e($sup['symbol']) ?> / 18 (EVM)</code></div>
            <div style="grid-column:1/-1;"><b>آدرس قرارداد مجازی ERC-20/BEP-20:</b> <code><?= e($sup['contract_address']) ?></code></div>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:12px;">📲 دستگاه‌های اندروید و کلاینت‌های متصل فعال</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>کاربر</th><th>آدرس کیف پول (BIP-44)</th><th>دستگاه</th><th>پیشوند توکن</th><th>آخرین IP / فعالیت</th><th>عملیات</th></tr></thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><b><?= e($s['full_name']) ?></b> (<code><?= e($s['wallet_code']) ?></code>)</td>
                        <td><code><?= e(short_addr((string) $s['evm_address'])) ?></code></td>
                        <td><?= e($s['device_name']) ?></td>
                        <td><code><?= e($s['token_prefix']) ?>…</code></td>
                        <td class="ltr"><?= e(($s['last_ip'] ?: '—') . ' | ' . ($s['last_used_at'] ?: $s['created_at'])) ?></td>
                        <td>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="token_id" value="<?= (int) $s['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">قطع اتصال</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
