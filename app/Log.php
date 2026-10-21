<?php
declare(strict_types=1);

namespace App;

final class Log
{
    /** @param array<string,mixed> $ctx */
    public static function info(string $event, array $ctx = []): void
    {
        self::write('INFO', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function warn(string $event, array $ctx = []): void
    {
        self::write('WARN', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function error(string $event, array $ctx = []): void
    {
        self::write('ERROR', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private static function write(string $level, string $event, array $ctx): void
    {
        // Redact any accidental sensitive keys before writing to disk
        foreach (['password', 'token', 'secret', 'private_key', 'code'] as $sensitive) {
            if (isset($ctx[$sensitive])) {
                $ctx[$sensitive] = '[REDACTED]';
            }
        }
        $dir = root_path('storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $event,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
