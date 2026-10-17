<?php
declare(strict_types=1);

/** Mesečno poročilo za naročnika: razpoložljivost, promet, latenca, alarmi, spremembe konfiguracije, največji porabniki */
final class Report
{
    /** Podatki za naročnika in mesec "Y-m" */
    public static function build(int $tenantId, string $ym, bool $withAi = true): array
    {
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tenantId]);
        $tenant = $st->fetch() ?: [];
        [$from, $to] = Sla::monthRange($ym);
        $st = $pdo->prepare('SELECT * FROM devices WHERE tenant_id=? AND active=1 AND created_at < FROM_UNIXTIME(?) ORDER BY name'); $st->execute([$tenantId, $to]);
        $devices = [];
        foreach ($st->fetchAll() as $d) {
            $d = Devices::decorate($d); $id = (int)$d['id'];
            $sla = Sla::period($d, $from, $to);
            // promet WAN po dnevih
            $daily = [];
            if ($d['wan_iface']) {
                $q = $pdo->prepare('SELECT day, rx_bytes, tx_bytes, rx_peak, tx_peak FROM iface_daily WHERE device_id=? AND iface=? AND day >= FROM_UNIXTIME(?) AND day < FROM_UNIXTIME(?) ORDER BY day');
                $q->execute([$id, $d['wan_iface'], $from, $to]);
                foreach ($q as $r) $daily[$r['day']] = $r;
                if ($to > time()) {   // tekoči mesec: današnji dan iz 5-min podatkov
                    $q = $pdo->prepare('SELECT COALESCE(SUM(rx_bytes),0) rx_bytes, COALESCE(SUM(tx_bytes),0) tx_bytes, COALESCE(MAX(rx_peak),0) rx_peak, COALESCE(MAX(tx_peak),0) tx_peak FROM iface_5m WHERE device_id=? AND iface=? AND ts >= CURDATE()');
                    $q->execute([$id, $d['wan_iface']]); $today = $q->fetch();
                    if ($today && ($today['rx_bytes'] + $today['tx_bytes']) > 0) $daily[date('Y-m-d')] = $today + ['day' => date('Y-m-d')];
                }
            }
            $rx = array_sum(array_column($daily, 'rx_bytes')); $tx = array_sum(array_column($daily, 'tx_bytes'));
            $peakRx = $daily ? max(array_column($daily, 'rx_peak')) : 0; $peakTx = $daily ? max(array_column($daily, 'tx_peak')) : 0;
            // latenca (5-min podatki se hranijo 31 dni)
            $q = $pdo->prepare('SELECT AVG(gw_ms) gw, AVG(ext_ms) ext, MAX(ext_ms) ext_max, AVG(ext_loss) loss FROM health_5m WHERE device_id=? AND ts >= FROM_UNIXTIME(?) AND ts < FROM_UNIXTIME(?)');
            $q->execute([$id, $from, $to]); $lat = $q->fetch();
            // alarmi
            $q = $pdo->prepare("SELECT severity, COUNT(*) n FROM alerts WHERE device_id=? AND started_at >= FROM_UNIXTIME(?) AND started_at < FROM_UNIXTIME(?) AND akey NOT IN ('update','newdev','config') GROUP BY severity");
            $q->execute([$id, $from, $to]); $al = array_column($q->fetchAll(), 'n', 'severity');
            $q = $pdo->prepare("SELECT COUNT(*) FROM alerts WHERE device_id=? AND akey LIKE 'attack:%' AND started_at >= FROM_UNIXTIME(?) AND started_at < FROM_UNIXTIME(?)");
            $q->execute([$id, $from, $to]); $attacks = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT COUNT(*) FROM alerts WHERE device_id=? AND akey='reboot' AND started_at >= FROM_UNIXTIME(?) AND started_at < FROM_UNIXTIME(?)");
            $q->execute([$id, $from, $to]); $reboots = (int)$q->fetchColumn();
            $q = $pdo->prepare('SELECT COUNT(*) FROM config_backups WHERE device_id=? AND created_at >= FROM_UNIXTIME(?) AND created_at < FROM_UNIXTIME(?) AND (added + removed) > 0');
            $q->execute([$id, $from, $to]); $changes = (int)$q->fetchColumn();
            // največji porabniki v LAN-u
            $q = $pdo->prepare('SELECT h.ip, SUM(h.up_bytes) up, SUM(h.down_bytes) down,
                                       (SELECT COALESCE(NULLIF(l.label,""), l.hostname) FROM lan_hosts l WHERE l.device_id=h.device_id AND l.ip=h.ip ORDER BY l.last_seen DESC LIMIT 1) name
                                FROM host_daily h WHERE h.device_id=? AND h.day >= FROM_UNIXTIME(?) AND h.day < FROM_UNIXTIME(?) GROUP BY h.ip ORDER BY SUM(h.up_bytes + h.down_bytes) DESC LIMIT 5');
            $q->execute([$id, $from, $to]); $top = $q->fetchAll();
            $q = $pdo->prepare('SELECT COUNT(DISTINCT mac) FROM lan_hosts WHERE device_id=? AND last_seen >= FROM_UNIXTIME(?) AND first_seen < FROM_UNIXTIME(?)');
            $q->execute([$id, $from, $to]); $hosts = (int)$q->fetchColumn();
            // varnost: zavrnjeni poskusi z interneta, stik z zlonamernimi strežniki, nenavaden promet, države, VPN uporabniki
            $q = $pdo->prepare("SELECT COALESCE(SUM(hits),0) FROM fw_daily WHERE device_id=? AND kind IN ('in','bl_in') AND day >= FROM_UNIXTIME(?) AND day < FROM_UNIXTIME(?)");
            $q->execute([$id, $from, $to]); $blocked = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT SUM(akey LIKE 'threat:%' OR akey LIKE 'outbl:%') thr, SUM(akey LIKE 'anom:%' OR akey LIKE 'geo:%') anom, SUM(akey LIKE 'wggeo:%') wg
                                FROM alerts WHERE device_id=? AND started_at >= FROM_UNIXTIME(?) AND started_at < FROM_UNIXTIME(?)");
            $q->execute([$id, $from, $to]); $sa = $q->fetch();
            $q = $pdo->prepare('SELECT cc, SUM(up_bytes + down_bytes) b FROM geo_daily WHERE device_id=? AND day >= FROM_UNIXTIME(?) AND day < FROM_UNIXTIME(?) AND cc <> "" GROUP BY cc ORDER BY b DESC LIMIT 6');
            $q->execute([$id, $from, $to]); $countries = $q->fetchAll();
            $q = $pdo->prepare('SELECT p.name, SUM(w.rx_bytes) rx, SUM(w.tx_bytes) tx, SUM(w.online_min) m, COUNT(*) days FROM wg_daily w JOIN wg_peers p ON p.id=w.peer_id
                                WHERE p.device_id=? AND w.day >= FROM_UNIXTIME(?) AND w.day < FROM_UNIXTIME(?) GROUP BY p.id ORDER BY m DESC LIMIT 25');
            $q->execute([$id, $from, $to]); $vpn = $q->fetchAll();
            $sec = ['score' => $d['sec_score'] !== null ? (int)$d['sec_score'] : null, 'blocked' => $blocked, 'threats' => (int)$sa['thr'], 'anom' => (int)$sa['anom'], 'wggeo' => (int)$sa['wg'], 'countries' => $countries, 'vpn' => $vpn];
            $devices[] = compact('d', 'sla', 'daily', 'rx', 'tx', 'peakRx', 'peakTx', 'lat', 'al', 'attacks', 'reboots', 'changes', 'top', 'hosts', 'sec');
        }
        $ai = null;
        if ($withAi) { $q = $pdo->prepare('SELECT text FROM report_ai WHERE tenant_id=? AND ym=?'); $q->execute([$tenantId, $ym]); $ai = $q->fetchColumn() ?: null; }
        return ['tenant' => $tenant, 'ym' => $ym, 'from' => $from, 'to' => $to, 'devices' => $devices, 'ai' => $ai];
    }

    public static function monthName(string $ym): string
    {
        global $LANG;
        $sl = ['januar', 'februar', 'marec', 'april', 'maj', 'junij', 'julij', 'avgust', 'september', 'oktober', 'november', 'december'];
        $en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        [$y, $m] = explode('-', $ym);
        return ($LANG === 'en' ? $en : $sl)[(int)$m - 1] . ' ' . $y;
    }

    /** HTML poročila (samostojna stran, primerna tudi za e-pošto in tisk v PDF) */
    public static function html(array $r, bool $forMail = false): string
    {
        ob_start();
        require APP_ROOT . '/views/report_doc.php';
        return (string)ob_get_clean();
    }

    /** Pošlje poročilo na naslove naročnika; vrne število uspešno poslanih */
    public static function send(int $tenantId, string $ym, ?array $to = null): int
    {
        $r = self::build($tenantId, $ym);
        if (!$r['devices']) return 0;
        $to ??= array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($r['tenant']['report_emails'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $html = self::html($r, true); $ok = 0;
        $subj = A('Mesečno poročilo o omrežju – {t} – {m}', ['t' => $r['tenant']['name'] ?? '', 'm' => self::monthName($ym)]);
        foreach ($to as $e) if (Notify::mail($e, $subj, $html)) $ok++;
        return $ok;
    }

    /** Cron: 1. v mesecu pošlje poročila za prejšnji mesec vsem naročnikom z vklopljenim pošiljanjem */
    public static function monthlyCron(): string
    {
        if ((int)date('j') > 5) return '';   // 1. v mesecu; če takrat ni uspelo, poskusi še do 5.
        $ym = date('Y-m', strtotime('first day of last month'));
        if (setting('report_sent') === $ym) return '';
        $n = 0;
        foreach (db()->query('SELECT id FROM tenants WHERE report_enabled=1 AND report_emails<>""')->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            try { Ai::reportSummary((int)$tid, $ym); } catch (\Throwable $e) { error_log('noc ai report: ' . $e->getMessage()); }   // AI odstavek (če je vklopljen)
            $n += self::send((int)$tid, $ym);
        }
        setting_set('report_sent', $ym);
        return "poročila $ym: $n";
    }
}
