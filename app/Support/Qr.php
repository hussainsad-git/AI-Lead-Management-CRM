<?php

namespace App\Support;

/**
 * Minimal QR Code encoder (ISO/IEC 18004): byte mode, error-correction level M, versions 1–10,
 * automatic mask selection. Enough for otpauth:// URIs; output is an SVG string (no inline styles,
 * CSP-safe). Written in-house so no third-party runtime dependency is needed for 2FA setup.
 */
class Qr
{
    // [total codewords, EC codewords per block, [block count, data codewords per block] groups] for level M
    private const EC_M = [
        1 => [26, 10, [[1, 16]]], 2 => [44, 16, [[1, 28]]], 3 => [70, 26, [[1, 44]]], 4 => [100, 18, [[2, 32]]],
        5 => [134, 24, [[2, 43]]], 6 => [172, 16, [[4, 27]]], 7 => [196, 18, [[4, 31]]], 8 => [242, 22, [[2, 38], [2, 39]]],
        9 => [292, 22, [[3, 36], [2, 37]]], 10 => [346, 26, [[4, 43], [1, 44]]],
    ];

    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    private static array $exp = [];

    private static array $log = [];

    /** @return array<int, array<int, int>> matrix of 0/1 */
    public static function matrix(string $data): array
    {
        self::initGf();
        $len = strlen($data);
        $version = null;
        foreach (self::EC_M as $v => [$total, $ec, $groups]) {
            $dataCw = array_sum(array_map(fn ($g) => $g[0] * $g[1], $groups));
            $ccBits = $v < 10 ? 8 : 16;
            if ((4 + $ccBits + $len * 8 + 7) >> 3 <= $dataCw) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException('Data too long for QR versions 1-10.');
        }
        [$total, $ecPerBlock, $groups] = self::EC_M[$version];
        $dataCw = array_sum(array_map(fn ($g) => $g[0] * $g[1], $groups));

        // --- bit stream ---
        $bits = '0100'.str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $pads = [0xEC, 0x11];
        for ($i = 0; strlen($bits) < $dataCw * 8; $i++) {
            $bits .= str_pad(decbin($pads[$i % 2]), 8, '0', STR_PAD_LEFT);
        }
        $codewords = array_map('bindec', str_split($bits, 8));

        // --- blocks + Reed-Solomon ---
        $blocks = [];
        $pos = 0;
        foreach ($groups as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $blk = array_slice($codewords, $pos, $size);
                $pos += $size;
                $blocks[] = ['d' => $blk, 'e' => self::rs($blk, $ecPerBlock)];
            }
        }
        $out = [];
        $maxD = max(array_map(fn ($b) => count($b['d']), $blocks));
        for ($i = 0; $i < $maxD; $i++) {
            foreach ($blocks as $b) {
                if (isset($b['d'][$i])) {
                    $out[] = $b['d'][$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b['e'][$i];
            }
        }
        $stream = '';
        foreach ($out as $cw) {
            $stream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // --- matrix ---
        $size = 17 + 4 * $version;
        $m = array_fill(0, $size, array_fill(0, $size, null));   // null = free, else 0/1 (function patterns)
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        $place = function (int $r, int $c, int $v) use (&$m, &$reserved) {
            $m[$r][$c] = $v;
            $reserved[$r][$c] = true;
        };
        $finder = function (int $r0, int $c0) use ($place, $size) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $r0 + $r;
                    $cc = $c0 + $c;
                    if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                        continue;
                    }
                    $on = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                    $place($rr, $cc, $on ? 1 : 0);
                }
            }
        };
        $finder(0, 0);
        $finder(0, $size - 7);
        $finder($size - 7, 0);
        $al = self::ALIGN[$version];
        foreach ($al as $ar) {
            foreach ($al as $ac) {
                if ($reserved[$ar][$ac]) {
                    continue; // overlaps a finder
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $place($ar + $r, $ac + $c, (max(abs($r), abs($c)) === 1) ? 0 : 1);
                    }
                }
            }
        }
        for ($i = 8; $i < $size - 8; $i++) { // timing
            $place(6, $i, $i % 2 === 0 ? 1 : 0);
            $place($i, 6, $i % 2 === 0 ? 1 : 0);
        }
        $place($size - 8, 8, 1); // dark module
        for ($i = 0; $i < 9; $i++) { // reserve format areas
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
        if ($version >= 7) { // reserve version areas
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$i][$size - 11 + $j] = true;
                    $reserved[$size - 11 + $j][$i] = true;
                }
            }
        }
        // data placement (zig-zag), remember which cells carry data for masking
        $isData = array_fill(0, $size, array_fill(0, $size, false));
        $idx = 0;
        $n = strlen($stream);
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2, $upward = ! $upward) {
            if ($col === 6) {
                $col--;
            }
            for ($k = 0; $k < $size; $k++) {
                $row = $upward ? $size - 1 - $k : $k;
                foreach ([$col, $col - 1] as $c) {
                    if ($reserved[$row][$c]) {
                        continue;
                    }
                    $m[$row][$c] = $idx < $n ? (int) $stream[$idx] : 0;
                    $isData[$row][$c] = true;
                    $idx++;
                }
            }
        }

        // --- mask selection ---
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $t = $m;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if ($isData[$r][$c] && self::maskBit($mask, $r, $c)) {
                        $t[$r][$c] ^= 1;
                    }
                }
            }
            self::writeFormat($t, $size, $mask);
            if ($version >= 7) {
                self::writeVersion($t, $size, $version);
            }
            $score = self::penalty($t, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $t;
            }
        }

        return $best;
    }

    public static function svg(string $data, int $module = 4, int $quiet = 4): string
    {
        $m = self::matrix($data);
        $n = count($m);
        $dim = ($n + 2 * $quiet) * $module;
        $rects = '';
        foreach ($m as $r => $row) {
            foreach ($row as $c => $v) {
                if ($v) {
                    $rects .= '<rect x="'.(($c + $quiet) * $module).'" y="'.(($r + $quiet) * $module).'" width="'.$module.'" height="'.$module.'"/>';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$dim.' '.$dim.'" width="'.$dim.'" height="'.$dim.'" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            .'<rect width="'.$dim.'" height="'.$dim.'" fill="#fff"/><g fill="#000">'.$rects.'</g></svg>';
    }

    // ---------------- internals ----------------

    private static function initGf(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        return $a === 0 || $b === 0 ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return int[] EC codewords */
    private static function rs(array $data, int $ec): array
    {
        $gen = [1];
        for ($i = 0; $i < $ec; $i++) { // (x - a^i)
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $next[$j] ^= $g;
                $next[$j + 1] ^= self::mul($g, self::$exp[$i]);
            }
            $gen = $next;
        }
        $msg = array_merge($data, array_fill(0, $ec, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $msg[$i];
            if ($coef === 0) {
                continue;
            }
            foreach ($gen as $j => $g) {
                $msg[$i + $j] ^= self::mul($g, $coef);
            }
        }

        return array_slice($msg, count($data));
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
            default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        };
    }

    private static function bch(int $value, int $poly, int $degree, int $totalBits): int
    {
        $v = $value << $degree;
        for ($i = $totalBits - 1; $i >= $degree; $i--) {
            if ($v & (1 << $i)) {
                $v ^= $poly << ($i - $degree);
            }
        }

        return ($value << $degree) | $v;
    }

    private static function writeFormat(array &$m, int $size, int $mask): void
    {
        $bits = self::bch((0b00 << 3) | $mask, 0x537, 10, 15) ^ 0x5412; // level M = 00
        for ($i = 0; $i < 15; $i++) {
            $b = ($bits >> (14 - $i)) & 1; // MSB first, starting at (8,0)
            // around top-left finder
            if ($i < 6) {
                $m[8][$i] = $b;
            } elseif ($i < 8) {
                $m[8][$i + 1] = $b;
            } elseif ($i === 8) {
                $m[7][8] = $b;
            } else {
                $m[14 - $i][8] = $b;
            }
            // second copy: bits 0-6 down the left of the bottom-left finder, bits 7-14 along the top-right finder
            if ($i < 7) {
                $m[$size - 1 - $i][8] = $b;
            } else {
                $m[8][$size - 15 + $i] = $b;
            }
        }
        $m[$size - 8][8] = 1;
    }

    private static function writeVersion(array &$m, int $size, int $version): void
    {
        $bits = self::bch($version, 0x1F25, 12, 18);
        for ($i = 0; $i < 18; $i++) {
            $b = ($bits >> $i) & 1;
            $m[intdiv($i, 3)][$size - 11 + $i % 3] = $b;
            $m[$size - 11 + $i % 3][intdiv($i, 3)] = $b;
        }
    }

    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        for ($pass = 0; $pass < 2; $pass++) { // rule 1 rows then columns
            for ($i = 0; $i < $n; $i++) {
                $run = 0;
                $prev = -1;
                for ($j = 0; $j < $n; $j++) {
                    $v = $pass ? $m[$j][$i] : $m[$i][$j];
                    if ($v === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + $run - 5;
                        }
                        $run = 1;
                        $prev = $v;
                    }
                }
                if ($run >= 5) {
                    $score += 3 + $run - 5;
                }
            }
        }
        for ($r = 0; $r < $n - 1; $r++) { // rule 2
            for ($c = 0; $c < $n - 1; $c++) {
                if ($m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = array_reverse($p1);
        for ($r = 0; $r < $n; $r++) { // rule 3
            for ($c = 0; $c <= $n - 11; $c++) {
                $row = array_slice($m[$r], $c, 11);
                $col = [];
                for ($k = 0; $k < 11; $k++) {
                    $col[] = $m[$c + $k][$r];
                }
                if ($row === $p1 || $row === $p2) {
                    $score += 40;
                }
                if ($col === $p1 || $col === $p2) {
                    $score += 40;
                }
            }
        }
        $dark = 0;
        foreach ($m as $row) {
            $dark += array_sum($row);
        }
        $pct = $dark * 100 / ($n * $n);
        $score += (int) (abs($pct - 50) / 5) * 10; // rule 4

        return $score;
    }
}
