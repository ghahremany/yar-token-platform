<?php
declare(strict_types=1);

namespace App\Crypto;

/**
 * Self-contained pure-PHP ISO/IEC 18004 QR Code SVG Generator (Versions 1..10, Level L, Byte Mode).
 * Works 100% offline with zero external libraries or CDNs — ideal for otpauth:// TOTP,
 * 0x... wallet addresses, Android Hub pairing URIs, and merchant invoices.
 */
final class QrSvg
{
    /** Total codewords & ECC codewords per single block (Level L, Versions 1..10; multi-block structure supported) */
    private const SPECS = [
        // v => [totalDataCodewords, ecPerBlock, [[numBlocks, dataPerBlock], ...], alignmentCenters]
        1  => [19,  7,  [[1, 19]], []],
        2  => [34,  10, [[1, 34]], [6, 18]],
        3  => [55,  15, [[1, 55]], [6, 22]],
        4  => [80,  20, [[1, 80]], [6, 26]],
        5  => [108, 26, [[1, 108]], [6, 30]],
        6  => [136, 18, [[2, 68]], [6, 34]],
        7  => [156, 20, [[2, 78]], [6, 22, 38]],
        8  => [194, 24, [[2, 97]], [6, 24, 42]],
        9  => [232, 30, [[2, 116]], [6, 26, 46]],
        10 => [274, 18, [[2, 68], [2, 69]], [6, 28, 50]],
    ];

    public static function render(string $text, int $pxSize = 200, string $fg = '#0f172a', string $bg = '#ffffff'): string
    {
        $matrix = self::encode($text);
        $n = count($matrix);
        $quiet = 3;
        $total = $n + $quiet * 2;
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $quiet;
                    $y = $r + $quiet;
                    $path .= "M{$x},{$y}h1v1h-1z";
                }
            }
        }
        $safeFg = htmlspecialchars($fg, ENT_QUOTES, 'UTF-8');
        $safeBg = htmlspecialchars($bg, ENT_QUOTES, 'UTF-8');
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges" role="img" aria-label="QR Code"><rect width="%d" height="%d" rx="2" fill="%s"/><path d="%s" fill="%s"/></svg>',
            $pxSize,
            $pxSize,
            $total,
            $total,
            $total,
            $total,
            $safeBg,
            $path,
            $safeFg
        );
    }

    /** @return array<int,array<int,int>> */
    public static function encode(string $data): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $len = count($bytes);

        $ver = 1;
        for ($v = 1; $v <= 10; $v++) {
            $countBits = $v <= 9 ? 8 : 16;
            $cap = intdiv(self::SPECS[$v][0] * 8 - 4 - $countBits, 8);
            if ($len <= $cap) {
                $ver = $v;
                break;
            }
            if ($v === 10) {
                throw new \InvalidArgumentException('متن QR بیش از حد مجاز است.');
            }
        }

        [$totalDataCw, $ecPerBlock, $groups, $align] = self::SPECS[$ver];
        $countBits = $ver <= 9 ? 8 : 16;

        $bits = '0100' . str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $maxBits = $totalDataCw * 8;
        $bits .= str_repeat('0', min(4, $maxBits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $dataCw = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $dataCw[] = bindec(substr($bits, $i, 8));
        }
        $pads = [0xEC, 0x11];
        $pi = 0;
        while (count($dataCw) < $totalDataCw) {
            $dataCw[] = $pads[$pi % 2];
            $pi++;
        }

        // Split into blocks & compute Reed-Solomon ECC
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        $maxBlockLen = 0;
        foreach ($groups as [$numBlocks, $perBlock]) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $blk = array_slice($dataCw, $offset, $perBlock);
                $offset += $perBlock;
                $dataBlocks[] = $blk;
                $ecBlocks[] = self::rsEncode($blk, $ecPerBlock);
                if ($perBlock > $maxBlockLen) $maxBlockLen = $perBlock;
            }
        }

        $interleaved = [];
        for ($i = 0; $i < $maxBlockLen; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) $interleaved[] = $blk[$i];
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $eblk) {
                $interleaved[] = $eblk[$i];
            }
        }

        $streamBits = '';
        foreach ($interleaved as $cw) {
            $streamBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $remainder = [0, 0, 7, 7, 7, 7, 7, 0, 0, 0, 0][$ver];
        $streamBits .= str_repeat('0', $remainder);

        // Build matrix
        $size = 17 + 4 * $ver;
        $mat = array_fill(0, $size, array_fill(0, $size, 0));
        $func = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($mat, $func, 0, 0, $size);
        self::placeFinder($mat, $func, $size - 7, 0, $size);
        self::placeFinder($mat, $func, 0, $size - 7, $size);

        // Alignment patterns
        $ac = count($align);
        for ($i = 0; $i < $ac; $i++) {
            for ($j = 0; $j < $ac; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $ac - 1) || ($i === $ac - 1 && $j === 0)) {
                    continue;
                }
                self::placeAlignment($mat, $func, $align[$i], $align[$j]);
            }
        }

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $v = ($i % 2 === 0) ? 1 : 0;
            if (!$func[6][$i]) { $mat[6][$i] = $v; $func[6][$i] = true; }
            if (!$func[$i][6]) { $mat[$i][6] = $v; $func[$i][6] = true; }
        }

        // Dark module & reserve format info areas
        $mat[4 * $ver + 9][8] = 1;
        $func[4 * $ver + 9][8] = true;
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $func[8][$i] = true;
                $func[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $func[8][$size - 1 - $i] = true;
            $func[$size - 1 - $i][8] = true;
        }
        if ($ver >= 7) {
            self::placeVersionInfo($mat, $func, $ver, $size);
        }

        // Zigzag data placement
        $bitIdx = 0;
        $up = true;
        for ($col = $size - 1; $col >= 1; $col -= 2) {
            if ($col === 6) $col = 5;
            for ($cnt = 0; $cnt < $size; $cnt++) {
                $row = $up ? ($size - 1 - $cnt) : $cnt;
                for ($dc = 0; $dc < 2; $dc++) {
                    $c = $col - $dc;
                    if (!$func[$row][$c]) {
                        $mat[$row][$c] = ($bitIdx < strlen($streamBits) && $streamBits[$bitIdx] === '1') ? 1 : 0;
                        $bitIdx++;
                    }
                }
            }
            $up = !$up;
        }

        // Mask selection (pick lowest penalty among masks 0..3)
        $bestMat = $mat;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 4; $mask++) {
            $cand = $mat;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if (!$func[$r][$c] && self::maskCond($mask, $r, $c)) {
                        $cand[$r][$c] ^= 1;
                    }
                }
            }
            self::placeFormatInfo($cand, $mask, $size);
            $score = self::penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMat = $cand;
            }
        }

        return $bestMat;
    }

    private static function maskCond(int $m, int $r, int $c): bool
    {
        return match ($m) {
            0 => (($r + $c) % 2) === 0,
            1 => ($r % 2) === 0,
            2 => ($c % 3) === 0,
            default => (($r + $c) % 3) === 0,
        };
    }

    /** @param array<int,int> $data @return array<int,int> */
    private static function rsEncode(array $data, int $ecCount): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x >= 256) $x ^= 0x11d;
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
        }
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            for ($j = 0, $gn = count($gen); $j < $gn; $j++) {
                $next[$j] ^= $gen[$j];
                if ($gen[$j] !== 0) {
                    $next[$j + 1] ^= $exp[$log[$gen[$j]] + $i];
                }
            }
            $gen = $next;
        }
        $res = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($res);
            $res[] = 0;
            if ($factor !== 0) {
                for ($j = 0; $j < $ecCount; $j++) {
                    if ($gen[$j + 1] !== 0) {
                        $res[$j] ^= $exp[$log[$gen[$j + 1]] + $log[$factor]];
                    }
                }
            }
        }
        return $res;
    }

    private static function placeFinder(array &$mat, array &$func, int $top, int $left, int $size): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $top + $dr;
                $c = $left + $dc;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) continue;
                $on = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6)
                    && ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6 || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                $mat[$r][$c] = $on ? 1 : 0;
                $func[$r][$c] = true;
            }
        }
    }

    private static function placeAlignment(array &$mat, array &$func, int $cr, int $cc): void
    {
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $on = max(abs($dr), abs($dc)) !== 1;
                $mat[$cr + $dr][$cc + $dc] = $on ? 1 : 0;
                $func[$cr + $dr][$cc + $dc] = true;
            }
        }
    }

    private static function placeVersionInfo(array &$mat, array &$func, int $ver, int $size): void
    {
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $b = ($bits >> $i) & 1;
            $a = $size - 11 + ($i % 3);
            $d = intdiv($i, 3);
            $mat[$a][$d] = $b; $func[$a][$d] = true;
            $mat[$d][$a] = $b; $func[$d][$a] = true;
        }
    }

    private static function placeFormatInfo(array &$mat, int $mask, int $size): void
    {
        // Level L = 01
        $data = (0b01 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) $mat[8][$i] = ($bits >> $i) & 1;
        $mat[8][7] = ($bits >> 6) & 1;
        $mat[8][8] = ($bits >> 7) & 1;
        $mat[7][8] = ($bits >> 8) & 1;
        for ($i = 9; $i < 15; $i++) $mat[14 - $i][8] = ($bits >> $i) & 1;

        for ($i = 0; $i < 8; $i++) $mat[8][$size - 1 - $i] = ($bits >> $i) & 1;
        for ($i = 8; $i < 15; $i++) $mat[$size - 15 + $i][8] = ($bits >> $i) & 1;
        $mat[$size - 8][8] = 1;
    }

    private static function penalty(array $mat, int $size): int
    {
        $score = 0;
        for ($r = 0; $r < $size; $r++) {
            $runH = 1; $runV = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($mat[$r][$c] === $mat[$r][$c - 1]) {
                    $runH++;
                    if ($runH === 5) $score += 3; elseif ($runH > 5) $score++;
                } else {
                    $runH = 1;
                }
                if ($mat[$c][$r] === $mat[$c - 1][$r]) {
                    $runV++;
                    if ($runV === 5) $score += 3; elseif ($runV > 5) $score++;
                } else {
                    $runV = 1;
                }
            }
        }
        return $score;
    }
}
