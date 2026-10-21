<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Log;
use App\Settings;
use App\Social\BotEngine;
use App\Social\MessengerClient;

echo "Starting YarToken Dual Messenger Poller (Bale + Telegram)...\n";
$offsets = ['bale' => 0, 'telegram' => 0];

while (true) {
    foreach (['bale', 'telegram'] as $platform) {
        if (!Settings::bool($platform . '_enabled', true) || MessengerClient::tokenFor($platform) === '') {
            continue;
        }
        $res = MessengerClient::call($platform, 'getUpdates', [
            'offset'  => $offsets[$platform],
            'timeout' => 5,
            'limit'   => 50,
        ]);
        if (!empty($res['ok']) && is_array($res['result'] ?? null)) {
            foreach ($res['result'] as $upd) {
                $offsets[$platform] = ((int) $upd['update_id']) + 1;
                try {
                    BotEngine::handleUpdate($platform, $upd);
                } catch (\Throwable $e) {
                    Log::error('poll_err', ['platform' => $platform, 'err' => $e->getMessage()]);
                }
            }
        }
    }
    usleep(400_000);
}
