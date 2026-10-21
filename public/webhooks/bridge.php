<?php
declare(strict_types=1);

define('YAR_STATELESS', true);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Config;
use App\Log;
use App\Settings;
use App\Social\BotEngine;

/**
 * Signed Inbound Social Bridge Webhook (/webhooks/bridge.php)
 * Connects Rubika (n8n-nodes-rubika), Eitaa, Discord, WhatsApp, or any custom n8n workflow
 * using HMAC-SHA256 (`X-YarToken-Signature: sha256=<hex>`).
 */
if (!is_installed()) {
    json_response(['ok' => false, 'error' => 'Not installed'], 503);
}

$raw = file_get_contents('php://input') ?: '';
$secret = trim(decrypt_secret(Settings::get('bridge_hmac_secret_enc', '')) ?: (string) Config::get('bridge_hmac_secret', ''));
if ($secret === '') {
    json_response(['ok' => false, 'error' => 'Bridge secret not configured'], 403);
}

$sigHeader = trim((string) ($_SERVER['HTTP_X_YARTOKEN_SIGNATURE'] ?? ''));
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $sigHeader)) {
    json_response(['ok' => false, 'error' => 'امضای HMAC پل ارتباطی نامعتبر است.'], 403);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

try {
    BotEngine::handleUpdate('bridge', $payload);
    json_response(['ok' => true]);
} catch (\Throwable $e) {
    Log::error('bridge_webhook_err', ['err' => $e->getMessage()]);
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
