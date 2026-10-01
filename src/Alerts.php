<?php
declare(strict_types=1);

/** Alarmi: odpiranje, zapiranje, enkratni dogodki, obveščanje */
final class Alerts
{
    public const LEVEL = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /** Odpre alarm, če še ni odprt (in obvesti) */
    public static function raise(array $d, string $key, string $sev, string $msg): void
    {
        $st = db()->prepare('SELECT id, severity FROM alerts WHERE device_id=? AND akey=? AND ended_at IS NULL'); $st->execute([$d['id'], $key]);
        $open = $st->fetch();
        if ($open) {
            if (self::LEVEL[$sev] > self::LEVEL[$open['severity']]) {   // stopnjevanje (npr. warning -> critical)
                db()->prepare('UPDATE alerts SET severity=?, message=? WHERE id=?')->execute([$sev, $msg, $open['id']]);
                Notify::alert($d, $sev, $msg, false);
            }
            return;
        }
        db()->prepare('INSERT INTO alerts (device_id, akey, severity, message, started_at) VALUES (?,?,?,?,NOW())')->execute([$d['id'], $key, $sev, mb_substr($msg, 0, 500)]);
        Notify::alert($d, $sev, $msg, false);
    }

    /** Zapre odprt alarm (in obvesti o razrešitvi) */
    public static function clear(array $d, string $key, string $msg = ''): void
    {
        $st = db()->prepare('SELECT id, severity, message, started_at FROM alerts WHERE device_id=? AND akey=? AND ended_at IS NULL'); $st->execute([$d['id'], $key]);
        foreach ($st->fetchAll() as $a) {
            db()->prepare('UPDATE alerts SET ended_at=NOW() WHERE id=?')->execute([$a['id']]);
            if ($a['severity'] !== 'info' && setting('notify_resolved', '1') === '1')
                Notify::alert($d, $a['severity'], ($msg ?: $a['message']) . ' – ' . A('razrešeno po {t}', ['t' => self::duration(time() - strtotime($a['started_at']))]), true);
        }
    }

    /** Enkraten dogodek (ponovni zagon, sprememba konfiguracije, posodobitev) – takoj zaprt */
    public static function event(array $d, string $key, string $sev, string $msg): void
    {
        db()->prepare('INSERT INTO alerts (device_id, akey, severity, message, started_at, ended_at) VALUES (?,?,?,?,NOW(),NOW())')->execute([$d['id'], $key, $sev, mb_substr($msg, 0, 500)]);
        Notify::alert($d, $sev, $msg, false);
    }

    public static function set(array $d, bool $cond, string $key, string $sev, string $msg): void
    {
        $cond ? self::raise($d, $key, $sev, $msg) : self::clear($d, $key);
    }

    private static function duration(int $s): string { return $s < 3600 ? max(1, intdiv($s, 60)) . ' min' : intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min'; }

    /** Zadnjih N minut iz health_1m – ali pogoj drži v vseh? */
    private static function sustained(int $id, string $expr, int $minutes): bool
    {
        $st = db()->prepare("SELECT COUNT(*) n, SUM($expr) ok FROM (SELECT * FROM health_1m WHERE device_id=? AND ts > NOW() - INTERVAL ? MINUTE) x");
        $st->execute([$id, $minutes]); $r = $st->fetch();
        return (int)$r['n'] >= max(1, $minutes - 1) && (int)$r['ok'] === (int)$r['n'];
    }

    /** Po vsakem pushu */
    public static function evaluatePush(array $d, array $h, bool $rebooted, ?int $uptime): void
    {
        $th = Devices::thresholds($d); $id = (int)$d['id'];
        self::clear($d, 'offline');
        self::clear($d, 'push');

        if ($rebooted) self::event($d, 'reboot', 'warning', A('Ponovni zagon (uptime {u})', ['u' => fmt_uptime($uptime)]));

        $cm = max(1, (int)$th['th_cpu_min']);
        if ($h['cpu'] !== null) self::set($d, self::sustained($id, 'cpu >= ' . (int)$th['th_cpu'], $cm), 'cpu', 'warning', A('CPU nad {p} % že {m} min', ['p' => (int)$th['th_cpu'], 'm' => $cm]));
        if ($h['temp'] !== null) self::set($d, $h['temp'] >= $th['th_temp'], 'temp', $h['temp'] >= $th['th_temp'] + 10 ? 'critical' : 'warning', A('Temperatura {t} °C', ['t' => $h['temp']]));
        if ($h['mem_pct'] !== null) self::set($d, $h['mem_pct'] >= $th['th_mem'], 'mem', 'warning', A('Pomnilnik zaseden {p} %', ['p' => $h['mem_pct']]));
        if ($h['hdd_pct'] !== null) self::set($d, $h['hdd_pct'] >= $th['th_hdd'], 'hdd', 'warning', A('Disk zaseden {p} %', ['p' => $h['hdd_pct']]));

        $pm = max(1, (int)$th['th_ping_min']);
        // Izpad WAN = ne odgovarja niti internet niti prehod. Prehod brez odgovora ob delujočem internetu pomeni,
        // da ponudnik ne odgovarja na ping – to ni izpad.
        $extDown = $h['ext_loss'] !== null && self::sustained($id, 'ext_loss >= 100', $pm);
        $gwDown = $h['gw_loss'] !== null && self::sustained($id, 'gw_loss >= 100', $pm);
        self::set($d, $extDown && ($gwDown || $h['gw_loss'] === null), 'wan_down', 'critical', A('Internet ne deluje že {m} min (WAN)', ['m' => $pm]));
        self::set($d, $extDown && $h['gw_loss'] !== null && !$gwDown, 'ext_down', 'critical', A('Prehod ponudnika odgovarja, internet pa ne že {m} min', ['m' => $pm]));
        self::clear($d, 'gw_down');   // stari ključ (v1.0)
        if ($h['gw_loss'] !== null && !$gwDown) {
            self::set($d, self::sustained($id, 'gw_loss >= ' . (int)$th['th_gw_loss'], $pm), 'gw_loss', 'warning', A('Izguba paketov do prehoda nad {p} %', ['p' => (int)$th['th_gw_loss']]));
            self::set($d, self::sustained($id, 'gw_ms >= ' . (float)$th['th_gw_ms'], $pm), 'gw_ms', 'warning', A('Latenca do prehoda nad {m} ms', ['m' => (int)$th['th_gw_ms']]));
        } else { self::clear($d, 'gw_loss'); self::clear($d, 'gw_ms'); }
        if ($h['ext_loss'] !== null && !$extDown) {
            self::set($d, self::sustained($id, 'ext_loss >= ' . (int)$th['th_ext_loss'], $pm), 'ext_loss', 'warning', A('Izguba paketov do interneta ({t}) nad {p} %', ['t' => $d['ping_target'], 'p' => (int)$th['th_ext_loss']]));
            self::set($d, self::sustained($id, 'ext_ms >= ' . (float)$th['th_ext_ms'], $pm), 'ext_ms', 'warning', A('Latenca do interneta nad {m} ms', ['m' => (int)$th['th_ext_ms']]));
        } else { self::clear($d, 'ext_loss'); self::clear($d, 'ext_ms'); }

        // vmesniki, ki morajo biti gor
        $watch = Devices::list((string)$d['down_ifaces']);
        if ($watch) {
            $st = db()->prepare('SELECT name, running, disabled FROM device_ifaces WHERE device_id=?'); $st->execute([$id]);
            $cur = []; foreach ($st as $r) $cur[$r['name']] = $r;
            foreach ($watch as $n) {
                if (!isset($cur[$n])) { self::clear($d, 'iface:' . $n); continue; }   // router ga ne pošilja = neznano, ne izpad
                $down = !$cur[$n]['running'] && !$cur[$n]['disabled'];
                self::set($d, $down, 'iface:' . $n, $n === $d['wan_iface'] ? 'critical' : 'warning', A('Vmesnik {n} ne deluje', ['n' => $n]));
            }
        }

        // posodobitev RouterOS
        $upd = $d['status']['upd'] ?? [];
        $newer = !empty($upd['latest']) && !empty($upd['inst']) && version_compare((string)$upd['latest'], (string)$upd['inst'], '>');
        self::set($d, $newer, 'update', 'info', A('Na voljo RouterOS {v} (nameščen {i})', ['v' => $upd['latest'] ?? '', 'i' => $upd['inst'] ?? '']));
    }

    /** Cron vsako minuto: tišina naprav, ping s strežnika */
    public static function evaluateCron(): void
    {
        $devs = db()->query('SELECT * FROM devices WHERE active=1')->fetchAll();
        $minute = (int)date('i');
        foreach ($devs as $d) {
            $d = Devices::decorate($d); $th = Devices::thresholds($d);
            $ok = null; $ms = null; $loss = null;
            if (filter_var($d['public_ip'], FILTER_VALIDATE_IP) && ($minute % 5 === 0 || !$d['online'])) {
                [$ok, $ms, $loss] = self::ping($d['public_ip'], 5);
                db()->prepare('UPDATE devices SET tiger_ping_ok=?, tiger_ping_ms=?, tiger_ping_at=NOW() WHERE id=?')->execute([$ok ? 1 : 0, $ms, $d['id']]);
                $slot = date('Y-m-d H:i:00', time() - time() % 300);
                db()->prepare('INSERT INTO health_5m (device_id, ts, tiger_ms, tiger_loss, n) VALUES (?,?,?,?,0) ON DUPLICATE KEY UPDATE tiger_ms=VALUES(tiger_ms), tiger_loss=VALUES(tiger_loss)')->execute([$d['id'], $slot, $ms, $loss]);
            }
            if (!$d['last_seen_at']) continue;
            $silent = time() - strtotime($d['last_seen_at']);
            if ($silent > $th['th_offline_min'] * 60) {
                if ($ok === null && filter_var($d['public_ip'], FILTER_VALIDATE_IP)) [$ok] = self::ping($d['public_ip'], 3);
                if ($ok) { self::clear($d, 'offline'); self::raise($d, 'push', 'warning', A('Router odgovarja na ping, pošiljanje podatkov pa ne deluje že {m} min', ['m' => intdiv($silent, 60)])); }
                else { self::clear($d, 'push'); self::raise($d, 'offline', 'critical', A('Naprava nedosegljiva že {m} min', ['m' => intdiv($silent, 60)])); }
            }
        }
    }

    /** [ok, ms, loss%] */
    public static function ping(string $ip, int $count = 5): array
    {
        $out = (string)@shell_exec('ping -n -c ' . $count . ' -i 0.3 -W 1 ' . escapeshellarg($ip) . ' 2>&1');
        $rcv = preg_match('/(\d+) received/', $out, $m) ? (int)$m[1] : 0;
        $ms = preg_match('#= [\d.]+/([\d.]+)/#', $out, $a) ? round((float)$a[1], 1) : null;
        return [$rcv > 0, $ms, (int)round((1 - $rcv / max(1, $count)) * 100)];
    }

    public static function openList(?int $deviceId = null): array
    {
        [$w, $p] = Auth::deviceScope('d');
        $sql = "SELECT a.*, d.name device_name, d.kind FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.ended_at IS NULL AND $w";
        if ($deviceId) { $sql .= ' AND a.device_id=?'; $p[] = $deviceId; }
        $st = db()->prepare($sql . ' ORDER BY FIELD(a.severity,"critical","warning","info"), a.started_at DESC'); $st->execute($p);
        return $st->fetchAll();
    }
}
