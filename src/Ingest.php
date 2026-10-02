<?php
declare(strict_types=1);

/** Sprejem podatkov z naprav (push vsako minuto, backup ponoči) */
final class Ingest
{
    public static function push(array $d, array $b): array
    {
        $pdo = db(); $id = (int)$d['id']; $now = time(); $ts = date('Y-m-d H:i:00', $now);
        $pdo->beginTransaction();   // push se zapiše v celoti ali sploh ne
        try {
        $res = is_array($b['res'] ?? null) ? $b['res'] : [];
        $rb = is_array($b['rb'] ?? null) ? $b['rb'] : [];
        $ping = is_array($b['ping'] ?? null) ? $b['ping'] : [];
        $healthRaw = is_array($b['health'] ?? null) ? $b['health'] : [];
        $uptime = ros_seconds((string)($res['uptime'] ?? ''));

        // --- vmesniki: hitrosti iz razlike števcev
        $prev = [];
        $st = $pdo->prepare('SELECT name, rx_byte, tx_byte, rx_bps, tx_bps, rate, running, link_downs, link_downs_noc, UNIX_TIMESTAMP(updated_at) t FROM device_ifaces WHERE device_id=?'); $st->execute([$id]);
        foreach ($st as $r) $prev[$r['name']] = $r;
        $rates = is_array($b['rates'] ?? null) ? $b['rates'] : [];
        $monitor = Devices::list((string)$d['monitor_ifaces']);
        if ($d['wan_iface'] !== '' && !in_array($d['wan_iface'], $monitor, true)) $monitor[] = $d['wan_iface'];   // WAN ima zgodovino vedno
        $seen = []; $keepT = []; $events = []; $speedDrops = [];
        $evIfaces = array_values(array_unique(array_merge($monitor, Devices::list((string)$d['down_ifaces']))));   // dogodki za WAN in izbrane vmesnike
        $upIf = $pdo->prepare('INSERT INTO device_ifaces (device_id, name, type, comment, mac, running, disabled, rate, rx_byte, tx_byte, rx_bps, tx_bps, rx_error, tx_error, link_downs, last_up, last_down, link_downs_noc, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))
                               ON DUPLICATE KEY UPDATE type=VALUES(type), comment=VALUES(comment), mac=VALUES(mac), running=VALUES(running), disabled=VALUES(disabled), rate=VALUES(rate),
                               rx_byte=VALUES(rx_byte), tx_byte=VALUES(tx_byte), rx_bps=VALUES(rx_bps), tx_bps=VALUES(tx_bps), rx_error=VALUES(rx_error), tx_error=VALUES(tx_error), link_downs=VALUES(link_downs), last_up=VALUES(last_up), last_down=VALUES(last_down), link_downs_noc=VALUES(link_downs_noc), updated_at=VALUES(updated_at)');
        $ins1 = $pdo->prepare('INSERT IGNORE INTO iface_1m (device_id, iface, ts, rx_bps, tx_bps) VALUES (?,?,?,?,?)');
        $ins5 = $pdo->prepare('INSERT INTO iface_5m (device_id, iface, ts, rx_bytes, tx_bytes, rx_peak, tx_peak, secs) VALUES (?,?,?,?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE rx_bytes=rx_bytes+VALUES(rx_bytes), tx_bytes=tx_bytes+VALUES(tx_bytes), rx_peak=GREATEST(rx_peak, VALUES(rx_peak)), tx_peak=GREATEST(tx_peak, VALUES(tx_peak)), secs=LEAST(300, secs+VALUES(secs))');
        $slot5 = date('Y-m-d H:i:00', $now - ($now % 300));
        foreach ((array)($b['ifs'] ?? []) as $i) {
            if (!is_array($i) || empty($i['n'])) continue;
            $n = mb_substr((string)$i['n'], 0, 64); $seen[] = $n;
            $rx = (int)($i['rx'] ?? 0); $tx = (int)($i['tx'] ?? 0); $rxb = 0; $txb = 0;
            $p = $prev[$n] ?? null;
            if ($p) {
                $dt = $now - (int)$p['t']; $drx = $rx - (int)$p['rx_byte']; $dtx = $tx - (int)$p['tx_byte'];
                if ($dt >= 20 && $dt <= 600 && $drx >= 0 && $dtx >= 0) {
                    $rxb = (int)round($drx * 8 / $dt); $txb = (int)round($dtx * 8 / $dt);
                    if (in_array($n, $monitor, true)) {
                        $ins1->execute([$id, $n, $ts, $rxb, $txb]);
                        $ins5->execute([$id, $n, $slot5, $drx, $dtx, $rxb, $txb, min(300, $dt)]);
                    }
                }
            }
            if ($p && $now - (int)$p['t'] < 20 && $now - (int)$p['t'] >= 0) { $rx = (int)$p['rx_byte']; $tx = (int)$p['tx_byte']; $keepT[$n] = (int)$p['t']; $rxb = (int)$p['rx_bps']; $txb = (int)$p['tx_bps']; }
            $rate = isset($rates[$n]) ? (string)$rates[$n] : (string)($p['rate'] ?? '');
            if (empty($i['run'])) $rate = '';
            // prekinitve in hitrost: števec link-downs z routerja; ob ponovnem zagonu se postavi na 0 (to ni prekinitev)
            $ld = (int)($i['ld'] ?? 0); $ldNoc = (int)($p['link_downs_noc'] ?? 0);
            if ($p && in_array($n, $evIfaces, true)) {
                $dld = $ld - (int)$p['link_downs'];
                if ($dld > 0) { $ldNoc += $dld; $events[] = [$n, 'down', (string)$dld, self::rosTime((string)($i['ldt'] ?? ''), $now)]; }   // detail = število prekinitev
                if (!(int)$p['running'] && !empty($i['run'])) $events[] = [$n, 'up', A('povezava vzpostavljena') . ($rate ? " ($rate)" : ''), self::rosTime((string)($i['lu'] ?? ''), $now)];
                $old = self::rateMbps((string)$p['rate']); $new = self::rateMbps($rate);
                if ($old && $new && $new !== $old) { $events[] = [$n, 'speed', $p['rate'] . ' → ' . $rate, $now]; $speedDrops[$n] = [$new < $old, $p['rate'] . ' → ' . $rate]; }
            }
            $upIf->execute([$id, $n, mb_substr((string)($i['t'] ?? ''), 0, 32), mb_substr((string)($i['c'] ?? ''), 0, 255), (string)($i['mac'] ?? ''),
                !empty($i['run']) ? 1 : 0, !empty($i['dis']) ? 1 : 0, mb_substr($rate, 0, 16), $rx, $tx, $rxb, $txb,
                (int)($i['rxe'] ?? 0), (int)($i['txe'] ?? 0), (int)($i['ld'] ?? 0), mb_substr((string)($i['lu'] ?? ''), 0, 32), mb_substr((string)($i['ldt'] ?? ''), 0, 32), $ldNoc, $keepT[$n] ?? $now]);
        }
        if ($seen) {   // vmesniki, ki jih ni več
            $in = implode(',', array_fill(0, count($seen), '?'));
            $pdo->prepare("DELETE FROM device_ifaces WHERE device_id=? AND name NOT IN ($in)")->execute(array_merge([$id], $seen));
        }

        if ($events) {
            $insE = $pdo->prepare('INSERT INTO iface_events (device_id, iface, ts, kind, detail) VALUES (?,?,FROM_UNIXTIME(?),?,?)');
            foreach ($events as [$n, $k, $det, $t]) $insE->execute([$id, $n, $t, $k, mb_substr($det, 0, 255)]);
        }

        // --- SFP moduli (vsakih 5 min)
        $sfpRows = [];
        foreach ((array)($b['sfp'] ?? []) as $m) {
            if (!is_array($m) || empty($m['n'])) continue;
            try { $sfpRows[] = self::sfp($d, $m, $now); }
            catch (\Throwable $e) { error_log('noc sfp ' . $d['name'] . ' ' . ($m['n'] ?? '?') . ': ' . $e->getMessage() . ' | ' . json_encode($m, JSON_UNESCAPED_UNICODE)); }   // en modul ne sme ustaviti pusha
        }

        // --- zdravje
        $memT = (int)($res['memT'] ?? 0); $memF = (int)($res['memF'] ?? 0);
        $hddT = (int)($res['hddT'] ?? 0); $hddF = (int)($res['hddF'] ?? 0);
        [$temp, $volt] = self::sensors($healthRaw);
        [$gwMs, $gwLoss, $extMs, $extLoss] = self::pings($ping);
        $h = [
            'cpu' => isset($res['cpu']) ? (int)$res['cpu'] : null,
            'mem_pct' => $memT > 0 ? (int)round((1 - $memF / $memT) * 100) : null,
            'hdd_pct' => $hddT > 0 ? (int)round((1 - $hddF / $hddT) * 100) : null,
            'temp' => $temp, 'volt' => $volt, 'gw_ms' => $gwMs, 'gw_loss' => $gwLoss, 'ext_ms' => $extMs, 'ext_loss' => $extLoss,
            'conns' => isset($b['conns']) && (int)$b['conns'] >= 0 ? (int)$b['conns'] : null, 'leases' => isset($b['nLeases']) ? (int)$b['nLeases'] : null,
        ];
        $pdo->prepare('INSERT IGNORE INTO health_1m (device_id, ts, cpu, mem_pct, hdd_pct, temp, volt, gw_ms, gw_loss, ext_ms, ext_loss, conns, leases) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $ts, $h['cpu'], $h['mem_pct'], $h['hdd_pct'], $h['temp'], $h['volt'], $h['gw_ms'], $h['gw_loss'], $h['ext_ms'], $h['ext_loss'], $h['conns'], $h['leases']]);
        $pdo->prepare('INSERT INTO health_5m (device_id, ts, cpu_avg, cpu_max, mem_pct, temp_max, gw_ms, gw_loss, ext_ms, ext_loss, conns_max, n) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)
                       ON DUPLICATE KEY UPDATE cpu_avg=ROUND((COALESCE(cpu_avg,0)*n + COALESCE(VALUES(cpu_avg),0))/(n+1)), cpu_max=GREATEST(COALESCE(cpu_max,0), COALESCE(VALUES(cpu_max),0)),
                         mem_pct=COALESCE(VALUES(mem_pct), mem_pct), temp_max=GREATEST(COALESCE(temp_max,-99), COALESCE(VALUES(temp_max),-99)),
                         gw_ms=IF(VALUES(gw_ms) IS NULL, gw_ms, (COALESCE(gw_ms,VALUES(gw_ms))*n + VALUES(gw_ms))/(n+1)), gw_loss=ROUND((COALESCE(gw_loss,0)*n + COALESCE(VALUES(gw_loss),0))/(n+1)),
                         ext_ms=IF(VALUES(ext_ms) IS NULL, ext_ms, (COALESCE(ext_ms,VALUES(ext_ms))*n + VALUES(ext_ms))/(n+1)), ext_loss=ROUND((COALESCE(ext_loss,0)*n + COALESCE(VALUES(ext_loss),0))/(n+1)),
                         conns_max=GREATEST(COALESCE(conns_max,0), COALESCE(VALUES(conns_max),0)), n=n+1')
            ->execute([$id, $slot5, $h['cpu'], $h['cpu'], $h['mem_pct'], $h['temp'], $h['gw_ms'], $h['gw_loss'], $h['ext_ms'], $h['ext_loss'], $h['conns']]);

        // --- DHCP najemi (vsakih 5 min) + zaznava novih naprav v LAN-u
        $leases = (array)($b['leases'] ?? []);
        $newHosts = [];
        if ($leases) {
            $known = $pdo->prepare('SELECT mac FROM lan_hosts WHERE device_id=?'); $known->execute([$id]);
            $knownMacs = array_flip($known->fetchAll(PDO::FETCH_COLUMN));
            // učno obdobje: prvih 24 h po prvem najemu se nove naprave ne javljajo (sicer bi bile "nove" vse)
            $st0 = $pdo->prepare('SELECT MIN(first_seen) FROM lan_hosts WHERE device_id=?'); $st0->execute([$id]);
            $since = $st0->fetchColumn();
            $learning = !$since || strtotime((string)$since) > $now - 86400;
            $ignore = array_map('mb_strtolower', Devices::list((string)($d['newdev_ignore'] ?? '')));
            $up = $pdo->prepare('INSERT INTO lan_hosts (device_id, mac, ip, hostname, server, status, first_seen, last_seen) VALUES (?,?,?,?,?,?,NOW(),NOW())
                                 ON DUPLICATE KEY UPDATE ip=VALUES(ip), hostname=IF(VALUES(hostname)="", hostname, VALUES(hostname)), server=VALUES(server), status=VALUES(status), last_seen=NOW()');
            foreach ($leases as $l) {
                if (!is_array($l)) continue;
                $mac = normalize_mac((string)($l['m'] ?? '')); $ip = (string)($l['a'] ?? '');
                if ($mac === '' || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
                $host = mb_substr((string)($l['h'] ?? ''), 0, 120); $srv = mb_substr((string)($l['s'] ?? ''), 0, 64);
                $up->execute([$id, $mac, $ip, $host, $srv, 'bound']);
                if (!isset($knownMacs[$mac]) && !$learning && (int)($d['newdev_alert'] ?? 1) === 1 && !in_array(mb_strtolower($srv), $ignore, true)) {
                    $newHosts[] = ['mac' => $mac, 'ip' => $ip, 'host' => $host, 'srv' => $srv];
                }
                $knownMacs[$mac] = true;
            }
        }

        // --- IP pooli (vsakih 5 min); med pošiljanji obdržimo zadnje znane vrednosti
        $pools = null;
        if (is_array($b['pools'] ?? null) && $b['pools']) {
            $pools = [];
            foreach ($b['pools'] as $p) {
                if (!is_array($p) || empty($p['n'])) continue;
                $size = self::poolSize((string)($p['r'] ?? ''));
                $pools[] = ['n' => (string)$p['n'], 'r' => (string)($p['r'] ?? ''), 'used' => (int)($p['u'] ?? 0), 'size' => $size];
            }
        }
        $oldStatus = json_decode((string)($d['status_json'] ?? ''), true) ?: [];

        // --- logi (vrstico, ki jo že imamo, zavrnemo – router lahko po ponovnem zagonu ali prenamestitvi pošlje znova)
        $logs = (array)($b['logs'] ?? []);
        if ($logs) {
            $insL = $pdo->prepare('INSERT INTO logs (device_id, ts, topics, severity, message) VALUES (?,?,?,?,?)');
            $exists = $pdo->prepare('SELECT 1 FROM logs WHERE device_id=? AND ts=? AND topics=? AND message=? LIMIT 1');
            $dups = 0;
            foreach ($logs as $l) {
                if (!is_array($l)) continue;
                $tp = str_replace(';', ',', mb_substr((string)($l['tp'] ?? ''), 0, 120));
                $msg = mb_substr((string)($l['m'] ?? ''), 0, 1000);
                $lts = self::logTime((string)($l['t'] ?? ''), $now);
                $exists->execute([$id, $lts, $tp, $msg]);
                if ($exists->fetchColumn()) { $dups++; continue; }
                $insL->execute([$id, $lts, $tp, self::severity($tp, $msg), $msg]);
            }
            if ($dups) {
                $prev = explode('|', setting('logdup:' . $id, '0|'));
                setting_set('logdup:' . $id, ((int)$prev[0] + $dups) . '|' . date('Y-m-d H:i:s'));
            }
        }

        // --- status naprave (za prikaz)
        $status = [
            'ident' => (string)($b['ident'] ?? ''), 'res' => $res, 'rb' => $rb, 'health' => $healthRaw, 'ping' => $ping,
            'wg' => (array)($b['wg'] ?? []), 'upd' => (array)($b['upd'] ?? []), 'h' => $h, 'at' => date('c', $now),
            'pools' => $pools ?? ($oldStatus['pools'] ?? []),
            'flt' => is_array($b['flt'] ?? null) && $b['flt'] ? $b['flt'] : ($oldStatus['flt'] ?? null),
        ];
        $rebooted = $d['last_uptime'] !== null && $uptime !== null && $uptime + 120 < (int)$d['last_uptime'];
        $pdo->prepare('UPDATE devices SET last_seen_at=NOW(), last_seen_ip=?, last_uptime=?, status_json=?, model=IF(?<>"", ?, model), serial=IF(?<>"", ?, serial), os_version=?, firmware=?, arch=? WHERE id=?')
            ->execute([client_ip(), $uptime, json_encode($status, JSON_UNESCAPED_UNICODE), (string)($rb['model'] ?? $res['board'] ?? ''), (string)($rb['model'] ?? $res['board'] ?? ''),
                (string)($rb['serial'] ?? ''), (string)($rb['serial'] ?? ''), mb_substr((string)($res['ver'] ?? ''), 0, 40), (string)($rb['fw'] ?? ''), (string)($res['arch'] ?? ''), $id]);

        $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        // --- alarmi iz svežih podatkov
        $d = Devices::decorate(array_merge($d, ['last_seen_at' => date('Y-m-d H:i:s'), 'status_json' => json_encode($status)]));
        Alerts::evaluatePush($d, $h, $rebooted, $uptime);
        if ($pools !== null) Alerts::pools($d, $pools);
        if ($newHosts) Alerts::newHosts($d, $newHosts);
        if (!empty($b['logs'])) Alerts::attacks($d);
        Alerts::links($d, $speedDrops);
        if ($sfpRows) Alerts::sfp($d, array_filter($sfpRows));

        return ['ok' => true, 'tick' => (int)($b['tick'] ?? 0)];
    }

    /** Ping: v2 = surovi časi odgovorov (/ping as-value), v1 = flood-ping (stare skripte) -> [gwMs, gwLoss, extMs, extLoss] */
    private static function pings(array $p): array
    {
        $cnt = max(1, (int)($p['cnt'] ?? 5));
        if ((int)($p['v'] ?? 1) >= 2) {
            $calc = function ($times) use ($cnt): array {
                $ms = [];
                foreach ((array)$times as $t) { $v = self::rosMs((string)$t); if ($v !== null) $ms[] = $v; }
                $loss = (int)round((1 - min($cnt, count($ms)) / $cnt) * 100);
                return [$ms ? round(array_sum($ms) / count($ms), 2) : null, $loss];
            };
            [$gwMs, $gwLoss] = !empty($p['gwOn']) ? $calc($p['gwT'] ?? []) : [null, null];
            [$extMs, $extLoss] = $calc($p['extT'] ?? []);
            return [$gwMs, $gwLoss, $extMs, $extLoss];
        }
        $gwMs = isset($p['gwMs']) && is_numeric($p['gwMs']) && $p['gwMs'] >= 0 ? (float)$p['gwMs'] : null;
        $gwLoss = isset($p['gwRx']) && is_numeric($p['gwRx']) && $p['gwRx'] >= 0 ? (int)round((1 - $p['gwRx'] / $cnt) * 100) : null;
        $extMs = isset($p['extMs']) && is_numeric($p['extMs']) && $p['extMs'] >= 0 ? (float)$p['extMs'] : null;
        $extLoss = isset($p['extRx']) && is_numeric($p['extRx']) ? (int)round((1 - $p['extRx'] / $cnt) * 100) : null;
        if ($gwLoss === 100) $gwMs = null;
        if ($extLoss === 100) $extMs = null;
        return [$gwMs, $gwLoss, $extMs, $extLoss];
    }

    /** RouterOS čas odgovora: "00:00:00.001825" ali "1ms825us" -> ms; prazno = ni odgovora */
    private static function rosMs(string $t): ?float
    {
        $t = trim($t);
        if ($t === '') return null;
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $t, $m)) return round(($m[1] * 3600 + $m[2] * 60 + (float)$m[3]) * 1000, 3);
        if (preg_match_all('/(\d+(?:\.\d+)?)(ms|us|s)/', $t, $mm, PREG_SET_ORDER)) {
            $v = 0.0; foreach ($mm as $x) $v += (float)$x[1] * ['s' => 1000, 'ms' => 1, 'us' => 0.001][$x[2]];
            return round($v, 3);
        }
        return null;
    }

    /** Število naslovov v RouterOS "ranges": "10.0.0.10-10.0.0.254;10.0.1.0/24" */
    public static function poolSize(string $ranges): int
    {
        $n = 0;
        foreach (preg_split('/[;,\s]+/', trim($ranges)) as $r) {
            if ($r === '') continue;
            if (preg_match('#^([\d.]+)/(\d+)$#', $r, $m)) { $n += 2 ** (32 - (int)$m[2]); continue; }
            [$a, $z] = array_pad(explode('-', $r, 2), 2, null);
            $la = ip2long($a); $lz = $z !== null ? ip2long($z) : $la;
            if ($la !== false && $lz !== false && $lz >= $la) $n += $lz - $la + 1;
        }
        return $n;
    }

    /** "1Gbps", "10Gbps", "100Mbps", "2.5Gbps" -> Mb/s */
    public static function rateMbps(string $r): ?float
    {
        if (!preg_match('/([\d.]+)\s*([GM])bps/i', $r, $m)) return null;
        return (float)$m[1] * (strtoupper($m[2]) === 'G' ? 1000 : 1);
    }

    /** Čas z routerja ("2026-10-01 08:09:52", "oct/01/2026 08:09:52") -> unix; prazno -> $now */
    private static function rosTime(string $t, int $now): int
    {
        $t = trim($t); if ($t === '') return $now;
        $ts = strtotime(str_replace('/', ' ', $t)); return $ts && $ts <= $now + 300 ? $ts : $now;
    }

    /** Število iz RouterOS vrednosti z enoto: "-8.405dBm", "41C", "3.29V", "18mA", "1310nm", "20km" */
    public static function num(?string $v): ?float
    {
        if ($v === null || !preg_match('/-?\d+(?:\.\d+)?/', $v, $m)) return null;
        return (float)$m[0];
    }

    /** Zapis enega SFP modula; vrne vrstico za alarme ali null */
    private static function sfp(array $d, array $m, int $now): ?array
    {
        $pdo = db(); $id = (int)$d['id']; $n = mb_substr((string)$m['n'], 0, 64);
        $present = in_array(strtolower((string)($m['p'] ?? '')), ['true', 'yes'], true);
        $st = $pdo->prepare('SELECT * FROM sfp_state WHERE device_id=? AND iface=?'); $st->execute([$id, $n]); $old = $st->fetch() ?: null;
        $ev = $pdo->prepare('INSERT INTO iface_events (device_id, iface, ts, kind, detail) VALUES (?,?,FROM_UNIXTIME(?),?,?)');
        if (!$present) {
            if ($old && $old['present']) {
                $pdo->prepare('UPDATE sfp_state SET present=0, rx=NULL, tx=NULL, updated_at=NOW() WHERE device_id=? AND iface=?')->execute([$id, $n]);
                $ev->execute([$id, $n, $now, 'sfp_out', $old['part']]);
                Alerts::event($d, 'sfp:' . $n, 'warning', A('SFP modul odstranjen iz {n} ({p})', ['n' => $n, 'p' => $old['part'] ?: $old['vendor']]));
            }
            return null;
        }
        $v = fn($k) => trim((string)($m[$k] ?? ''));
        $row = ['vendor' => mb_substr($v('v'), 0, 64), 'part' => mb_substr($v('pn'), 0, 64), 'serial' => mb_substr($v('sn'), 0, 64), 'stype' => mb_substr($v('t'), 0, 64),
                'wavelength' => self::num($v('wl')), 'rx' => self::num($v('rx')), 'tx' => self::num($v('tx')), 'temp' => self::num($v('tmp')), 'volt' => self::num($v('vcc')), 'bias' => self::num($v('bias'))];
        $len = $v('len'); $row['length_km'] = ($x = self::num($len)) !== null ? (str_contains($len, 'km') ? $x : round($x / 1000, 1)) : null;
        // nesmiselne vrednosti (bakreni moduli, DAC kabli, moduli brez DDM) -> ni podatka; sicer bi baza zavrnila cel push
        $in = fn($x, float $lo, float $hi) => $x !== null && $x >= $lo && $x <= $hi ? $x : null;
        $row['wavelength'] = $in($row['wavelength'], 1, 20000) !== null ? (int)round($row['wavelength']) : null;
        $row['rx'] = $in($row['rx'], -60, 30); $row['tx'] = $in($row['tx'], -60, 30);
        $row['temp'] = $in($row['temp'], -60, 200); $row['volt'] = $in($row['volt'], 0, 20);
        $row['bias'] = $in($row['bias'], 0, 1000); $row['length_km'] = $in($row['length_km'], 0, 10000);
        // 10G: hitrost porta, oznaka 10G v tipu/modelu ali MikroTik "S+…" (tip "SFP/SFP+/SFP28" opisuje režo, ne modula)
        $tenG = preg_match('/\b10G|10000|10Gbps/i', $row['stype'] . ' ' . $row['part'] . ' ' . $v('rate')) || preg_match('/^(S\+|SFP-10G|SFP\+-)/i', $row['part']);
        $row['speed_class'] = $tenG ? '10g' : '1g';
        if (!$old) $ev->execute([$id, $n, $now, 'sfp_in', trim($row['vendor'] . ' ' . $row['part'])]);
        elseif ($old['serial'] !== '' && $row['serial'] !== '' && $old['serial'] !== $row['serial']) {
            $ev->execute([$id, $n, $now, 'sfp_swap', $old['part'] . ' → ' . $row['part']]);
            Alerts::event($d, 'sfp:' . $n, 'info', A('SFP modul v {n} zamenjan: {o} → {p} (SN {s})', ['n' => $n, 'o' => $old['part'], 'p' => $row['part'], 's' => $row['serial']]));
        } elseif (!$old['present']) $ev->execute([$id, $n, $now, 'sfp_in', trim($row['vendor'] . ' ' . $row['part'])]);
        $pdo->prepare('INSERT INTO sfp_state (device_id, iface, present, vendor, part, serial, stype, wavelength, length_km, rx, tx, temp, volt, bias, speed_class, updated_at)
                       VALUES (?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE present=1, vendor=VALUES(vendor), part=VALUES(part), serial=VALUES(serial), stype=VALUES(stype), wavelength=VALUES(wavelength), length_km=VALUES(length_km),
                         rx=VALUES(rx), tx=VALUES(tx), temp=VALUES(temp), volt=VALUES(volt), bias=VALUES(bias), speed_class=VALUES(speed_class), updated_at=NOW()')
            ->execute([$id, $n, $row['vendor'], $row['part'], $row['serial'], $row['stype'], $row['wavelength'], $row['length_km'], $row['rx'], $row['tx'], $row['temp'], $row['volt'], $row['bias'], $row['speed_class']]);
        $pdo->prepare('INSERT IGNORE INTO sfp_5m (device_id, iface, ts, rx, tx, temp) VALUES (?,?,?,?,?,?)')->execute([$id, $n, date('Y-m-d H:i:00', $now - $now % 300), $row['rx'], $row['tx'], $row['temp']]);
        return ['iface' => $n] + $row + ['th_warn' => $old['th_warn'] ?? null, 'th_crit' => $old['th_crit'] ?? null, 'th_high' => $old['th_high'] ?? null];
    }

    /** Stopnja vrstice loga. RouterOS spremembo ure (IP Cloud ob zagonu) označi kot critical – to je samo info. */
    public static function severity(string $topics, string $msg): string
    {
        if (str_starts_with($msg, 'cloud change time') || preg_match('/^(ntp|sntp)\b.*change time/i', $msg)) return 'info';
        return str_contains($topics, 'critical') ? 'critical' : (str_contains($topics, 'error') ? 'error' : (str_contains($topics, 'warning') ? 'warning' : 'info'));
    }

    /** Temperatura (najvišja od cpu-temperature/temperature/board-temperature…) in napetost */
    private static function sensors(array $h): array
    {
        $temp = null; $volt = null;
        foreach ($h as $k => $v) {
            if (!is_numeric($v)) continue;
            if (str_contains((string)$k, 'temperature')) $temp = max($temp ?? -99, (float)$v);
            if ($k === 'voltage' || $k === 'psu1-voltage') $volt ??= (float)$v;
        }
        return [$temp, $volt];
    }

    /** RouterOS časi: "12:34:56", "oct/01 12:34:56", "2026-10-01 12:34:56", "10-01 12:34:56" */
    private static function logTime(string $t, int $now): string
    {
        $t = trim($t);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $t)) return $t;
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $t)) { $ts = strtotime(date('Y-m-d ', $now) . $t); if ($ts > $now + 300) $ts -= 86400; return date('Y-m-d H:i:s', $ts); }
        if (preg_match('/^(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/', $t, $m)) return date('Y', $now) . "-{$m[1]}-{$m[2]} {$m[3]}";
        if (preg_match('/^([a-z]{3})\/(\d{2})(?:\/(\d{4}))? (\d{2}:\d{2}:\d{2})$/i', $t, $m)) { $ts = strtotime("{$m[2]} {$m[1]} " . ($m[3] ?: date('Y', $now)) . " {$m[4]}"); if ($ts) return date('Y-m-d H:i:s', $ts); }
        return date('Y-m-d H:i:s', $now);
    }

    /** Del /export (30 kB); ko so vsi deli tu, sestavi in shrani, če se je kaj spremenilo */
    public static function backupPart(array $d, string $upload, int $part, int $size, string $data): array
    {
        $pdo = db(); $id = (int)$d['id'];
        $upload = preg_replace('/[^0-9a-zA-Z]/', '', $upload);
        if ($upload === '' || $part < 0 || $part > 500 || $size <= 0 || $size > 8 * 1024 * 1024) return ['error' => 'bad'];
        $pdo->prepare('REPLACE INTO backup_parts (device_id, upload_id, part, data) VALUES (?,?,?,?)')->execute([$id, $upload, $part, $data]);
        $st = $pdo->prepare('SELECT part, data FROM backup_parts WHERE device_id=? AND upload_id=? ORDER BY part'); $st->execute([$id, $upload]);
        $parts = $st->fetchAll(); $all = implode('', array_column($parts, 'data'));
        if (strlen($all) < $size) return ['ok' => true, 'parts' => count($parts)];
        $pdo->prepare('DELETE FROM backup_parts WHERE device_id=? AND (upload_id=? OR at < NOW() - INTERVAL 1 DAY)')->execute([$id, $upload]);
        return self::storeBackup($d, $all);
    }

    public static function storeBackup(array $d, string $content): array
    {
        $pdo = db(); $id = (int)$d['id'];
        $content = str_replace("\r", '', $content);
        $norm = Diff::normalize($content); $sha = sha1($norm);
        $st = $pdo->prepare('SELECT id, sha1, content FROM config_backups WHERE device_id=? ORDER BY created_at DESC LIMIT 1'); $st->execute([$id]);
        $last = $st->fetch();
        if ($last && $last['sha1'] === $sha) { $pdo->prepare('UPDATE config_backups SET created_at=NOW() WHERE id=?')->execute([$last['id']]); return ['ok' => true, 'changed' => false]; }
        [$add, $rem] = $last ? Diff::count(Diff::normalize($last['content']), $norm) : [substr_count($norm, "\n") + 1, 0];
        $pdo->prepare('INSERT INTO config_backups (device_id, created_at, sha1, bytes, added, removed, content) VALUES (?, NOW(), ?, ?, ?, ?, ?)')
            ->execute([$id, $sha, strlen($content), $add, $rem, $content]);
        if ($last) Alerts::event($d, 'config', 'info', A('Konfiguracija spremenjena: +{a} / −{r} vrstic', ['a' => $add, 'r' => $rem]));
        return ['ok' => true, 'changed' => true];
    }
}
