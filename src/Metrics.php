<?php
declare(strict_types=1);

/** Serije za grafe (uPlot: [[t…], [y…], …]) in dnevno stiskanje/brisanje */
final class Metrics
{
    public const RANGES = ['6h' => 6, '24h' => 24, '48h' => 48, '7d' => 168, '30d' => 720, '12m' => 8784];

    /** [t[], rx_bps[], tx_bps[]] */
    public static function traffic(int $id, string $iface, string $range): array
    {
        $h = self::RANGES[$range] ?? 24; $pdo = db();
        if ($h <= 48) {
            $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(ts) t, rx_bps r, tx_bps x FROM iface_1m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL ? HOUR ORDER BY ts');
            $st->execute([$id, $iface, $h]);
        } elseif ($h <= 168) {
            $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(ts) t, ROUND(rx_bytes*8/GREATEST(secs,60)) r, ROUND(tx_bytes*8/GREATEST(secs,60)) x FROM iface_5m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL ? HOUR ORDER BY ts');
            $st->execute([$id, $iface, $h]);
        } elseif ($h <= 720) {
            $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(DATE_FORMAT(ts, "%Y-%m-%d %H:00:00")) t, ROUND(SUM(rx_bytes)*8/GREATEST(SUM(secs),300)) r, ROUND(SUM(tx_bytes)*8/GREATEST(SUM(secs),300)) x FROM iface_5m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL ? HOUR GROUP BY t ORDER BY t');
            $st->execute([$id, $iface, $h]);
        } else {
            $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(day) t, ROUND(rx_bytes*8/86400) r, ROUND(tx_bytes*8/86400) x FROM iface_daily WHERE device_id=? AND iface=? AND day > CURDATE() - INTERVAL 366 DAY ORDER BY day');
            $st->execute([$id, $iface]);
        }
        return self::cols($st->fetchAll(PDO::FETCH_NUM), 3);
    }

    /** Količina prenosa (bajti) za kartico: danes, ta mesec */
    public static function volume(int $id, string $iface): array
    {
        $st = db()->prepare('SELECT COALESCE(SUM(rx_bytes),0) rx, COALESCE(SUM(tx_bytes),0) tx FROM iface_5m WHERE device_id=? AND iface=? AND ts >= CURDATE()');
        $st->execute([$id, $iface]); $today = $st->fetch();
        $st = db()->prepare('SELECT COALESCE(SUM(rx_bytes),0) rx, COALESCE(SUM(tx_bytes),0) tx FROM iface_daily WHERE device_id=? AND iface=? AND day >= DATE_FORMAT(CURDATE(), "%Y-%m-01")');
        $st->execute([$id, $iface]); $month = $st->fetch();
        return ['today' => $today, 'month' => ['rx' => $month['rx'] + $today['rx'], 'tx' => $month['tx'] + $today['tx']]];
    }

    /** kind: cpu | ping | temp | conns */
    public static function health(int $id, string $kind, string $range): array
    {
        $h = min(self::RANGES[$range] ?? 24, 720); $pdo = db();
        $short = $h <= 48;
        $cols = match ($kind) {
            'cpu'   => $short ? 'cpu, mem_pct' : 'cpu_avg, mem_pct',
            'ping'  => $short ? 'gw_ms, ext_ms, gw_loss, ext_loss' : 'gw_ms, ext_ms, gw_loss, ext_loss, tiger_ms',
            'temp'  => $short ? 'temp' : 'temp_max',
            'conns' => $short ? 'conns' : 'conns_max',
            default => 'cpu',
        };
        $tbl = $short ? 'health_1m' : 'health_5m';
        $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(ts) t, $cols FROM $tbl WHERE device_id=? AND ts > NOW() - INTERVAL ? HOUR ORDER BY ts");
        $st->execute([$id, $h]);
        $rows = $st->fetchAll(PDO::FETCH_NUM);
        if ($kind === 'temp' && !$short) $rows = array_map(fn($r) => [$r[0], $r[1] !== null && (float)$r[1] > -99 ? $r[1] : null], $rows);
        return self::cols($rows, count($rows[0] ?? []) ?: 2);
    }

    /** Vrstice -> stolpci, števila kot float/null */
    private static function cols(array $rows, int $n): array
    {
        $out = array_fill(0, $n, []);
        foreach ($rows as $r) for ($i = 0; $i < $n; $i++) $out[$i][] = ($r[$i] ?? null) === null ? null : +$r[$i];
        return $out;
    }

    /** Vsako noč: dnevni povzetki in brisanje starih podatkov */
    public static function rollup(): void
    {
        $pdo = db();
        $pdo->exec('INSERT INTO iface_daily (device_id, iface, day, rx_bytes, tx_bytes, rx_peak, tx_peak)
                    SELECT device_id, iface, DATE(ts), SUM(rx_bytes), SUM(tx_bytes), MAX(rx_peak), MAX(tx_peak) FROM iface_5m
                    WHERE ts >= CURDATE() - INTERVAL 3 DAY AND ts < CURDATE() GROUP BY device_id, iface, DATE(ts)
                    ON DUPLICATE KEY UPDATE rx_bytes=VALUES(rx_bytes), tx_bytes=VALUES(tx_bytes), rx_peak=VALUES(rx_peak), tx_peak=VALUES(tx_peak)');
        $pdo->exec('INSERT INTO host_daily (device_id, ip, day, up_bytes, down_bytes)
                    SELECT device_id, ip, DATE(ts), SUM(up_bytes), SUM(down_bytes) FROM host_5m
                    WHERE ts >= CURDATE() - INTERVAL 3 DAY AND ts < CURDATE() GROUP BY device_id, ip, DATE(ts)
                    ON DUPLICATE KEY UPDATE up_bytes=VALUES(up_bytes), down_bytes=VALUES(down_bytes)');
        $pdo->exec('DELETE FROM iface_1m WHERE ts < NOW() - INTERVAL 48 HOUR');
        $pdo->exec('DELETE FROM health_1m WHERE ts < NOW() - INTERVAL 48 HOUR');
        $pdo->exec('DELETE FROM iface_5m WHERE ts < NOW() - INTERVAL 35 DAY');
        $pdo->exec('DELETE FROM health_5m WHERE ts < NOW() - INTERVAL 35 DAY');
        $pdo->exec('DELETE FROM host_5m WHERE ts < NOW() - INTERVAL 8 DAY');
        $pdo->exec('DELETE FROM iface_daily WHERE day < CURDATE() - INTERVAL 400 DAY');
        $pdo->exec('DELETE FROM host_daily WHERE day < CURDATE() - INTERVAL 400 DAY');
        $pdo->exec('DELETE FROM logs WHERE ts < NOW() - INTERVAL 90 DAY');
        $pdo->exec('DELETE FROM alerts WHERE ended_at < NOW() - INTERVAL 365 DAY');
        $pdo->exec('DELETE FROM lan_hosts WHERE last_seen < NOW() - INTERVAL 180 DAY');
        $pdo->exec('DELETE FROM login_attempts WHERE at < NOW() - INTERVAL 7 DAY');
        $pdo->exec('DELETE FROM backup_parts WHERE at < NOW() - INTERVAL 1 DAY');
        // backupi: zadnjih 60 sprememb na napravo
        foreach ($pdo->query('SELECT DISTINCT device_id FROM config_backups')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->exec('DELETE FROM config_backups WHERE device_id=' . (int)$id . ' AND id NOT IN (SELECT id FROM (SELECT id FROM config_backups WHERE device_id=' . (int)$id . ' ORDER BY created_at DESC LIMIT 60) x)');
        }
    }
}
