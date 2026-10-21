<?php
declare(strict_types=1);

namespace App\Crypto;

/**
 * RFC 6238 TOTP + RFC 4648 Base32 Engine (100% compatible with Wallet Yar Authenticator & Google Authenticator).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function uri(string $secretBase32, string $accountLabel, string $issuer = 'YarToken', int $digits = 6, int $period = 30): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $params = http_build_query([
            'secret'    => strtoupper(preg_replace('/\s+/', '', $secretBase32) ?? ''),
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => $digits,
            'period'    => $period,
        ], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/{$label}?{$params}";
    }

    public static function code(string $secretBase32, ?int $timestamp = null, int $digits = 6, int $period = 30, string $algo = 'sha1'): string
    {
        $ts = $timestamp ?? time();
        $counter = intdiv($ts, $period);
        $key = self::base32Decode($secretBase32);
        $binCounter = pack('J', $counter);
        $hmac = hash_hmac(strtolower($algo), $binCounter, $key, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0f;
        $val = (
            ((ord($hmac[$offset]) & 0x7f) << 24) |
            ((ord($hmac[$offset + 1]) & 0xff) << 16) |
            ((ord($hmac[$offset + 2]) & 0xff) << 8) |
            (ord($hmac[$offset + 3]) & 0xff)
        ) % (10 ** $digits);
        return str_pad((string) $val, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secretBase32, string $inputCode, int $window = 1, ?int $now = null, int &$matchedSlice = 0): bool
    {
        $clean = preg_replace('/\D+/', '', $inputCode) ?? '';
        if (strlen($clean) !== 6 && strlen($clean) !== 8) {
            return false;
        }
        $ts = $now ?? time();
        $digits = strlen($clean);
        for ($w = -$window; $w <= $window; $w++) {
            $stepTs = $ts + ($w * 30);
            $expected = self::code($secretBase32, $stepTs, $digits, 30);
            if (hash_equals($expected, $clean)) {
                $matchedSlice = intdiv($stepTs, 30);
                return true;
            }
        }
        return false;
    }

    public static function base32Encode(string $raw): string
    {
        if ($raw === '') return '';
        $bits = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = str_pad(substr($bits, $i, 5), 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(str_replace(['=', ' ', '-'], '', trim($encoded)));
        if ($clean === '') return '';
        $bits = '';
        for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
            $pos = strpos(self::ALPHABET, $clean[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException('کلید Base32 نامعتبر است.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
