<?php
declare(strict_types=1);

/** Primerjava konfiguracij (vrstice, Myers O(ND)) */
final class Diff
{
    /** Odstrani vrstice, ki se spreminjajo vsak dan (datum v glavi exporta) */
    public static function normalize(string $s): string
    {
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $s)) as $l) {
            if (preg_match('/^# \S+ \S+ by RouterOS/', $l) || preg_match('/^# (\w{3}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2}) /', $l)) continue;
            $out[] = rtrim($l);
        }
        return trim(implode("\n", $out));
    }

    /** [dodanih, odstranjenih] */
    public static function count(string $a, string $b): array
    {
        $add = 0; $rem = 0;
        foreach (self::ops(explode("\n", $a), explode("\n", $b)) as [$op]) { if ($op === '+') $add++; elseif ($op === '-') $rem++; }
        return [$add, $rem];
    }

    /** [[op, vrstica]], op: ' ', '+', '-' */
    public static function ops(array $a, array $b): array
    {
        // skupni začetek in konec
        $pre = 0; $na = count($a); $nb = count($b);
        while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) $pre++;
        $suf = 0;
        while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) $suf++;
        $A = array_slice($a, $pre, $na - $pre - $suf); $B = array_slice($b, $pre, $nb - $pre - $suf);
        $mid = self::myers($A, $B);
        $ops = [];
        for ($i = 0; $i < $pre; $i++) $ops[] = [' ', $a[$i]];
        foreach ($mid as $o) $ops[] = $o;
        for ($i = $na - $suf; $i < $na; $i++) $ops[] = [' ', $a[$i]];
        return $ops;
    }

    private static function myers(array $a, array $b): array
    {
        $n = count($a); $m = count($b); $max = $n + $m;
        if ($n === 0) return array_map(fn($l) => ['+', $l], $b);
        if ($m === 0) return array_map(fn($l) => ['-', $l], $a);
        if ($max > 20000) {   // zelo različni: preprost prikaz
            return array_merge(array_map(fn($l) => ['-', $l], $a), array_map(fn($l) => ['+', $l], $b));
        }
        $v = [1 => 0]; $trace = [];
        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) { $x++; $y++; }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) return self::backtrack($trace, $a, $b, $d);
            }
        }
        return [];
    }

    private static function backtrack(array $trace, array $a, array $b, int $dEnd): array
    {
        $x = count($a); $y = count($b); $ops = [];
        for ($d = $dEnd; $d > 0; $d--) {
            $v = $trace[$d]; $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0; $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) { $ops[] = [' ', $a[$x - 1]]; $x--; $y--; }
            if ($x === $prevX) $ops[] = ['+', $b[$y - 1]]; else $ops[] = ['-', $a[$x - 1]];
            $x = $prevX; $y = $prevY;
        }
        while ($x > 0 && $y > 0) { $ops[] = [' ', $a[$x - 1]]; $x--; $y--; }
        return array_reverse($ops);
    }

    /** Za prikaz: spremembe s 3 vrsticami konteksta, skrite dolge nespremenjene dele */
    public static function hunks(array $ops, int $ctx = 3): array
    {
        $keep = [];
        foreach ($ops as $i => [$op]) if ($op !== ' ') for ($j = max(0, $i - $ctx); $j <= min(count($ops) - 1, $i + $ctx); $j++) $keep[$j] = true;
        $out = []; $skipped = 0;
        foreach ($ops as $i => $o) {
            if (isset($keep[$i])) { if ($skipped) { $out[] = ['…', $skipped]; $skipped = 0; } $out[] = $o; }
            else $skipped++;
        }
        if ($skipped) $out[] = ['…', $skipped];
        return $out;
    }
}
