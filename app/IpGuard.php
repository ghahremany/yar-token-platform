<?php
declare(strict_types=1);

namespace App;

final class IpGuard
{
    public static function clientIp(): string
    {
        $trustProxy = filter_var(Config::get('trust_proxy', false), FILTER_VALIDATE_BOOLEAN);
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Prevents Server-Side Request Forgery (SSRF) on webhook callbacks & custom proxy URLs in production.
     */
    public static function assertSafeOutboundUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('آدرس URL نامعتبر است.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $env = strtolower((string) Config::get('app_env', 'production'));
        if ($env === 'production' && $scheme !== 'https') {
            throw new \InvalidArgumentException('در محیط عملیاتی فقط آدرس‌های امن HTTPS مجاز هستند.');
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('پروتکل URL غیرمجاز است.');
        }
        if ($env === 'production') {
            $ips = gethostbynamel((string) $parts['host']) ?: [];
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \InvalidArgumentException('آدرس مقصد به شبکه داخلی اشاره دارد و مسدود شد (SSRF Guard).');
                }
            }
        }
    }
}
