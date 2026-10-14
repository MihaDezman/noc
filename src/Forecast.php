<?php
declare(strict_types=1);

/**
 * Napovedi iz trendov (linearna regresija dnevnih vrednosti zadnjih 14 dni): kdaj bo disk poln, kdaj bo SFP signal
 * padel pod kritično mejo, kdaj bo IP pool zaseden, ali pomnilnik počasi narašča. Brez AI – AI jih samo ubesedi.
 */
final class Forecast
{
    /** [[vrsta, opis, dni, resnost]] za napravo; samo napovedi v naslednjih 60 dneh */
    public static function forDevice(array $d): array
    {
        $id = (int)$d['id']; $out = [];
        $daily = function (string $expr, string $table = 'health_5m', string $extra = '', array $p = []) use ($id): array {
            $st = db()->prepare("SELECT DATEDIFF(DATE(ts), CURDATE()) x, $expr y FROM $table WHERE device_id=? $extra AND ts > NOW() - INTERVAL 14 DAY GROUP BY DATE(ts) HAVING y IS NOT NULL ORDER BY x");
            $st->execute(array_merge([$id], $p));
            return array_map(fn($r) => [(float)$r['x'], (float)$r['y']], $st->fetchAll());
        };
        // disk do 100 %
        if (!Alerts::isOff($d, 'hdd') && ($f = self::fit($daily('MAX(hdd_pct)'))) && $f['slope'] > 0.05 && $f['now'] < 100) {
            $days = (100 - $f['now']) / $f['slope'];
            if ($days <= 60) $out[] = ['hdd', A('Disk bo poln čez ~{d} dni (zdaj {p} %, +{s} % na dan)', ['d' => self::days($days), 'p' => round($f['now']), 's' => self::n($f['slope'], 2)]), $days, $days <= 14 ? 'warning' : 'info'];
        }
        // pomnilnik počasi narašča (možno puščanje) do 95 %
        if (!Alerts::isOff($d, 'mem') && ($f = self::fit($daily('AVG(mem_pct)'))) && $f['slope'] > 0.3 && $f['now'] < 95) {
            $days = (95 - $f['now']) / $f['slope'];
            if ($days <= 60) $out[] = ['mem', A('Pomnilnik vztrajno narašča (+{s} % na dan) – 95 % čez ~{d} dni', ['s' => self::n($f['slope'], 1), 'd' => self::days($days)]), $days, $days <= 14 ? 'warning' : 'info'];
        }
        // IP pool
        if (($f = self::fit($daily('MAX(pool_pct)'))) && $f['slope'] > 0.2 && $f['now'] < 100) {
            $days = (100 - $f['now']) / $f['slope'];
            if ($days <= 60) $out[] = ['pool', A('IP pool bo zaseden čez ~{d} dni (zdaj {p} %)', ['d' => self::days($days), 'p' => round($f['now'])]), $days, $days <= 14 ? 'warning' : 'info'];
        }
        // SFP RX do kritične meje
        $st = db()->prepare('SELECT * FROM sfp_state WHERE device_id=? AND present=1'); $st->execute([$id]);
        foreach ($st->fetchAll() as $s) {
            $pts = $daily('AVG(rx)', 'sfp_5m', 'AND iface=?', [$s['iface']]);
            if (!($f = self::fit($pts)) || $f['slope'] > -0.02) continue;
            $crit = Alerts::sfpThresholds($s)['crit'];
            if ($f['now'] <= $crit) continue;   // že pod mejo – to je alarm, ne napoved
            $days = ($f['now'] - $crit) / -$f['slope'];
            if ($days <= 60) $out[] = ['sfp:' . $s['iface'], A('SFP {i}: signal pada {s} dB na dan – kritična meja ({c} dBm) čez ~{d} dni', ['i' => $s['iface'], 's' => self::n(-$f['slope'], 2), 'c' => self::n($crit, 1), 'd' => self::days($days)]), $days, $days <= 14 ? 'warning' : 'info'];
        }
        usort($out, fn($a, $b) => $a[2] <=> $b[2]);
        return $out;
    }

    /** Linearni trend [(x dni, y)] -> ['slope' na dan, 'now' ocena za danes] ali null (premalo podatkov ali preveč šuma) */
    public static function fit(array $pts): ?array
    {
        $n = count($pts); if ($n < 5) return null;
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($pts as [$x, $y]) { $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y; }
        $den = $n * $sxx - $sx * $sx; if (abs($den) < 1e-9) return null;
        $slope = ($n * $sxy - $sx * $sy) / $den; $icpt = ($sy - $slope * $sx) / $n;
        // zanesljivost: R² vsaj 0,5 (sicer je "trend" samo nihanje)
        $my = $sy / $n; $ssTot = 0.0; $ssRes = 0.0;
        foreach ($pts as [$x, $y]) { $ssTot += ($y - $my) ** 2; $ssRes += ($y - ($icpt + $slope * $x)) ** 2; }
        if ($ssTot > 0 && 1 - $ssRes / $ssTot < 0.5) return null;
        return ['slope' => $slope, 'now' => $icpt];   // x = 0 je danes
    }

    private static function days(float $d): int { return max(1, (int)round($d)); }
    private static function n(float $v, int $dec): string { return rtrim(rtrim(number_format($v, $dec, ',', ''), '0'), ','); }
}
