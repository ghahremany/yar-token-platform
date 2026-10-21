<?php
declare(strict_types=1);

define('YAR_STATELESS', true);
require_once __DIR__ . '/../app/bootstrap.php';

use App\EvmRpc;
use App\IpGuard;
use App\RateLimiter;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!is_installed()) {
    json_response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32001, 'message' => 'System not installed']], 503);
}

if (!RateLimiter::allow('rpc:' . IpGuard::clientIp(), 240, 60)) {
    json_response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32005, 'message' => 'Rate limit exceeded']], 429);
}

$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    json_response([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => ['code' => -32700, 'message' => 'Parse error — send JSON-RPC 2.0 POST body'],
    ], 400);
}

json_response(EvmRpc::handle($decoded));
