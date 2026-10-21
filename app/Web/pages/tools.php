<?php
declare(strict_types=1);

use App\Config;
use App\Csrf;
use App\Db;
use App\Settings;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form_action'] ?? '') === 'sql_dump') {
    Csrf::assertPost();
    $pdo = Db::pdo();
    $sql = "-- YarToken SQL Backup Generated " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $tbl) {
        $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(\PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . ($create[1] ?? '') . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll();
        foreach ($rows as $row) {
            $vals = array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
            $sql .= "INSERT INTO `{$tbl}` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="yartoken-backup-' . date('Ymd-His') . '.sql"');
    echo $sql;
    exit;
}

$cronKey = (string) Config::get('cron_key', '');
$audits = Db::pdo()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 40')->fetchAll();
?>
<div class="grid grid-2">
    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">💾 پشتیبان‌گیری کامل پایگاه داده (SQL Export)</h3>
        <p style="color:#64748b; font-size:13px;">خروجی کامل جداول، دفتر کل زنجیره‌ای، کیف پول‌ها، نمایندگان و پرونده‌های وام (قابل بازگردانی مستقیم در phpMyAdmin):</p>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="sql_dump">
            <button class="btn btn-primary" type="submit">⬇️ دانلود فایل پشتیبان کامل (.sql)</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom:12px;">⏱ کرون‌جاب‌های خودکار سرور (cPanel / DirectAdmin / Linux)</h3>
        <div style="margin-bottom:8px;">
            <small style="color:#64748b;">۱. وصول خودکار اقساط سررسیدشده و ارسال یادآوری وام‌ها (هر ۵ دقیقه):</small><br>
            <code>*/5 * * * * php <?= e(root_path('crons/loans.php')) ?> >/dev/null 2>&1</code>
        </div>
        <div style="margin-bottom:8px;">
            <small style="color:#64748b;">۲. نگهداشت امنیتی و پاکسازی OTP/Nonce منقضی (هر دقیقه):</small><br>
            <code>* * * * * php <?= e(root_path('crons/maintenance.php')) ?> >/dev/null 2>&1</code>
        </div>
        <div style="font-size:12px; color:#059669;">
            آخرین اجرای کرون وام: <b><?= e(Settings::get('last_loans_cron_at', 'ثبت نشده')) ?></b> |
            نگهداشت: <b><?= e(Settings::get('last_maintenance_cron_at', 'ثبت نشده')) ?></b>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:12px;">🛡️ لاگ ممیزی امنیتی مدیران و رویدادهای حساس (Audit Log)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>عامل</th><th>رویداد</th><th>شرح</th><th>IP</th><th>زمان</th></tr></thead>
            <tbody>
                <?php foreach ($audits as $au): ?>
                    <tr>
                        <td><?= fa_digits($au['id']) ?></td>
                        <td><span class="badge badge-muted"><?= e($au['actor_type']) ?> #<?= (int) $au['actor_id'] ?></span></td>
                        <td><code><?= e($au['event']) ?></code></td>
                        <td><?= e($au['summary']) ?></td>
                        <td class="ltr"><?= e($au['ip_address']) ?></td>
                        <td class="ltr"><?= e($au['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
