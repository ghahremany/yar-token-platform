<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Loans;
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

$lockFp = fopen(root_path('storage/tmp/cron_loans.lock'), 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit("Already running\n");
}

try {
    $stats = Loans::processScheduledInstallments();
    Settings::set('last_loans_cron_at', date('Y-m-d H:i:s'));
    echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
