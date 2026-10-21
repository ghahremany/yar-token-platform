<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Db;
use App\Settings;

if (!is_installed()) {
    exit("Not installed\n");
}

if (PHP_SAPI !== 'cli') {
    $expected = (string) Config::get('cron_key', '');
    $given = (string) ($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$pdo = Db::pdo();
$pdo->exec('DELETE FROM otp_challenges WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$pdo->exec('DELETE FROM auth_nonces WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$pdo->exec('DELETE FROM rate_limits WHERE reset_at < ' . (time() - 3600));
$pdo->exec('UPDATE merchant_invoices SET status = "expired" WHERE status = "pending" AND expires_at < NOW()');
Settings::set('last_maintenance_cron_at', date('Y-m-d H:i:s'));

echo json_encode(['ok' => true, 'cleaned_at' => date('c')], JSON_UNESCAPED_UNICODE) . "\n";
