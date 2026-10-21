<?php
declare(strict_types=1);

use App\Config;
use App\Settings;

function root_path(string $sub = ''): string
{
    $base = dirname(__DIR__);
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

function is_installed(): bool
{
    return is_file(root_path('storage/installed.lock'));
}

function base_url(string $path = ''): string
{
    $cfgUrl = rtrim((string) Config::get('app_url', ''), '/');
    if ($cfgUrl === '') {
        $trustProxy = filter_var(Config::get('trust_proxy', false), FILTER_VALIDATE_BOOLEAN);
        $proto = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($trustProxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        ) {
            $proto = 'https';
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Strip port/host injection attempts
        if (!preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/', $host)) {
            $host = 'localhost';
        }
        $cfgUrl = $proto . '://' . $host;
    }
    return $path === '' ? $cfgUrl : $cfgUrl . '/' . ltrim($path, '/');
}

function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formats LTR strings (addresses, @handles, masked chat IDs, codes) safely inside RTL Persian text.
 */
function ltr(string $text): string
{
    return "\u{2066}" . $text . "\u{2069}";
}

function fa_digits(string|int|float $input): string
{
    return strtr((string) $input, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
}

function en_digits(string $input): string
{
    return strtr($input, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/**
 * Normalizes a decimal token amount strictly to scale 8 using bcmath without floating-point errors.
 */
function dec_norm(string|int|float $amount): string
{
    $s = trim(en_digits((string) $amount));
    $s = str_replace([',', ' ', '_'], '', $s);
    if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $s)) {
        throw new InvalidArgumentException('فرمت مقدار عددی نامعتبر است.');
    }
    return bcadd($s, '0', 8);
}

/**
 * Human-readable token formatting using configured decimals (default 4 display decimals, trailing zeros trimmed).
 */
function fmt_token(string|int|float $amount, bool $withSymbol = true, bool $persianDigits = false): string
{
    $norm = bcadd((string) $amount, '0', 8);
    $neg = str_starts_with($norm, '-');
    if ($neg) $norm = substr($norm, 1);

    [$intPart, $fracPart] = explode('.', $norm);
    $grouped = number_format((float) $intPart, 0, '.', ',');
    // For huge integers beyond 53-bit float precision, group manually:
    if (strlen($intPart) > 14) {
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($intPart, '0') ?: '0') ?? $intPart;
    }

    $displayDec = is_installed() ? max(0, min(8, (int) Settings::get('token_decimals_display', '4'))) : 4;
    $trimmedFrac = rtrim($fracPart, '0');
    if (strlen($trimmedFrac) > $displayDec) {
        $trimmedFrac = rtrim(substr($fracPart, 0, max($displayDec, 6)), '0');
    }

    $formatted = ($neg ? '-' : '') . $grouped . ($trimmedFrac !== '' ? '.' . $trimmedFrac : '');
    if ($persianDigits) {
        $formatted = fa_digits($formatted);
    }
    if ($withSymbol) {
        $sym = is_installed() ? (string) Settings::get('token_symbol', 'YAR') : 'YAR';
        return ltr($formatted . ' ' . $sym);
    }
    return $formatted;
}

/**
 * Converts token amount to IRT (Toman) display string using current admin valuation rate.
 */
function fmt_irt(string|int|float $tokenAmount): string
{
    $rate = is_installed() ? (string) Settings::get('token_price_irt', '10000') : '10000';
    if (bccomp($rate, '0', 8) <= 0) {
        return '—';
    }
    $irt = bcmul(dec_norm($tokenAmount), dec_norm($rate), 0);
    return fa_digits(number_format((float) $irt)) . ' تومان';
}

/**
 * Encrypts sensitive secret strings at rest using AES-256-GCM keyed with APP_SECRET.
 */
function encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') return '';
    $key = hash('sha256', (string) Config::get('app_secret', 'fallback-install-key'), true);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('خطا در رمزنگاری داده حساس.');
    }
    return 'gcm1:' . base64_encode($iv . $tag . $ct);
}

function decrypt_secret(?string $payload): string
{
    if ($payload === null || $payload === '') return '';
    if (!str_starts_with($payload, 'gcm1:')) {
        return $payload;
    }
    $raw = base64_decode(substr($payload, 5), true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $key = hash('sha256', (string) Config::get('app_secret', 'fallback-install-key'), true);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/** @return ?array{type:string,message:string} */
function flash_pop(): ?array
{
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($f) ? $f : null;
}

function short_addr(string $addr): string
{
    if (strlen($addr) <= 14) return $addr;
    return substr($addr, 0, 7) . '…' . substr($addr, -5);
}
