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

    /**
     * Vrstica izvoza, ki jo upravlja NOC sam (skripte in urniki noc-*, pravila z oznako noc, seznami noc-*, beleženje
     * za varnostno analizo, cilj traffic-flow na NOC). Take spremembe nastanejo ob samodejni posodobitvi skripte
     * in niso sprememba konfiguracije stranke.
     */
    public static function isNocLine(string $l): bool
    {
        if (preg_match('#^/user\b#', ltrim($l))) return false;   // uporabniki so vedno sprememba stranke (oznaka "noc" jih ne skrije)
        $noc = preg_quote((string)cfg('noc_ip', '-'), '/');
        return (bool)preg_match('/name="?noc[-a-z]*\b|comment="?noc[-:. ]|noc\.dezman\.net|list=noc-|nocsyslog|topics=info,!fetch|dst-address=' . $noc . '\b/i', $l);
    }

    /** Spremembe brez vrstic NOC: [[op, vrstica]] */
    public static function ownChanges(string $a, string $b): array
    {
        return array_values(array_filter(self::ops(explode("\n", $a), explode("\n", $b)), fn($o) => $o[0] !== ' ' && !self::isNocLine($o[1])));
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
        $mid = self::middle($A, $B);
        $ops = [];
        for ($i = 0; $i < $pre; $i++) $ops[] = [' ', $a[$i]];
        foreach ($mid as $o) $ops[] = $o;
        for ($i = $na - $suf; $i < $na; $i++) $ops[] = [' ', $a[$i]];
        return $ops;
    }

    /**
     * Vrstice, ki jih na drugi strani sploh ni, so zagotovo dodane/odstranjene – izločimo jih pred Myersom
     * (kot GNU diff). Tako tisoč novih vnosov v seznamu naslovov ne napihne iskanja (pomnilnik raste s kvadratom razlik).
     */
    private static function middle(array $a, array $b): array
    {
        if (!$a) return array_map(fn($l) => ['+', $l], $b);
        if (!$b) return array_map(fn($l) => ['-', $l], $a);
        $inA = array_count_values($a); $inB = array_count_values($b);
        $ia = array_keys(array_filter($a, fn($l) => isset($inB[$l]))); $ib = array_keys(array_filter($b, fn($l) => isset($inA[$l])));
        $fa = array_map(fn($i) => $a[$i], $ia); $fb = array_map(fn($i) => $b[$i], $ib);
        $core = self::myers($fa, $fb);
        if ($core === null) return self::bag($a, $b);
        // nazaj v polno zaporedje: izločene vrstice vstavimo pred naslednjo vrstico iz jedra
        $ops = []; $x = 0; $y = 0; $pa = 0; $pb = 0;
        $flushA = function (int $to) use (&$x, &$ops, $a) { for (; $x < $to; $x++) $ops[] = ['-', $a[$x]]; };
        $flushB = function (int $to) use (&$y, &$ops, $b) { for (; $y < $to; $y++) $ops[] = ['+', $b[$y]]; };
        foreach ($core as [$op, $l]) {
            if ($op !== '+') { $flushA($ia[$pa]); }
            if ($op !== '-') { $flushB($ib[$pb]); }
            if ($op === ' ') { $ops[] = [' ', $l]; $x++; $y++; $pa++; $pb++; }
            elseif ($op === '-') { $ops[] = ['-', $l]; $x++; $pa++; }
            else { $ops[] = ['+', $l]; $y++; $pb++; }
        }
        $flushA(count($a)); $flushB(count($b));
        return $ops;
    }

    /** Rezerva za zelo različni datoteki: požrešno skupno zaporedje (linearno, ni nujno najkrajše, a vedno pravilno) */
    private static function bag(array $a, array $b): array
    {
        $pos = [];
        foreach ($a as $i => $l) $pos[$l][] = $i;
        $ptr = array_fill_keys(array_keys($pos), 0);
        $ops = []; $x = 0; $n = count($a);
        foreach ($b as $l) {
            if (!isset($pos[$l])) { $ops[] = ['+', $l]; continue; }
            $p = &$ptr[$l]; $list = $pos[$l];
            while ($p < count($list) && $list[$p] < $x) $p++;
            if ($p < count($list) && $list[$p] - $x <= 200) {   // ujemanje blizu: vmesne vrstice so odstranjene
                for (; $x < $list[$p]; $x++) $ops[] = ['-', $a[$x]];
                $ops[] = [' ', $l]; $x++; $p++;
            } else $ops[] = ['+', $l];
            unset($p);
        }
        for (; $x < $n; $x++) $ops[] = ['-', $a[$x]];
        return $ops;
    }

    /** Myers O(ND); null, če je razlik preveč (pomnilnik sledi raste kot D²) */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a); $m = count($b); $max = $n + $m;
        if ($n === 0) return array_map(fn($l) => ['+', $l], $b);
        if ($m === 0) return array_map(fn($l) => ['-', $l], $a);
        $v = [1 => 0]; $trace = [];
        for ($d = 0; $d <= $max; $d++) {
            if ($d > 800) return null;
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
