<?php
declare(strict_types=1);

namespace App\Crypto;

/**
 * Pure-PHP Ethereum Keccak-256 (0x01 padding) + EIP-55 Checksum Address Engine.
 * Zero external dependencies — works on any 64-bit PHP 8.1+ installation.
 */
final class Keccak256
{
    private const RC = [
        0x0000000000000001, 0x0000000000008082, 0x800000000000808a, 0x8000000080008000,
        0x000000000000808b, 0x0000000080000001, 0x8000000080008081, 0x8000000000008009,
        0x000000000000008a, 0x0000000000000088, 0x0000000080008009, 0x000000008000000a,
        0x000000008000808b, 0x800000000000008b, 0x8000000000008089, 0x8000000000008003,
        0x8000000000008002, 0x8000000000000080, 0x000000000000800a, 0x800000008000000a,
        0x8000000080008081, 0x8000000000008080, 0x0000000080000001, 0x8000000080008008,
    ];

    private const ROTC = [
        1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14,
        27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44,
    ];

    private const PILN = [
        10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4,
        15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1,
    ];

    public static function hash(string $binaryInput, bool $rawOutput = false): string
    {
        $rate = 136; // 1088 bits for Keccak-256
        $state = array_fill(0, 25, 0);

        $len = strlen($binaryInput);
        $padLen = $rate - ($len % $rate);
        if ($padLen === 1) {
            $padded = $binaryInput . "\x81";
        } else {
            $padded = $binaryInput . "\x01" . str_repeat("\x00", $padLen - 2) . "\x80";
        }

        $blocks = intdiv(strlen($padded), $rate);
        for ($b = 0; $b < $blocks; $b++) {
            $words = unpack('P17', substr($padded, $b * $rate, $rate));
            for ($i = 0; $i < 17; $i++) {
                $state[$i] ^= $words[$i + 1];
            }
            self::keccakF1600($state);
        }

        $out = pack('P4', $state[0], $state[1], $state[2], $state[3]);
        return $rawOutput ? $out : bin2hex($out);
    }

    private static function rotl64(int $x, int $n): int
    {
        return (($x << $n) | (($x >> (64 - $n)) & ((1 << $n) - 1)));
    }

    /** @param array<int,int> $st */
    private static function keccakF1600(array &$st): void
    {
        $bc = [0, 0, 0, 0, 0];
        for ($round = 0; $round < 24; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $bc[$i] = $st[$i] ^ $st[$i + 5] ^ $st[$i + 10] ^ $st[$i + 15] ^ $st[$i + 20];
            }
            for ($i = 0; $i < 5; $i++) {
                $t = $bc[($i + 4) % 5] ^ self::rotl64($bc[($i + 1) % 5], 1);
                for ($j = 0; $j < 25; $j += 5) {
                    $st[$j + $i] ^= $t;
                }
            }
            $t = $st[1];
            for ($i = 0; $i < 24; $i++) {
                $j = self::PILN[$i];
                $tmp = $st[$j];
                $st[$j] = self::rotl64($t, self::ROTC[$i]);
                $t = $tmp;
            }
            for ($j = 0; $j < 25; $j += 5) {
                $b0 = $st[$j];
                $b1 = $st[$j + 1];
                $b2 = $st[$j + 2];
                $b3 = $st[$j + 3];
                $b4 = $st[$j + 4];
                $st[$j]     ^= (~$b1) & $b2;
                $st[$j + 1] ^= (~$b2) & $b3;
                $st[$j + 2] ^= (~$b3) & $b4;
                $st[$j + 3] ^= (~$b4) & $b0;
                $st[$j + 4] ^= (~$b0) & $b1;
            }
            // Note: PHP signed 64-bit constants with bit 63 set come in as float unless packed/unpacked or hex-masked
            $rc = self::roundConstant($round);
            $st[0] ^= $rc;
        }
    }

    private static function roundConstant(int $r): int
    {
        static $table = null;
        if ($table === null) {
            $hex = [
                '0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000',
                '000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009',
                '000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
                '000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003',
                '8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a',
                '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
            ];
            $table = [];
            foreach ($hex as $h) {
                $hi = hexdec(substr($h, 0, 8));
                $lo = hexdec(substr($h, 8, 8));
                $table[] = ($hi << 32) | $lo;
            }
        }
        return $table[$r];
    }

    /**
     * Formats a 40-hex-character Ethereum/BSC address according to EIP-55 mixed-case checksum.
     */
    public static function checksumAddress(string $address): string
    {
        $clean = strtolower(preg_replace('/^0x/i', '', trim($address)) ?? '');
        if (!preg_match('/^[0-9a-f]{40}$/', $clean)) {
            throw new \InvalidArgumentException('آدرس کیف پول نامعتبر است.');
        }
        $hash = self::hash($clean, false);
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $ch = $clean[$i];
            if ($ch >= 'a' && $ch <= 'f' && hexdec($hash[$i]) >= 8) {
                $out .= strtoupper($ch);
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }

    public static function isValidAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', trim($address));
    }
}
