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
                Notify::alert($d, $sev, $msg, false, (int)$open['id']);
            }
            return;
        }
        db()->prepare('INSERT INTO alerts (device_id, akey, severity, message, started_at) VALUES (?,?,?,?,NOW())')->execute([$d['id'], $key, $sev, mb_substr($msg, 0, 500)]);
        Notify::alert($d, $sev, $msg, false, (int)db()->lastInsertId());
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

    /** DHCP / IP pool: opozorilo pri th_pool %, kritično, ko je poln */
    public static function pools(array $d, array $pools): void
    {
        $th = Devices::thresholds($d); $seen = [];
        foreach ($pools as $p) {
            if ($p['size'] <= 0) continue;
            $key = 'pool:' . $p['n']; $seen[$key] = true;
            $pct = (int)round($p['used'] / $p['size'] * 100);
            $full = $p['used'] >= $p['size'];
            self::set($d, $pct >= $th['th_pool'], $key, $full ? 'critical' : 'warning',
                $full ? A('IP pool {n} je poln ({u}/{s}) – nove naprave ne dobijo naslova', ['n' => $p['n'], 'u' => $p['used'], 's' => $p['size']])
                      : A('IP pool {n} zaseden {p} % ({u}/{s})', ['n' => $p['n'], 'p' => $pct, 'u' => $p['used'], 's' => $p['size']]));
        }
        $st = db()->prepare('SELECT akey FROM alerts WHERE device_id=? AND akey LIKE "pool:%" AND ended_at IS NULL'); $st->execute([$d['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) if (!isset($seen[$k])) self::clear($d, $k);
    }

    /** Nove naprave v LAN-u (prvič videni MAC naslovi) */
    public static function newHosts(array $d, array $hosts): void
    {
        $fmt = fn($h) => ($h['host'] ?: A('brez imena')) . ' – ' . $h['ip'] . ' (' . $h['mac'] . ($h['srv'] ? ', ' . $h['srv'] : '') . ')';
        if (count($hosts) <= 3) {
            foreach ($hosts as $h) self::event($d, 'newdev', 'info', A('Nova naprava v LAN-u: {h}', ['h' => $fmt($h)]));
        } else {
            self::event($d, 'newdev', 'info', A('{n} novih naprav v LAN-u, npr. {h}', ['n' => count($hosts), 'h' => $fmt($hosts[0])]));
        }
    }

    /** Neuspele prijave na router (SSH, Winbox, API, web) po izvornem IP-ju */
    public static function attacks(array $d): void
    {
        $th = Devices::thresholds($d); $id = (int)$d['id'];
        $min = max(1, (int)$th['th_attack_min']); $lim = max(1, (int)$th['th_attack']);
        $st = db()->prepare("SELECT REGEXP_SUBSTR(message, 'from [0-9a-fA-F:.]+') src, COUNT(*) n, GROUP_CONCAT(DISTINCT REGEXP_SUBSTR(message, 'via [a-z-]+')) via,
                                    GROUP_CONCAT(DISTINCT REGEXP_SUBSTR(message, 'for user [^ ]+')) usr
                             FROM logs WHERE device_id=? AND message LIKE 'login failure%' AND ts > NOW() - INTERVAL ? MINUTE GROUP BY src");
        $st->execute([$id, $min]);
        $hot = [];
        foreach ($st as $r) {
            $ip = trim(substr((string)$r['src'], 5)); if ($ip === '' || (int)$r['n'] < $lim) continue;
            $hot['attack:' . $ip] = A('Napad na router: {n} neuspelih prijav z {ip} v {m} min ({via}, {u})', ['n' => $r['n'], 'ip' => $ip, 'm' => $min,
                'via' => str_replace('via ', '', (string)$r['via']), 'u' => str_replace('for user ', '', (string)$r['usr'])]);
        }
        foreach ($hot as $k => $msg) self::raise($d, $k, 'warning', $msg);
        // zapri, ko 60 min ni več poskusov z istega IP-ja
        $open = db()->prepare('SELECT akey FROM alerts WHERE device_id=? AND akey LIKE "attack:%" AND ended_at IS NULL'); $open->execute([$id]);
        $chk = db()->prepare("SELECT COUNT(*) FROM logs WHERE device_id=? AND message LIKE 'login failure%' AND message LIKE ? AND ts > NOW() - INTERVAL 60 MINUTE");
        foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if (isset($hot[$k])) continue;
            $chk->execute([$id, '%from ' . substr($k, 7) . ' %']);
            if ((int)$chk->fetchColumn() === 0) self::clear($d, $k);
        }
    }

    /** Enkrat na uro: manjkajoč nočni backup konfiguracije */
    public static function backups(): void
    {
        foreach (db()->query('SELECT d.*, (SELECT MAX(created_at) FROM config_backups b WHERE b.device_id=d.id) last_backup FROM devices d WHERE d.active=1 AND d.last_seen_at IS NOT NULL')->fetchAll() as $d) {
            $d = Devices::decorate($d); $th = Devices::thresholds($d);
            $days = max(1, (int)$th['th_backup_days']);
            $ref = $d['last_backup'] ?: $d['created_at'];
            $old = strtotime((string)$ref) < time() - $days * 86400;
            $msg = $d['last_backup'] ? A('Ni novega backupa konfiguracije že {n} dni (zadnji {t})', ['n' => intdiv(time() - strtotime($d['last_backup']), 86400), 't' => date('d.m.Y', strtotime($d['last_backup']))])
                                     : A('Router še ni poslal nobenega backupa konfiguracije');
            self::set($d, $old && $d['online'], 'backup', 'warning', $msg);
        }
    }

    /** Enkrat na uro: nočni backup strežnika (baza NOC) – obvestilo največ enkrat na dan */
    public static function serverBackup(): ?int
    {
        $dir = '/var/backups/noc';
        $files = @glob($dir . '/db-*.sql.gz') ?: [];
        $newest = $files ? max(array_map('filemtime', $files)) : null;
        if ($newest !== null && $newest > time() - 36 * 3600) return $newest;
        if (setting('srvbackup_notified') !== date('Y-m-d')) {
            Notify::telegram('🟠 <b>NOC</b>' . "\n" . A('Nočni backup baze NOC na strežniku manjka ali je starejši od 36 h.'));
            foreach (Notify::recipients() as $to) Notify::mail($to, 'NOC – ' . A('backup strežnika'), '<p>' . A('Nočni backup baze NOC na strežniku manjka ali je starejši od 36 h.') . '</p><p>/var/backups/noc</p>');
            setting_set('srvbackup_notified', date('Y-m-d'));
        }
        return $newest;
    }

    /** Nestabilna povezava (pogoste prekinitve) in padec hitrosti na WAN in izbranih vmesnikih */
    public static function links(array $d, array $speedDrops): void
    {
        $th = Devices::thresholds($d); $id = (int)$d['id'];
        $watch = array_values(array_unique(array_filter(array_merge([(string)$d['wan_iface']], Devices::list((string)$d['down_ifaces'])))));
        $lim = max(2, (int)$th['th_flap']); $min = max(5, (int)$th['th_flap_min']);
        $st = db()->prepare("SELECT COALESCE(SUM(CAST(detail AS UNSIGNED)),0) FROM iface_events WHERE device_id=? AND iface=? AND kind='down' AND ts > NOW() - INTERVAL ? MINUTE");   // ena vrstica = lahko več prekinitev
        foreach ($watch as $n) {
            $st->execute([$id, $n, $min]); $c = (int)$st->fetchColumn();
            self::set($d, $c >= $lim, 'flap:' . $n, 'warning', A('Nestabilna povezava na {n}: {c} v {m} min', ['n' => $n, 'c' => $c . ' ' . plural($c, A('prekinitev'), A('prekinitvi'), A('prekinitve'), A('prekinitev')), 'm' => $min]));
        }
        // padec hitrosti: alarm ostane odprt, dokler se hitrost ne poveča nazaj
        foreach ($speedDrops as $n => [$down, $txt]) {
            if (!in_array($n, $watch, true)) continue;
            if ($down) self::raise($d, 'speed:' . $n, 'warning', A('Hitrost povezave na {n} je padla: {t}', ['n' => $n, 't' => $txt]));
            else self::clear($d, 'speed:' . $n, A('Hitrost povezave na {n} spet normalna: {t}', ['n' => $n, 't' => $txt]));
        }
    }

    /** Privzeti pragovi RX moči (dBm) po hitrosti modula */
    public const SFP_TH = ['1g' => ['warn' => -20.0, 'crit' => -23.0, 'high' => -3.0], '10g' => ['warn' => -12.0, 'crit' => -14.0, 'high' => 0.5]];

    public static function sfpThresholds(array $r): array
    {
        $def = self::SFP_TH[$r['speed_class'] ?? '1g'] ?? self::SFP_TH['1g'];
        return ['warn' => $r['th_warn'] !== null ? (float)$r['th_warn'] : $def['warn'], 'crit' => $r['th_crit'] !== null ? (float)$r['th_crit'] : $def['crit'], 'high' => $r['th_high'] !== null ? (float)$r['th_high'] : $def['high']];
    }

    /** SFP: šibek ali premočan signal, slabšanje optike glede na tedensko povprečje */
    public static function sfp(array $d, array $rows): void
    {
        $id = (int)$d['id'];
        foreach ($rows as $r) {
            $n = $r['iface']; $rx = $r['rx'];
            if ($rx === null) continue;   // modul brez diagnostike (DDM)
            $t = self::sfpThresholds($r); $f = number_format((float)$rx, 1, ',', '');
            $sev = $rx <= $t['crit'] ? 'critical' : ($rx <= $t['warn'] ? 'warning' : null);
            self::set($d, $sev !== null, 'sfprx:' . $n, $sev ?? 'warning', $sev === 'critical' ? A('SFP {n}: RX {r} dBm – signal tik pred izpadom', ['n' => $n, 'r' => $f]) : A('SFP {n}: šibek signal, RX {r} dBm', ['n' => $n, 'r' => $f]));
            self::set($d, $rx >= $t['high'], 'sfphigh:' . $n, 'warning', A('SFP {n}: premočan signal, RX {r} dBm – potreben atenuator', ['n' => $n, 'r' => $f]));
            // slabšanje: povprečje zadnjih 7 dni (brez zadnje ure) proti zadnjim 30 min
            $st = db()->prepare('SELECT AVG(IF(ts < NOW() - INTERVAL 1 HOUR, rx, NULL)) wk, AVG(IF(ts > NOW() - INTERVAL 30 MINUTE, rx, NULL)) now_, SUM(ts < NOW() - INTERVAL 1 DAY) old_n
                                 FROM sfp_5m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL 7 DAY AND rx IS NOT NULL');
            $st->execute([$id, $n]); $a = $st->fetch();
            $drop = ($a['wk'] !== null && $a['now_'] !== null && (int)$a['old_n'] >= 12) ? (float)$a['wk'] - (float)$a['now_'] : 0;
            self::set($d, $drop >= 3, 'sfpdeg:' . $n, 'warning', A('SFP {n}: optika se slabša – RX je {x} dB nižji od tedenskega povprečja (umazan konektor, upognjeno vlakno?)', ['n' => $n, 'x' => number_format($drop, 1, ',', '')]));
        }
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
