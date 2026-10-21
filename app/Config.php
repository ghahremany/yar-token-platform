<?php
declare(strict_types=1);

namespace App;

final class Config
{
    /** @var ?array<string,mixed> */
    private static ?array $items = null;

    public static function reload(): void
    {
        self::$items = null;
        self::load();
    }

    /** @return array<string,mixed> */
    private static function load(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }
        $cfgFile = root_path('config.php');
        $base = is_file($cfgFile) ? (array) require $cfgFile : [];

        $envFile = root_path('.env');
        $envVars = [];
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = strtolower(trim($k));
                $v = trim($v);
                if (
                    (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                    (str_starts_with($v, "'") && str_ends_with($v, "'"))
                ) {
                    $v = substr($v, 1, -1);
                }
                $envVars[$k] = $v;
            }
        }

        // Process environment overrides file values when explicitly exported (e.g. CI tests)
        foreach ([
            'app_name', 'app_url', 'app_env', 'app_secret', 'trust_proxy',
            'db_host', 'db_port', 'db_name', 'db_user', 'db_pass',
            'bale_bot_token', 'bale_api_base', 'telegram_bot_token', 'telegram_api_base',
            'webhook_secret', 'bridge_hmac_secret', 'cron_key',
        ] as $key) {
            $upper = strtoupper($key);
            $sysVal = getenv($upper);
            if ($sysVal !== false && $sysVal !== '') {
                $base[$key] = $sysVal;
            } elseif (array_key_exists($key, $envVars)) {
                $base[$key] = $envVars[$key];
            }
        }

        self::$items = $base;
        return self::$items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $items = self::load();
        $k = strtolower($key);
        return array_key_exists($k, $items) ? $items[$k] : $default;
    }
}
