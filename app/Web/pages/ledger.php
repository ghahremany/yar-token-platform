<?php
declare(strict_types=1);

use App\Db;
use App\Ledger;

$integrity = Ledger::verifyChainIntegrity();
$typeFilter = trim((string) ($_GET['type'] ?? ''));

$pdo = Db::pdo();
if ($typeFilter !== '') {
    $st = $pdo->prepare(
        'SELECT l.*, su.wallet_code AS from_code, ru.wallet_code AS to_code
         FROM ledger_entries l
         LEFT JOIN users su ON su.id = l.from_user_id
         LEFT JOIN users ru ON ru.id = l.to_user_id
         WHERE l.tx_type = ?
         ORDER BY l.id DESC LIMIT 120'
    );
    $st->execute([$typeFilter]);
    $entries = $st->fetchAll();
} else {
    $entries = $pdo->query(
        'SELECT l.*, su.wallet_code AS from_code, ru.wallet_code AS to_code
         FROM ledger_entries l
         LEFT JOIN users su ON su.id = l.from_user_id
         LEFT JOIN users ru ON ru.id = l.to_user_id
         ORDER BY l.id DESC LIMIT 120'
    )->fetchAll();
}
?>
<div class="alert <?= $integrity['ok'] ? 'alert-success' : 'alert-error' ?>">
    <b><?= $integrity['ok'] ? '✅ راستی‌آزمایی ریاضی و رمزنگاری کل زنجیره با موفقیت تایید شد!' : '❌ هشدار عدم تطابق زنجیره!' ?></b><br>
    تعداد بلاک‌های بررسی‌شده: <b><?= fa_digits($integrity['blocks_checked']) ?></b> |
    کل ضرب‌شده: <b><?= fmt_token($integrity['total_minted']) ?></b> |
    کل سوزانده‌شده: <b><?= fmt_token($integrity['total_burned']) ?></b> |
    اختلاف تراز عرضه کل: <code><?= e($integrity['supply_discrepancy']) ?></code> |
    آخرین هش سرزنجیره: <code><?= e($integrity['head_hash']) ?></code>
</div>

<div class="card">
    <div class="card-head">
        <h3 class="card-title">⛓ مرورگر بلاک‌به‌بلاک دفتر کل تغییرناپذیر (SHA-256 Hash Chain)</h3>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <?php foreach (['' => 'همه', 'mint' => 'Mint', 'burn' => 'Burn', 'transfer' => 'انتقال', 'loan_disburse' => 'وام', 'loan_repay' => 'قسط', 'group_tip' => 'انعام گروه', 'red_packet_claim' => 'پاکت هدیه'] as $k => $lbl): ?>
                <a class="btn <?= $typeFilter === $k ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?r=ledger<?= $k !== '' ? '&type=' . $k : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>هش فعلی / قبلی</th><th>نوع</th><th>فرستنده ⬅️ گیرنده</th>
                    <th>مبلغ ناخالص</th><th>دریافتی خالص</th><th>کارمزد / سوزانده / نماینده</th><th>کانال</th><th>شرح</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $eRow): ?>
                    <tr>
                        <td><?= fa_digits($eRow['id']) ?></td>
                        <td>
                            <code><?= e(substr((string) $eRow['tx_hash'], 0, 14)) ?>…</code><br>
                            <small style="color:#94a3b8;">prev: <code><?= e(substr((string) $eRow['prev_hash'], 0, 10)) ?>…</code></small>
                        </td>
                        <td><span class="badge badge-indigo"><?= e($eRow['tx_type']) ?></span></td>
                        <td><code><?= e($eRow['from_code'] ?: 'MINT') ?></code> ⬅️ <code><?= e($eRow['to_code'] ?: 'BURN') ?></code></td>
                        <td><?= fmt_token($eRow['gross_amount']) ?></td>
                        <td><b><?= fmt_token($eRow['net_amount']) ?></b></td>
                        <td style="font-size:12px;">
                            ⚖️ <?= fmt_token($eRow['fee_amount'], false) ?> |
                            🔥 <?= fmt_token($eRow['burned_amount'], false) ?> |
                            🤝 <?= fmt_token($eRow['agent_commission_amount'], false) ?>
                        </td>
                        <td><span class="badge badge-muted"><?= e($eRow['channel']) ?></span></td>
                        <td><?= e($eRow['memo']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
