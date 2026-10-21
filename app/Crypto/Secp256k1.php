<?php
declare(strict_types=1);

namespace App\Crypto;

/**
 * Pure-PHP GMP secp256k1 Elliptic-Curve Engine:
 * - Decompresses compressed (33-byte 02/03) or validates uncompressed (65-byte 04) public keys
 * - Derives exact EIP-55 0x... Ethereum/BSC wallet address from public key (matching Wallet Yar m/44'/60'/0'/0/0!)
 * - Verifies ECDSA (r, s) signatures against SHA-256 / Keccak-256 digests
 * - Signs digests deterministically (RFC 6979) for server self-tests
 */
final class Secp256k1
{
    private const P_HEX  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    private const N_HEX  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    private const GX_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const GY_HEX = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    /**
     * Parses compressed (66 hex chars) or uncompressed (130 hex chars) secp256k1 public key
     * and validates that the point lies strictly on y^2 = x^3 + 7 (mod p).
     *
     * @return array{0:\GMP,1:\GMP}
     */
    public static function parsePublicKey(string $pubHex): array
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($pubHex)) ?? '');
        $p = gmp_init(self::P_HEX, 16);

        if (strlen($hex) === 130 && str_starts_with($hex, '04')) {
            $x = gmp_init(substr($hex, 2, 64), 16);
            $y = gmp_init(substr($hex, 66, 64), 16);
        } elseif (strlen($hex) === 66 && (str_starts_with($hex, '02') || str_starts_with($hex, '03'))) {
            $x = gmp_init(substr($hex, 2, 64), 16);
            if (gmp_cmp($x, 0) <= 0 || gmp_cmp($x, $p) >= 0) {
                throw new \InvalidArgumentException('کلید عمومی خارج از محدوده منحنی است.');
            }
            $rhs = gmp_mod(gmp_add(gmp_powm($x, 3, $p), 7), $p);
            // Since p ≡ 3 (mod 4) for secp256k1, sqrt(a) = a^((p+1)/4) mod p
            $exp = gmp_div_q(gmp_add($p, 1), 4);
            $y = gmp_powm($rhs, $exp, $p);
            $wantOdd = str_starts_with($hex, '03') ? 1 : 0;
            if (gmp_intval(gmp_and($y, 1)) !== $wantOdd) {
                $y = gmp_sub($p, $y);
            }
        } else {
            throw new \InvalidArgumentException('فرمت کلید عمومی نامعتبر است.');
        }

        // Strict curve membership check: y^2 mod p == (x^3 + 7) mod p
        $lhs = gmp_powm($y, 2, $p);
        $rhs = gmp_mod(gmp_add(gmp_powm($x, 3, $p), 7), $p);
        if (gmp_cmp($lhs, $rhs) !== 0) {
            throw new \InvalidArgumentException('نقطه کلید عمومی روی منحنی secp256k1 قرار ندارد.');
        }

        return [$x, $y];
    }

    /**
     * Derives the EIP-55 Ethereum / BSC 0x... address from any compressed or uncompressed secp256k1 public key.
     */
    public static function addressFromPublicKey(string $pubHex): string
    {
        [$x, $y] = self::parsePublicKey($pubHex);
        $xyHex = str_pad(gmp_strval($x, 16), 64, '0', STR_PAD_LEFT)
               . str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT);
        $keccak = Keccak256::hash(hex2bin($xyHex) ?: '', false);
        return Keccak256::checksumAddress(substr($keccak, 24, 40));
    }

    /**
     * Verifies an ECDSA signature (128 hex chars = r[64] || s[64], optionally 130 chars with v)
     * over a 32-byte raw message digest AND checks that publicKey derives expectedAddress (if provided).
     */
    public static function verifyDigest(
        string $digest32Bin,
        string $sigHex,
        string $pubHex,
        ?string $expectedAddress = null
    ): bool {
        if (strlen($digest32Bin) !== 32) {
            return false;
        }
        $cleanSig = strtolower(preg_replace('/^0x/i', '', trim($sigHex)) ?? '');
        if (strlen($cleanSig) !== 128 && strlen($cleanSig) !== 130) {
            return false;
        }

        try {
            [$qx, $qy] = self::parsePublicKey($pubHex);
            if ($expectedAddress !== null && $expectedAddress !== '') {
                $derived = self::addressFromPublicKey($pubHex);
                if (strtolower($derived) !== strtolower(trim($expectedAddress))) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        $n = gmp_init(self::N_HEX, 16);
        $r = gmp_init(substr($cleanSig, 0, 64), 16);
        $s = gmp_init(substr($cleanSig, 64, 64), 16);

        if (gmp_cmp($r, 1) < 0 || gmp_cmp($r, $n) >= 0) {
            return false;
        }
        if (gmp_cmp($s, 1) < 0 || gmp_cmp($s, $n) >= 0) {
            return false;
        }

        $z = gmp_init(bin2hex($digest32Bin), 16);
        $sInv = gmp_invert($s, $n);
        if ($sInv === false) {
            return false;
        }

        $u1 = gmp_mod(gmp_mul($z, $sInv), $n);
        $u2 = gmp_mod(gmp_mul($r, $sInv), $n);

        $g = [gmp_init(self::GX_HEX, 16), gmp_init(self::GY_HEX, 16)];
        $p1 = self::scalarMul($u1, $g);
        $p2 = self::scalarMul($u2, [$qx, $qy]);
        $sum = self::pointAdd($p1, $p2);
        if ($sum === null) {
            return false;
        }

        return gmp_cmp(gmp_mod($sum[0], $n), $r) === 0;
    }

    /**
     * Ethereum Personal-Sign style message hash: Keccak256("\x19Ethereum Signed Message:\n" . len . message)
     */
    public static function personalMessageDigest(string $message): string
    {
        $prefix = "\x19Ethereum Signed Message:\n" . strlen($message);
        return Keccak256::hash($prefix . $message, true);
    }

    /**
     * Deterministic RFC 6979 ECDSA low-S signature over a 32-byte digest (returns [sigHex128, compressedPubHex66, address]).
     *
     * @return array{signature:string,public_key:string,address:string}
     */
    public static function signDigest(string $privHex, string $digest32Bin): array
    {
        $cleanPriv = str_pad(strtolower(preg_replace('/^0x/i', '', trim($privHex)) ?? ''), 64, '0', STR_PAD_LEFT);
        $n = gmp_init(self::N_HEX, 16);
        $d = gmp_init($cleanPriv, 16);
        $g = [gmp_init(self::GX_HEX, 16), gmp_init(self::GY_HEX, 16)];
        $pub = self::scalarMul($d, $g);
        if ($pub === null) {
            throw new \RuntimeException('Invalid private key');
        }
        $prefix = gmp_intval(gmp_and($pub[1], 1)) === 1 ? '03' : '02';
        $pubHex = $prefix . str_pad(gmp_strval($pub[0], 16), 64, '0', STR_PAD_LEFT);

        // RFC 6979 HMAC-DRBG (SHA-256)
        $xBin = hex2bin($cleanPriv) ?: '';
        $v = str_repeat("\x01", 32);
        $k = str_repeat("\x00", 32);
        $k = hash_hmac('sha256', $v . "\x00" . $xBin . $digest32Bin, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        $k = hash_hmac('sha256', $v . "\x01" . $xBin . $digest32Bin, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);

        $z = gmp_init(bin2hex($digest32Bin), 16);
        $halfN = gmp_div_q($n, 2);

        while (true) {
            $v = hash_hmac('sha256', $v, $k, true);
            $kNum = gmp_init(bin2hex($v), 16);
            if (gmp_cmp($kNum, 1) >= 0 && gmp_cmp($kNum, $n) < 0) {
                $pt = self::scalarMul($kNum, $g);
                if ($pt !== null) {
                    $r = gmp_mod($pt[0], $n);
                    $kInv = gmp_invert($kNum, $n);
                    if (gmp_cmp($r, 0) > 0 && $kInv !== false) {
                        $s = gmp_mod(gmp_mul($kInv, gmp_add($z, gmp_mul($r, $d))), $n);
                        if (gmp_cmp($s, 0) > 0) {
                            if (gmp_cmp($s, $halfN) > 0) {
                                $s = gmp_sub($n, $s);
                            }
                            $sigHex = str_pad(gmp_strval($r, 16), 64, '0', STR_PAD_LEFT)
                                    . str_pad(gmp_strval($s, 16), 64, '0', STR_PAD_LEFT);
                            return [
                                'signature'  => $sigHex,
                                'public_key' => $pubHex,
                                'address'    => self::addressFromPublicKey($pubHex),
                            ];
                        }
                    }
                }
            }
            $k = hash_hmac('sha256', $v . "\x00", $k, true);
            $v = hash_hmac('sha256', $v, $k, true);
        }
    }

    /**
     * @param ?array{0:\GMP,1:\GMP} $p
     * @param ?array{0:\GMP,1:\GMP} $q
     * @return ?array{0:\GMP,1:\GMP}
     */
    private static function pointAdd(?array $p, ?array $q): ?array
    {
        if ($p === null) return $q;
        if ($q === null) return $p;

        $mod = gmp_init(self::P_HEX, 16);
        if (gmp_cmp($p[0], $q[0]) === 0) {
            if (gmp_cmp($p[1], $q[1]) !== 0 || gmp_cmp($p[1], 0) === 0) {
                return null;
            }
            $num = gmp_mul(3, gmp_powm($p[0], 2, $mod));
            $den = gmp_invert(gmp_mod(gmp_mul(2, $p[1]), $mod), $mod);
        } else {
            $num = gmp_sub($q[1], $p[1]);
            $den = gmp_invert(gmp_pos_mod(gmp_sub($q[0], $p[0]), $mod), $mod);
        }
        if ($den === false) {
            return null;
        }
        $lam = gmp_pos_mod(gmp_mul($num, $den), $mod);
        $rx = gmp_pos_mod(gmp_sub(gmp_sub(gmp_powm($lam, 2, $mod), $p[0]), $q[0]), $mod);
        $ry = gmp_pos_mod(gmp_sub(gmp_mul($lam, gmp_sub($p[0], $rx)), $p[1]), $mod);
        return [$rx, $ry];
    }

    /**
     * @param array{0:\GMP,1:\GMP} $point
     * @return ?array{0:\GMP,1:\GMP}
     */
    private static function scalarMul(\GMP $k, array $point): ?array
    {
        if (gmp_cmp($k, 0) === 0) {
            return null;
        }
        $res = null;
        $addend = $point;
        $bits = gmp_strval($k, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                $res = self::pointAdd($res, $addend);
            }
            $addend = self::pointAdd($addend, $addend);
        }
        return $res;
    }
}

function gmp_pos_mod(\GMP $a, \GMP $m): \GMP
{
    $r = gmp_mod($a, $m);
    return gmp_cmp($r, 0) < 0 ? gmp_add($r, $m) : $r;
}
