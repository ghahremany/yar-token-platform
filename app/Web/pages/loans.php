<?php
declare(strict_types=1);

use App\Csrf;
use App\Db;
use App\Loans;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    $stats = Loans::processScheduledInstallments();
    flash_set(
        'success',
        'پردازش خودکار سررسید اقساط انجام شد — وصول خودکار: ' . fa_digits($stats['auto_paid'])
        . ' | معوق‌شده: ' . fa_digits($stats['marked_overdue'])
        . ' | یادآوری ارسالی: ' . fa_digits($stats['reminders_sent'])
    );
    redirect_to(base_url('admin/?r=loans'));
}

$loans = Db::pdo()->query(
    'SELECT l.*, bu.full_name AS borrower_name, bu.wallet_code AS borrower_code,
            ag.title AS agent_title, au.wallet_code AS agent_code_wallet
     FROM loans l
     JOIN users bu ON bu.id = l.borrower_user_id
     JOIN users au ON au.id = l.agent_user_id
     LEFT JOIN agents ag ON ag.user_id = l.agent_user_id
     ORDER BY l.id DESC LIMIT 100'
)->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title">🏦 نظارت سراسری تسهیلات، اقساط و وصول خودکار سررسیدها</h3>
            <small style="color:#64748b;">تمامی وام‌های صادرشده توسط نمایندگان (چه از استخر اعتباری و چه از کیف پول شخصی نماینده)</small>
        </div>
        <form method="post">
            <?= Csrf::field() ?>
            <button class="btn btn-primary" type="submit">⚡ اجرای فوری موتور وصول خودکار اقساط سررسیدشده</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>کد پرونده</th><th>نماینده وام‌دهنده</th><th>وام‌گیرنده</th><th>منبع</th>
                    <th>اصل وام</th><th>کل بازپرداخت</th><th>پرداخت‌شده</th><th>مانده بدهی</th><th>اقساط</th><th>وضعیت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loans as $ln): ?>
                    <tr>
                        <td><code><?= e($ln['loan_code']) ?></code></td>
                        <td><b><?= e($ln['agent_title'] ?: 'نماینده') ?></b><br><small><code><?= e($ln['agent_code_wallet']) ?></code></small></td>
                        <td><b><?= e($ln['borrower_name']) ?></b><br><small><code><?= e($ln['borrower_code']) ?></code></small></td>
                        <td><span class="badge badge-muted"><?= $ln['funding_source'] === 'credit_pool' ? 'استخر اعتباری' : 'کیف نماینده' ?></span></td>
                        <td><?= fmt_token($ln['principal_amount']) ?></td>
                        <td><?= fmt_token($ln['total_repayable']) ?></td>
                        <td><?= fmt_token($ln['repaid_amount']) ?></td>
                        <td><b><?= fmt_token($ln['remaining_amount']) ?></b></td>
                        <td><?= fa_digits($ln['installments_count']) ?> قسط</td>
                        <td>
                            <span class="badge <?= $ln['status'] === 'repaid' ? 'badge-success' : ($ln['status'] === 'overdue' ? 'badge-danger' : 'badge-warning') ?>">
                                <?= e($ln['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
