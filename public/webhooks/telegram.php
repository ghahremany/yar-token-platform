<?php
declare(strict_types=1);

define('YAR_STATELESS', true);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Config;
use App\Log;
use App\Settings;
use App\Social\BotEngine;

if (!is_installed() || !Settings::bool('telegram_enabled', true)) {
    json_response(['ok' => true, 'skipped' => true]);
}

$expected = (string) Config::get('webhook_secret', '');
if ($expected !== '') {
    $provided = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? $_GET['secret'] ?? '');
    if (!hash_equals($expected, $provided)) {
        http_response_code(403);
        json_response(['ok' => false, 'error' => 'Invalid webhook secret'], 403);
    }
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (is_array($update)) {
    try {
        BotEngine::handleUpdate('telegram', $update);
    } catch (\Throwable $e) {
        Log::error('tg_webhook_err', ['err' => $e->getMessage()]);
    }
}
json_response(['ok' => true]);
