<?php
declare(strict_types=1);

/**
 * Traffic-flow (IPFIX) – nfcapd na strežniku vsakih 5 min zapiše datoteko, cron/flows.php jo prebere z nfdump
 * in sešteje promet po napravah v LAN-u: izvor v LAN = upload, cilj v LAN = download.
 */
final class Flows
{
    public static function process(): string
    {
        $dir = rtrim((string)cfg('flow_dir'), '/');
        if (!is_dir($dir)) return 'flow_dir ne obstaja';
        $last = setting('flow_last', '');
        $files = glob($dir . '/nfcapd.[0-9]*') ?: [];
        sort($files);
        $devs = db()->query('SELECT * FROM devices WHERE active=1 AND flow_enabled=1')->fetchAll();
        $byIp = [];
        foreach ($devs as $d) {
            $d['_nets'] = Devices::list((string)$d['lan_networks']);
            if (!$d['_nets']) continue;
            foreach ([$d['public_ip'], $d['last_seen_ip']] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP)) $byIp[$ip] = $d;
        }
        $done = 0;
        foreach ($files as $f) {
            $base = basename($f);
            if (strcmp($base, $last) <= 0 || !preg_match('/^nfcapd\.(\d{12})$/', $base, $m)) continue;
            self::file($f, $byIp, DateTime::createFromFormat('YmdHi', $m[1])->format('Y-m-d H:i:00'));
            $last = $base; $done++;
        }
        if ($done) setting_set('flow_last', $last);
        foreach ($files as $f) if (filemtime($f) < time() - 2 * 86400) @unlink($f);   // surove tokove hranimo 2 dni
        if ($done) self::hostAlerts($devs);
        return "datotek: $done";
    }

    private static function file(string $f, array $byIp, string $slot): void
    {
        $cmd = escapeshellcmd((string)cfg('nfdump', '/usr/bin/nfdump')) . ' -r ' . escapeshellarg($f) . ' -q -N -o ' . escapeshellarg('fmt:%ra|%sa|%da|%xsa|%xda|%byt');
        $h = popen($cmd, 'r'); if (!$h) return;
        $agg = [];   // [device_id][ip] = [up, down]
        $netCache = [];
        while (($line = fgets($h)) !== false) {
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 6) continue;
            [$ra, $sa, $da, $xsa, $xda, $byt] = $p;
            $d = $byIp[$ra] ?? null; if (!$d) continue;
            $id = (int)$d['id'];
            $inS = $netCache[$id][$sa] ??= self::inNets($sa, $d['_nets']);
            $inD = $netCache[$id][$da] ??= self::inNets($da, $d['_nets']);
            if (!$inD && $xda !== '0.0.0.0' && $xda !== '') { $inD = $netCache[$id][$xda] ??= self::inNets($xda, $d['_nets']); if ($inD) $da = $xda; }
            if ($inS && !$inD) { $agg[$id][$sa][0] = ($agg[$id][$sa][0] ?? 0) + (int)$byt; }
            elseif ($inD && !$inS) { $agg[$id][$da][1] = ($agg[$id][$da][1] ?? 0) + (int)$byt; }
        }
        pclose($h);
        $ins = db()->prepare('INSERT INTO host_5m (device_id, ip, ts, up_bytes, down_bytes) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE up_bytes=up_bytes+VALUES(up_bytes), down_bytes=down_bytes+VALUES(down_bytes)');
        foreach ($agg as $id => $hosts) foreach ($hosts as $ip => $v) $ins->execute([$id, $ip, $slot, $v[0] ?? 0, $v[1] ?? 0]);
    }

    private static function inNets(string $ip, array $nets): bool
    {
        foreach ($nets as $n) if (ip_in_cidr($ip, $n)) return true;
        return false;
    }

    /** Alarm za napravo v LAN-u z nenavadno velikim prometom */
    private static function hostAlerts(array $devs): void
    {
        foreach ($devs as $d) {
            $d = Devices::decorate($d); $th = Devices::thresholds($d); $id = (int)$d['id'];
            $gb = (float)$th['th_host_gb_h']; $mbps = (float)$th['th_host_mbps']; $min = max(5, (int)$th['th_host_min']);
            $st = db()->prepare('SELECT h.ip, SUM(h.up_bytes + h.down_bytes) tot, SUM(IF(h.ts > NOW() - INTERVAL ? MINUTE, h.up_bytes + h.down_bytes, 0)) recent,
                                        (SELECT COALESCE(NULLIF(label,""), hostname) FROM lan_hosts l WHERE l.device_id=h.device_id AND l.ip=h.ip ORDER BY last_seen DESC LIMIT 1) name
                                 FROM host_5m h WHERE h.device_id=? AND h.ts > NOW() - INTERVAL 1 HOUR GROUP BY h.ip');
            $st->execute([$min + 5, $id]);
            $hot = [];
            foreach ($st as $r) {
                $who = $r['ip'] . ($r['name'] ? ' (' . $r['name'] . ')' : '');
                $avg = $r['recent'] * 8 / ($min * 60);
                if ($gb > 0 && $r['tot'] >= $gb * 1e9) $hot[$r['ip']] = A('Naprava {h} je v zadnji uri prenesla {b}', ['h' => $who, 'b' => fmt_bytes($r['tot'])]);
                elseif ($mbps > 0 && $avg >= $mbps * 1e6) $hot[$r['ip']] = A('Naprava {h} povprečno {r} zadnjih {m} min', ['h' => $who, 'r' => fmt_bps($avg), 'm' => $min]);
            }
            foreach ($hot as $ip => $msg) Alerts::raise($d, 'host:' . $ip, 'warning', $msg);
            $open = db()->prepare('SELECT akey FROM alerts WHERE device_id=? AND akey LIKE "host:%" AND ended_at IS NULL'); $open->execute([$id]);
            foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $k) if (!isset($hot[substr($k, 5)])) Alerts::clear($d, $k);
        }
    }

    /** Top naprave v LAN-u za obdobje */
    public static function top(int $deviceId, string $range, int $limit = 25): array
    {
        $hours = ['1h' => 1, '24h' => 24, '7d' => 168][$range] ?? 24;
        if ($range === '30d') {
            $st = db()->prepare('SELECT ip, SUM(up_bytes) up, SUM(down_bytes) down FROM host_daily WHERE device_id=? AND day > CURDATE() - INTERVAL 30 DAY GROUP BY ip
                                 UNION ALL SELECT ip, SUM(up_bytes), SUM(down_bytes) FROM host_5m WHERE device_id=? AND ts >= CURDATE() GROUP BY ip');
            $st->execute([$deviceId, $deviceId]);
            $sum = [];
            foreach ($st as $r) { $sum[$r['ip']]['up'] = ($sum[$r['ip']]['up'] ?? 0) + $r['up']; $sum[$r['ip']]['down'] = ($sum[$r['ip']]['down'] ?? 0) + $r['down']; }
            $rows = []; foreach ($sum as $ip => $v) $rows[] = ['ip' => $ip, 'up' => $v['up'], 'down' => $v['down']];
        } else {
            $st = db()->prepare('SELECT ip, SUM(up_bytes) up, SUM(down_bytes) down FROM host_5m WHERE device_id=? AND ts > NOW() - INTERVAL ? HOUR GROUP BY ip');
            $st->execute([$deviceId, $hours]); $rows = $st->fetchAll();
        }
        usort($rows, fn($a, $b) => ($b['up'] + $b['down']) <=> ($a['up'] + $a['down']));
        $rows = array_slice($rows, 0, $limit);
        $names = db()->prepare('SELECT mac, hostname, label, last_seen FROM lan_hosts WHERE device_id=? AND ip=? ORDER BY last_seen DESC LIMIT 1');
        foreach ($rows as &$r) { $names->execute([$deviceId, $r['ip']]); $r += $names->fetch() ?: ['mac' => '', 'hostname' => '', 'label' => '']; }
        return $rows;
    }
}
