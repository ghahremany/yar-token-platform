<?php
declare(strict_types=1);

namespace App\Social;

use App\Config;
use App\IpGuard;
use App\Log;
use App\Settings;

/**
 * Unified Multi-Messenger Client:
 * - Bale Messenger Bot API (https://tapi.bale.ai)
 * - Telegram Bot API (https://api.telegram.org OR custom Worker/Proxy URL for Iranian servers!)
 * - Outbound Signed Webhook Bridge (HMAC-SHA256 for Rubika, Eitaa, Discord, n8n workflows)
 */
final class MessengerClient
{
    public static function tokenFor(string $platform): string
    {
        return match ($platform) {
            'bale' => trim(decrypt_secret(Settings::get('bale_bot_token_enc', '')) ?: (string) Config::get('bale_bot_token', '')),
            'telegram' => trim(decrypt_secret(Settings::get('telegram_bot_token_enc', '')) ?: (string) Config::get('telegram_bot_token', '')),
            default => '',
        };
    }

    public static function apiBaseFor(string $platform): string
    {
        if ($platform === 'bale') {
            $override = trim(Settings::get('bale_api_base_override', ''));
            return rtrim($override !== '' ? $override : (string) Config::get('bale_api_base', 'https://tapi.bale.ai'), '/');
        }
        if ($platform === 'telegram') {
            $override = trim(Settings::get('telegram_api_base_override', ''));
            return rtrim($override !== '' ? $override : (string) Config::get('telegram_api_base', 'https://api.telegram.org'), '/');
        }
        return '';
    }

    /**
     * Calls a Bot API method on Bale or Telegram.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function call(string $platform, string $method, array $params = []): array
    {
        $token = self::tokenFor($platform);
        if ($token === '') {
            return ['ok' => false, 'description' => "توکن ربات {$platform} تنظیم نشده است."];
        }
        $base = self::apiBaseFor($platform);
        $url = "{$base}/bot{$token}/{$method}";

        if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
            $params['reply_markup'] = json_encode($params['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Log::warn('messenger_http_err', ['platform' => $platform, 'method' => $method, 'err' => $err]);
            return ['ok' => false, 'description' => $err];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'description' => "HTTP {$code}"];
        }
        return $decoded;
    }

    /**
     * Sends a formatted message (HTML) on Bale, Telegram, or via Outbound Webhook Bridge.
     *
     * @param ?array<string,mixed> $replyMarkup
     * @return array<string,mixed>
     */
    public static function sendMessage(
        string $platform,
        string|int $chatId,
        string $htmlText,
        ?array $replyMarkup = null,
        ?int $replyToMessageId = null
    ): array {
        if ($platform === 'bridge') {
            return self::pushBridgeEvent('bot.message', [
                'chat_id'             => (string) $chatId,
                'text'                => strip_tags($htmlText),
                'html'                => $htmlText,
                'reply_markup'        => $replyMarkup,
                'reply_to_message_id' => $replyToMessageId,
            ]);
        }

        $payload = [
            'chat_id'    => (string) $chatId,
            'text'       => $htmlText,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($replyToMessageId !== null && $replyToMessageId > 0) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }
        return self::call($platform, 'sendMessage', $payload);
    }

    /**
     * Dispatches a signed JSON event to external social automation bridges (Rubika, Eitaa, Discord, n8n).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function pushBridgeEvent(string $event, array $data): array
    {
        $webhookUrl = trim(Settings::get('bridge_outbound_url', ''));
        if ($webhookUrl === '') {
            return ['ok' => false, 'description' => 'وبهوک خروجی پل اجتماعی تنظیم نشده است.'];
        }
        try {
            IpGuard::assertSafeOutboundUrl($webhookUrl);
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        $body = json_encode([
            'event'     => $event,
            'timestamp' => time(),
            'data'      => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $secret = trim(decrypt_secret(Settings::get('bridge_hmac_secret_enc', '')) ?: (string) Config::get('bridge_hmac_secret', ''));
        $sig = hash_hmac('sha256', $body, $secret !== '' ? $secret : 'yartoken-bridge');

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'X-YarToken-Event: ' . $event,
                'X-YarToken-Signature: sha256=' . $sig,
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'raw' => is_string($raw) ? substr($raw, 0, 200) : ''];
    }

    /**
     * Delivers non-blocking notification to all linked social channels of a user + bridge event.
     */
    public static function notifyUser(array $user, string $htmlMessage): void
    {
        if (!empty($user['bale_user_id']) && Settings::bool('bale_enabled', true)) {
            self::sendMessage('bale', (string) $user['bale_user_id'], $htmlMessage);
        }
        if (!empty($user['telegram_user_id']) && Settings::bool('telegram_enabled', true)) {
            self::sendMessage('telegram', (string) $user['telegram_user_id'], $htmlMessage);
        }
    }

    /**
     * Broadcasts public transparency alerts (Mints / Whale Transfers) to configured Bale & Telegram channels.
     */
    public static function broadcastPublicAlert(string $htmlMessage): void
    {
        $baleCh = trim(Settings::get('public_alert_bale_chat', ''));
        if ($baleCh !== '' && Settings::bool('bale_enabled', true)) {
            self::sendMessage('bale', $baleCh, $htmlMessage);
        }
        $tgCh = trim(Settings::get('public_alert_tg_chat', ''));
        if ($tgCh !== '' && Settings::bool('telegram_enabled', true)) {
            self::sendMessage('telegram', $tgCh, $htmlMessage);
        }
        if (trim(Settings::get('bridge_outbound_url', '')) !== '') {
            self::pushBridgeEvent('ledger.alert', ['html' => $htmlMessage, 'text' => strip_tags($htmlMessage)]);
        }
    }
}
