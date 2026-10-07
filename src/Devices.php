<?php
declare(strict_types=1);

/** Naprave: branje z upoštevanjem pravic, stanje, API ključi */
final class Devices
{
    /** Seznam naprav, ki jih uporabnik vidi; z naročnikom in številom odprtih alarmov */
    public static function all(?int $tenantId = null): array
    {
        [$w, $p] = Auth::deviceScope('d');
        $sql = "SELECT d.*, t.name tenant_name,
                  (SELECT MAX(FIELD(a.severity,'info','warning','critical')) FROM alerts a WHERE a.device_id=d.id AND a.ended_at IS NULL) alert_level,
                  (SELECT COUNT(*) FROM alerts a WHERE a.device_id=d.id AND a.ended_at IS NULL) alert_count
                FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1 AND $w";
        if ($tenantId) { $sql .= ' AND d.tenant_id=?'; $p[] = $tenantId; }
        $st = db()->prepare($sql . ' ORDER BY t.name, d.name'); $st->execute($p);
        return array_map([self::class, 'decorate'], $st->fetchAll());
    }

    /** Ena naprava ali 404 */
    public static function get(int $id): array
    {
        [$w, $p] = Auth::deviceScope('d');
        $st = db()->prepare("SELECT d.*, t.name tenant_name FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.id=? AND $w");
        $st->execute(array_merge([$id], $p));
        $d = $st->fetch();
        if (!$d) { http_response_code(404); exit(A('Naprava ne obstaja.')); }
        $d['alert_level'] = db()->query('SELECT MAX(FIELD(severity,"info","warning","critical")) FROM alerts WHERE ended_at IS NULL AND device_id=' . (int)$id)->fetchColumn();
        return self::decorate($d);
    }

    /** Doda izračunana polja: state (up/warn/down/new/muted), status (zadnji push), ifaces za panel */
    public static function decorate(array $d): array
    {
        $d['status'] = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        $off = max(2, (int)setting('th_offline_min', '3')) * 60;
        $age = $d['last_seen_at'] ? time() - strtotime($d['last_seen_at']) : null;
        $d['online'] = $age !== null && $age <= $off;
        $lvl = (int)($d['alert_level'] ?? 0);
        // "ne deluje" = samo dejanski izpad (router se ne javlja ali internet ne dela);
        // ostali alarmi, tudi kritični (šibek SFP, poln pool, napad …), pomenijo opozorilo – naprava deluje
        $outage = in_array((int)$d['id'], self::outageIds(), true);
        $d['state'] = $age === null ? 'new' : (!$d['online'] || $outage ? 'down' : ($lvl >= 2 ? 'warn' : 'up'));
        $d['muted'] = $d['mute_until'] && strtotime($d['mute_until']) > time();
        return $d;
    }

    public const OUTAGE_KEYS = ['offline', 'wan_down', 'ext_down'];

    /** Naprave z odprtim alarmom izpada (enkrat na zahtevo) */
    public static function outageIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $in = implode(',', array_fill(0, count(self::OUTAGE_KEYS), '?'));
            $st = db()->prepare("SELECT DISTINCT device_id FROM alerts WHERE ended_at IS NULL AND akey IN ($in)"); $st->execute(self::OUTAGE_KEYS);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        return $ids;
    }

    /** Bakreni SFP (RJ45) – nima optike, zato tudi meritev moči ne */
    public static function sfpCopper(array $sf): bool
    {
        return $sf['rx'] === null && $sf['tx'] === null
            && (bool)preg_match('/RJ\d*|RJ45|BASE-?T\b|COPPER|-T\b/i', $sf['part'] . ' ' . $sf['stype'] . ' ' . $sf['vendor']);
    }

    public static function ifaces(int $deviceId): array
    {
        $st = db()->prepare('SELECT * FROM device_ifaces WHERE device_id=? ORDER BY FIELD(type,"ether","sfp-sfpplus","bridge","vlan","wg") DESC, name');
        $st->execute([$deviceId]);
        $rows = $st->fetchAll();
        usort($rows, fn($a, $b) => strnatcasecmp(self::ifaceSortKey($a), self::ifaceSortKey($b)));
        return $rows;
    }
    private static function ifaceSortKey(array $i): string
    {
        $order = ['ether' => 1, 'sfp' => 1, 'combo' => 1, 'wlan' => 2, 'wifi' => 2, 'bridge' => 3, 'vlan' => 4, 'wg' => 5];
        $o = 9; foreach ($order as $k => $v) if (str_starts_with($i['type'], $k)) { $o = $v; break; }
        return $o . ' ' . $i['name'];   // presledek: številka skupine se ne zlije s številko na začetku imena
    }

    /** Fizična vrata (za prikaz sprednje plošče) */
    public static function ports(array $ifaces): array
    {
        return array_values(array_filter($ifaces, fn($i) => preg_match('/^(ether|sfp|combo|qsfp)/', $i['type']) || preg_match('/^(ether|sfp|combo|qsfp)/', $i['name'])));
    }

    public static function newKey(int $id): string
    {
        $key = random_token(24);
        db()->prepare('UPDATE devices SET api_key_hash=?, api_key_enc=? WHERE id=?')->execute([hash('sha256', $key), enc($key), $id]);
        return $key;
    }

    public static function byKey(string $key): ?array
    {
        if (strlen($key) < 20) return null;
        $st = db()->prepare('SELECT * FROM devices WHERE api_key_hash=? AND active=1'); $st->execute([hash('sha256', $key)]);
        return $st->fetch() ?: null;
    }

    public static function list(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($x) => $x !== ''));
    }

    public static function thresholds(array $d): array
    {
        $keys = ['th_cpu', 'th_cpu_min', 'th_temp', 'th_mem', 'th_hdd', 'th_gw_loss', 'th_gw_ms', 'th_ext_loss', 'th_ext_ms', 'th_ping_min', 'th_offline_min', 'th_host_gb_h', 'th_host_mbps', 'th_host_min', 'th_attack', 'th_attack_min', 'th_pool', 'th_backup_days', 'th_flap', 'th_flap_min'];
        $own = json_decode((string)($d['thresholds'] ?? ''), true) ?: [];
        $out = [];
        foreach ($keys as $k) $out[$k] = (isset($own[$k]) && $own[$k] !== '') ? (float)$own[$k] : (float)setting($k, '0');
        return $out;
    }

    public static function tenants(): array
    {
        if (Auth::isSuper()) return db()->query('SELECT t.*, (SELECT COUNT(*) FROM devices d WHERE d.tenant_id=t.id AND d.active=1) n FROM tenants t ORDER BY name')->fetchAll();
        $st = db()->prepare('SELECT t.*, (SELECT COUNT(*) FROM devices d WHERE d.tenant_id=t.id AND d.active=1) n FROM tenants t WHERE id=?');
        $st->execute([(int)(Auth::$user['tenant_id'] ?? 0)]);
        return $st->fetchAll();
    }
}
