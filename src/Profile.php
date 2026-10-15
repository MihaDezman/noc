<?php
declare(strict_types=1);

/**
 * Profili naprav v LAN-u: vrsta naprave (kamera, NVR, tiskalnik …) iz proizvajalca (MAC) in imena (DHCP),
 * opozorila za "pametne" naprave (kamere, NVR, tiskalniki, IoT), ki se običajno obnašajo predvidljivo:
 *  - nenavadno veliko poslanih podatkov glede na zadnji teden
 *  - prvič komunicirajo z novo državo (po vsaj 7 dneh opazovanja)
 */
final class Profile
{
    /** Vrste, pri katerih je nenavadno obnašanje sumljivo */
    public const WATCH = ['camera', 'nvr', 'printer', 'iot', 'tv'];

    public static function kinds(): array
    {
        return ['' => A('neznano'), 'camera' => A('kamera'), 'nvr' => A('snemalnik (NVR)'), 'printer' => A('tiskalnik'), 'phone' => A('telefon'), 'pc' => A('računalnik'),
                'tv' => A('TV / medij'), 'iot' => A('pametna naprava'), 'network' => A('omrežna oprema'), 'server' => A('strežnik / NAS'), 'other' => A('drugo')];
    }

    public static function icon(string $k): string
    {
        return ['camera' => 'eye', 'nvr' => 'hdd', 'printer' => 'file', 'phone' => 'smartphone', 'pc' => 'laptop', 'tv' => 'monitor', 'iot' => 'plug', 'network' => 'router', 'server' => 'server'][$k] ?? 'box';
    }

    /** Vrsta po proizvajalcu in imenu */
    public static function classify(string $vendor, string $host): string
    {
        $v = strtolower($vendor); $h = strtolower($host);
        if (preg_match('/\b(nvr|dvr|xvr)\b|^nvr|^dvr/', $h)) return 'nvr';
        if (preg_match('/hikvision|dahua|zhejiang dahua|axis comm|uniview|reolink|hanwha|samsung techwin|vivotek|ezviz|imou|foscam|amcrest|tp-link.*tapo/', $v) || preg_match('/^(ipc|cam|camera|kamera)|ipcam|^hik|^dh-/', $h)) return 'camera';
        if (preg_match('/brother|canon|seiko epson|epson|kyocera|lexmark|xerox|ricoh|konica|sharp corp|oki electric|zebra/', $v) || preg_match('/^(hp|npi)[0-9a-f]{6}|printer|tiskalnik|^brw|^epson|^kyocera|^canon/', $h)) return 'printer';
        if (preg_match('/iphone|ipad|android|galaxy|redmi|xiaomi|huawei|oneplus|pixel|oppo|vivo-|honor/', $h)) return 'phone';
        if (preg_match('/synology|qnap|western digital|buffalo/', $v) || preg_match('/nas|server|srv|^ds[0-9]/', $h)) return 'server';
        if (preg_match('/shelly|espressif|tuya|sonoff|itead|signify|philips lighting|lifx|nest labs|ecobee|amazon technologies/', $v) || preg_match('/^(shelly|esp[-_]|tasmota|sonoff)/', $h)) return 'iot';
        if (preg_match('/roku|lg electronics|sony|vizio|tcl|hisense|sagemcom|kaon|technicolor/', $v) || preg_match('/tv|chromecast|appletv|fire-?tv|stb|mediabox/', $h)) return 'tv';
        if (preg_match('/ubiquiti|mikrotik|routerboard|tp-link|tplink|cisco|aruba|netgear|d-link|zyxel|cambium|ruckus|grandstream|yealink|snom/', $v)) return 'network';
        if (preg_match('/desktop-|laptop|^pc|-pc$|macbook|imac|win-|thinkpad/', $h) || preg_match('/intel corp|dell|lenovo|hewlett|asustek|micro-star|gigabyte|apple/', $v)) return 'pc';
        return 'other';
    }

    /** Dopolni proizvajalca in vrsto novim napravam (kliče se po obdelavi tokov) */
    public static function refresh(): void
    {
        if (setting('oui_month') === '') return;   // brez baze proizvajalcev bi skoraj vse postalo "drugo"
        $st = db()->query("SELECT device_id, mac, hostname, vendor FROM lan_hosts WHERE kind='' AND kind_manual=0 AND last_seen > NOW() - INTERVAL 7 DAY LIMIT 2000");
        $up = db()->prepare('UPDATE lan_hosts SET vendor=?, kind=? WHERE device_id=? AND mac=? AND kind_manual=0');
        foreach ($st->fetchAll() as $r) {
            $v = $r['vendor'] !== '' ? $r['vendor'] : Geo::vendor($r['mac']);
            $up->execute([$v, self::classify($v, (string)$r['hostname']), $r['device_id'], $r['mac']]);
        }
    }

    /** Enkrat dnevno: naprave brez proizvajalca in z neznano vrsto ponovno razvrsti (ime iz DHCP se lahko pojavi kasneje) */
    public static function reclassify(): string
    {
        $n = db()->exec("UPDATE lan_hosts SET kind='' WHERE kind='other' AND kind_manual=0 AND last_seen > NOW() - INTERVAL 7 DAY");
        self::refresh();
        return "profili: $n";
    }

    /** Vrsta in ime naprave po IP-ju (zadnji najem) */
    public static function host(int $deviceId, string $ip): array
    {
        static $c = [];
        if (isset($c[$deviceId][$ip])) return $c[$deviceId][$ip];
        $st = db()->prepare('SELECT kind, COALESCE(NULLIF(label,""), NULLIF(hostname,""), vendor) name FROM lan_hosts WHERE device_id=? AND ip=? ORDER BY last_seen DESC LIMIT 1');
        $st->execute([$deviceId, $ip]); $r = $st->fetch() ?: ['kind' => '', 'name' => ''];
        return $c[$deviceId][$ip] = ['kind' => (string)$r['kind'], 'name' => (string)$r['name']];
    }

    private static function who(int $deviceId, string $ip): string
    {
        $h = self::host($deviceId, $ip);
        $k = $h['kind'] !== '' ? (self::kinds()[$h['kind']] ?? '') : A('naprava');
        return mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1) . ' ' . $ip . ($h['name'] !== '' ? ' (' . $h['name'] . ')' : '');
    }

    /** Opozorila: nenavadno velik odhodni promet pametnih naprav glede na zadnji teden */
    public static function anomalies(array $devs): void
    {
        $in = "'" . implode("','", self::WATCH) . "'";
        foreach ($devs as $d) {
            $id = (int)$d['id']; $d = Devices::decorate($d);
            // naprave, ki jih spremljamo; zadnja ura in največja ura v prejšnjih 7 dneh
            $st = db()->prepare("SELECT h.ip, SUM(IF(h.ts > NOW() - INTERVAL 1 HOUR, h.up_bytes, 0)) cur FROM host_5m h
                                 WHERE h.device_id=? AND h.ts > NOW() - INTERVAL 1 HOUR AND h.ip IN (SELECT ip FROM lan_hosts WHERE device_id=? AND kind IN ($in)) GROUP BY h.ip");
            $st->execute([$id, $id]);
            $hot = [];
            foreach ($st->fetchAll() as $r) {
                $b = db()->prepare("SELECT MAX(s) mx, MIN(t) first FROM (SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00') t, SUM(up_bytes) s FROM host_5m
                                    WHERE device_id=? AND ip=? AND ts BETWEEN NOW() - INTERVAL 7 DAY AND NOW() - INTERVAL 1 HOUR GROUP BY t) x");
                $b->execute([$id, $r['ip']]); $base = $b->fetch();
                if (!$base['first'] || strtotime((string)$base['first']) > time() - 3 * 86400) continue;   // premalo zgodovine (vsaj 3 dni)
                $lim = max(3 * (float)$base['mx'], 100e6);
                if ((float)$r['cur'] >= $lim) $hot[$r['ip']] = A('{w} pošilja nenavadno veliko podatkov: {b} v zadnji uri (prejšnji teden največ {m} na uro)', ['w' => self::who($id, $r['ip']), 'b' => fmt_bytes($r['cur']), 'm' => fmt_bytes($base['mx'])]);
                elseif ((float)$r['cur'] < max(1.5 * (float)$base['mx'], 50e6)) $hot[$r['ip']] = false;
                else $hot[$r['ip']] = null;   // vmesno območje: odprt alarm ostane, nov se ne odpre (brez utripanja)
            }
            foreach ($hot as $ip => $msg) if (is_string($msg)) Alerts::raise($d, 'anom:' . $ip, 'warning', $msg);
            $open = db()->prepare("SELECT akey FROM alerts WHERE device_id=? AND akey LIKE 'anom:%' AND ended_at IS NULL"); $open->execute([$id]);
            foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $k) { $ip = substr($k, 5); if (!array_key_exists($ip, $hot) || $hot[$ip] === false) Alerts::clear($d, $k); }
        }
    }

    /** Opozorila: pametna naprava prvič komunicira z novo državo (po vsaj 7 dneh opazovanja, vsaj 1 MB) */
    public static function newCountries(array $devById): void
    {
        $in = "'" . implode("','", self::WATCH) . "'";
        // vrsta po zadnjem najemu tega IP-ja (IP se lahko zamenja med napravami)
        $rows = db()->query("SELECT g.* FROM host_geo g
                             WHERE g.first_seen >= CURDATE() - INTERVAL 1 DAY AND g.bytes >= 1000000 AND g.cc <> ''
                             AND (SELECT l.kind FROM lan_hosts l WHERE l.device_id=g.device_id AND l.ip=g.ip ORDER BY l.last_seen DESC LIMIT 1) IN ($in)
                             AND (SELECT MIN(first_seen) FROM host_geo x WHERE x.device_id=g.device_id AND x.ip=g.ip) <= CURDATE() - INTERVAL 7 DAY
                             AND NOT EXISTS (SELECT 1 FROM alerts a WHERE a.device_id=g.device_id AND a.akey=CONCAT('geo:', g.ip, ':', g.cc))")->fetchAll();
        foreach ($rows as $r) {
            $d = $devById[(int)$r['device_id']] ?? null; if (!$d) continue;
            Alerts::event(Devices::decorate($d), 'geo:' . $r['ip'] . ':' . $r['cc'], 'warning', A('{w} prvič komunicira z državo {c} ({b})', ['w' => self::who((int)$r['device_id'], $r['ip']), 'c' => Geo::flag($r['cc']) . ' ' . Geo::name($r['cc']), 'b' => fmt_bytes($r['bytes'])]));
        }
    }
}
