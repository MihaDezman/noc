<?php
declare(strict_types=1);

/**
 * Razpoložljivost (SLA) iz zgodovine alarmov: izpad = naprava nedosegljiva (offline) ali internet ne deluje (wan_down).
 * Prekrivajoči se intervali se združijo; šteje samo čas od dodajanja naprave naprej.
 */
final class Sla
{
    public const KEYS = ['offline', 'wan_down'];

    /** [['from' => ts, 'to' => ts, 'key' => ..., 'msg' => ...]] – izpadi, ki se dotikajo obdobja */
    public static function outages(int $id, int $from, int $to): array
    {
        $in = implode(',', array_fill(0, count(self::KEYS), '?'));
        $st = db()->prepare("SELECT akey, message, UNIX_TIMESTAMP(started_at) s, UNIX_TIMESTAMP(COALESCE(ended_at, NOW())) e FROM alerts
                             WHERE device_id=? AND akey IN ($in) AND started_at < FROM_UNIXTIME(?) AND COALESCE(ended_at, NOW()) > FROM_UNIXTIME(?) ORDER BY started_at");
        $st->execute(array_merge([$id], self::KEYS, [$to, $from]));
        $out = [];
        foreach ($st as $r) {
            // alarm se odpre šele po pragu (npr. 3 min brez pusha) – izpad je začel toliko prej
            $start = (int)$r['s'] - ($r['akey'] === 'offline' ? (int)setting('th_offline_min', '3') * 60 : 0);
            $out[] = ['from' => max($from, $start), 'to' => min($to, (int)$r['e']), 'key' => $r['akey'], 'msg' => $r['message'], 'open' => (int)$r['e'] >= time() - 60];
        }
        return $out;
    }

    /** Skupni čas izpada (združeni intervali) v sekundah */
    private static function downSeconds(array $outs): int
    {
        usort($outs, fn($a, $b) => $a['from'] <=> $b['from']);
        $sum = 0; $cs = null; $ce = null;
        foreach ($outs as $o) {
            if ($o['to'] <= $o['from']) continue;
            if ($cs === null || $o['from'] > $ce) { if ($cs !== null) $sum += $ce - $cs; $cs = $o['from']; $ce = $o['to']; }
            else $ce = max($ce, $o['to']);
        }
        if ($cs !== null) $sum += $ce - $cs;
        return $sum;
    }

    /** Povzetek za obdobje: pct (null = ni podatkov), down_s, mon_s, outages */
    public static function period(array $d, int $from, int $to): array
    {
        $to = min($to, time());
        $start = max($from, strtotime((string)$d['created_at']));
        if ($to <= $start) return ['pct' => null, 'down_s' => 0, 'mon_s' => 0, 'outages' => [], 'count' => 0];
        $outs = self::outages((int)$d['id'], $start, $to);
        $down = self::downSeconds($outs); $mon = $to - $start;
        return ['pct' => round(max(0, 100 - $down / $mon * 100), 3), 'down_s' => $down, 'mon_s' => $mon, 'outages' => $outs, 'count' => count($outs)];
    }

    /** Dnevni odstotki za mesec "Y-m": [ 'Y-m-d' => pct|null ] */
    public static function month(array $d, string $ym): array
    {
        $first = strtotime($ym . '-01 00:00:00'); $days = (int)date('t', $first); $out = [];
        for ($i = 0; $i < $days; $i++) {
            $f = strtotime("+$i day", $first); $t = strtotime('+1 day', $f);
            $out[date('Y-m-d', $f)] = $f > time() ? null : self::period($d, $f, $t)['pct'];
        }
        return $out;
    }

    public static function monthRange(string $ym): array
    {
        $f = strtotime($ym . '-01 00:00:00');
        return [$f, strtotime('+1 month', $f)];
    }

    public static function fmt(?float $pct): string
    {
        if ($pct === null) return '–';
        return ($pct >= 99.995 ? '100' : number_format(floor($pct * 100) / 100, 2, ',', '')) . ' %';
    }

    public static function cls(?float $pct): string
    {
        return $pct === null ? '' : ($pct >= 99.9 ? 'up' : ($pct >= 99 ? 'warn' : 'down'));
    }
}
