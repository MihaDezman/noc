<?php
declare(strict_types=1);

/** Stikala: L2 podatki iz pusha (bridge porti, VLAN-i, PoE, MAC tabela), uplink, naprava na portu */
final class SwitchIngest
{
    /** Fizični port stikala (ne bridge, vlan, wg …) */
    public static function isPort(string $name): bool { return (bool)preg_match('/^(ether|sfp|combo|qsfp)/', $name); }

    /** RouterOS seznam "a;b" ali "a,b" -> ['a','b'] */
    public static function split(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;,]/', $s)), fn($x) => $x !== ''));
    }

    /** VLAN ID-ji iz "10,20-22" -> [10,20,21,22] (največ 4094) */
    public static function ids(string $s): array
    {
        $out = [];
        foreach (self::split($s) as $p) {
            if (preg_match('/^(\d+)-(\d+)$/', $p, $m)) { for ($i = (int)$m[1]; $i <= min((int)$m[2], 4094) && count($out) < 4094; $i++) $out[] = $i; }
            elseif (ctype_digit($p) && (int)$p >= 1 && (int)$p <= 4094) $out[] = (int)$p;
        }
        return array_values(array_unique($out));
    }

    /** Način porta iz VLAN tabele: trunk = tagiran v kakšnem VLAN-u, access = samo netagiran */
    public static function mode(string $port, array $vlans): string
    {
        $t = false; $u = false;
        foreach ($vlans as $v) {
            if (in_array($port, $v['tagged'], true)) $t = true;
            if (in_array($port, $v['untagged'], true)) $u = true;
        }
        return $t ? 'trunk' : ($u ? 'access' : 'none');
    }

    /** Zapis L2 podatkov stikala (kliče se znotraj transakcije pusha) */
    public static function apply(array $d, array $sw, int $now): void
    {
        $pdo = db(); $id = (int)$d['id']; $at = date('Y-m-d H:i:s', $now);
        $cut = fn($v, int $n) => mb_substr(ros_clean((string)$v), 0, $n);

        // --- VLAN tabela (dejansko stanje: current-*, sicer nastavljeno)
        $vlans = []; $rows = [];
        foreach (array_slice((array)($sw['bv'] ?? []), 0, 512) as $v) {
            if (!is_array($v)) continue;
            $br = $cut($v['b'] ?? '', 64); $dyn = ($v['d'] ?? '') === 'true' ? 1 : 0;
            $tg = self::split((string)(($v['ct'] ?? '') !== '' ? $v['ct'] : ($v['t'] ?? '')));
            $ut = self::split((string)(($v['cu'] ?? '') !== '' ? $v['cu'] : ($v['u'] ?? '')));
            foreach (array_slice(self::ids((string)($v['id'] ?? '')), 0, 64) as $vid) {
                $k = "$br/$vid";
                $vlans[$k] = ['bridge' => $br, 'vid' => $vid, 'tagged' => array_values(array_unique(array_merge($vlans[$k]['tagged'] ?? [], $tg))), 'untagged' => array_values(array_unique(array_merge($vlans[$k]['untagged'] ?? [], $ut))),
                    'comment' => ($vlans[$k]['comment'] ?? '') ?: $cut($v['c'] ?? '', 255), 'dynamic' => ($vlans[$k]['dynamic'] ?? 1) && $dyn ? 1 : 0];
            }
        }
        $pdo->prepare('DELETE FROM sw_vlans WHERE device_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO sw_vlans (device_id, bridge, vlan_id, comment, tagged, untagged, dynamic) VALUES (?,?,?,?,?,?,?)');
        foreach ($vlans as $v) $ins->execute([$id, $v['bridge'], $v['vid'], $v['comment'], mb_substr(implode(',', $v['tagged']), 0, 1000), mb_substr(implode(',', $v['untagged']), 0, 1000), $v['dynamic']]);

        // --- MAC tabela (vsakih 5 min, po kosih): zapis in čiščenje starih
        if (isset($sw['h']) && is_array($sw['h'])) {
            $up = $pdo->prepare('INSERT INTO sw_hosts (device_id, mac, vid, port, seen_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE port=VALUES(port), seen_at=VALUES(seen_at)');
            foreach (array_slice($sw['h'], 0, 300) as $h) {
                $mac = strtoupper((string)($h['m'] ?? ''));
                if (!is_array($h) || !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) continue;
                $up->execute([$id, $mac, max(0, min(4094, (int)($h['v'] ?? 0))), $cut($h['i'] ?? '', 64), $at]);
            }
            $pdo->prepare('DELETE FROM sw_hosts WHERE device_id=? AND seen_at < ?')->execute([$id, date('Y-m-d H:i:s', $now - 3600)]);
        }
        $hosts = [];
        $st = $pdo->prepare('SELECT port, COUNT(*) n, MIN(mac) mac FROM sw_hosts WHERE device_id=? GROUP BY port'); $st->execute([$id]);
        foreach ($st as $r) $hosts[$r['port']] = $r;

        // --- PoE (vsakih 5 min; sicer ostane zadnje znano)
        $poe = null;
        if (isset($sw['poe']) && is_array($sw['poe'])) {
            $poe = [];
            foreach (array_slice($sw['poe'], 0, 128) as $p) if (is_array($p) && !empty($p['i'])) {
                $w = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', (string)($p['w'] ?? '')));
                $poe[(string)$p['i']] = [$cut($p['m'] ?? '', 20), $cut($p['st'] ?? '', 40), $w !== '' && is_numeric($w) ? min(999.9, (float)$w) : null];
            }
        }
        $old = [];
        $st = $pdo->prepare('SELECT port, poe_mode, poe_status, poe_w FROM sw_ports WHERE device_id=?'); $st->execute([$id]);
        foreach ($st as $r) $old[$r['port']] = $r;

        // --- bridge porti
        $vl = array_values($vlans); $seen = [];
        $ins = $pdo->prepare('INSERT INTO sw_ports (device_id, port, bridge, pvid, frame_types, mode, untagged, tagged, poe_mode, poe_status, poe_w, stp_role, stp_state, edge, hosts_n, host_mac, updated_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bridge=VALUES(bridge), pvid=VALUES(pvid), frame_types=VALUES(frame_types), mode=VALUES(mode), untagged=VALUES(untagged), tagged=VALUES(tagged),
                              poe_mode=VALUES(poe_mode), poe_status=VALUES(poe_status), poe_w=VALUES(poe_w), stp_role=VALUES(stp_role), stp_state=VALUES(stp_state), edge=VALUES(edge), hosts_n=VALUES(hosts_n), host_mac=VALUES(host_mac), updated_at=VALUES(updated_at)');
        foreach (array_slice((array)($sw['bp'] ?? []), 0, 256) as $p) {
            if (!is_array($p) || empty($p['i'])) continue;
            $port = $cut($p['i'], 64); if (isset($seen[$port])) continue; $seen[$port] = true;
            $ut = []; $tg = [];
            foreach ($vl as $v) { if (in_array($port, $v['untagged'], true)) $ut[] = $v['vid']; if (in_array($port, $v['tagged'], true)) $tg[] = $v['vid']; }
            sort($ut); sort($tg);
            $pe = $poe !== null ? ($poe[$port] ?? ['', '', null]) : [$old[$port]['poe_mode'] ?? '', $old[$port]['poe_status'] ?? '', $old[$port]['poe_w'] ?? null];
            $ho = $hosts[$port] ?? null;
            $ins->execute([$id, $port, $cut($p['b'] ?? '', 64), max(1, min(4094, (int)($p['pv'] ?? 1))), $cut($p['ft'] ?? '', 40), self::mode($port, $vl),
                implode(',', $ut), implode(',', $tg), $pe[0], $pe[1], $pe[2], $cut($p['r'] ?? '', 20), $cut($p['s'] ?? '', 20), ($p['e'] ?? '') === 'yes' || ($p['e'] ?? '') === 'true' ? 1 : 0,
                $ho ? min(65535, (int)$ho['n']) : 0, $ho ? (string)$ho['mac'] : '', $at]);
        }
        if ($seen) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $pdo->prepare("DELETE FROM sw_ports WHERE device_id=? AND port NOT IN ($in)")->execute(array_merge([$id], array_keys($seen)));
        } else $pdo->prepare('DELETE FROM sw_ports WHERE device_id=?')->execute([$id]);

        // --- upravljalni naslov (za terminal prek routerja)
        $mg = explode('/', (string)($sw['mg'] ?? ''))[0];
        if (filter_var($mg, FILTER_VALIDATE_IP) && (string)$d['mgmt_ip'] === '') $pdo->prepare('UPDATE devices SET mgmt_ip=? WHERE id=?')->execute([$mg, $id]);   // samo prazno: ročni vpis (terminal) ostane
    }

    /** Ali ima stikalo vklopljen bridge VLAN filtering (iz zadnjega pusha) */
    public static function vlanFiltering(array $d): bool
    {
        $s = $d['status'] ?? (json_decode((string)($d['status_json'] ?? ''), true) ?: []);
        return !empty($s['sw_vf']);
    }

    /**
     * Uplink: port, na katerem stikalo vidi MAC routerja-starša; sicer STP root port; sicer edini SFP z linkom.
     */
    public static function uplink(array $d): ?string
    {
        $pdo = db(); $id = (int)$d['id'];
        if (!empty($d['parent_id'])) {
            $st = $pdo->prepare("SELECT h.port FROM sw_hosts h JOIN device_ifaces i ON i.device_id=? AND i.mac<>'' AND UPPER(i.mac)=h.mac WHERE h.device_id=? GROUP BY h.port ORDER BY COUNT(*) DESC LIMIT 1");
            $st->execute([(int)$d['parent_id'], $id]);
            if ($p = $st->fetchColumn()) return (string)$p;
        }
        $st = $pdo->prepare("SELECT port FROM sw_ports WHERE device_id=? AND stp_role='root-port' LIMIT 1"); $st->execute([$id]);
        if ($p = $st->fetchColumn()) return (string)$p;
        $st = $pdo->prepare("SELECT name FROM device_ifaces WHERE device_id=? AND running=1 AND (name LIKE 'sfp%' OR name LIKE 'qsfp%') ORDER BY name"); $st->execute([$id]);
        $sfp = $st->fetchAll(PDO::FETCH_COLUMN);
        return count($sfp) === 1 ? (string)$sfp[0] : null;
    }

    /** Porti z minutnimi podatki: uplink + porti z obvestilom */
    public static function minutePorts(array $d): array
    {
        $p = Devices::list((string)($d['down_ifaces'] ?? ''));
        if ($u = self::uplink($d)) $p[] = $u;
        return array_values(array_unique($p));
    }

    /** Naprave po portih za celo stikalo (en poizvedba za MAC, ena za imena pri routerju): port => [n, mac, ip, name] */
    public static function hostsByPort(array $d): array
    {
        $st = db()->prepare('SELECT port, mac FROM sw_hosts WHERE device_id=? ORDER BY port, mac'); $st->execute([(int)$d['id']]);
        $by = []; $all = [];
        foreach ($st as $r) { $by[$r['port']][] = $r['mac']; $all[$r['mac']] = true; }
        $names = [];
        if ($all && !empty($d['parent_id'])) {
            foreach (array_chunk(array_keys($all), 500) as $ch) {
                $in = implode(',', array_fill(0, count($ch), '?'));
                $q = db()->prepare("SELECT UPPER(mac) mac, ip, COALESCE(NULLIF(label,''), NULLIF(hostname,''), NULLIF(vendor,''), '') nm, label<>'' lb FROM lan_hosts WHERE device_id=? AND UPPER(mac) IN ($in)");
                $q->execute(array_merge([(int)$d['parent_id']], $ch));
                foreach ($q as $r) $names[$r['mac']] = $r;
            }
        }
        $out = [];
        foreach ($by as $port => $macs) {
            $best = null;   // najprej naprava z lastnim imenom, nato s katerimkoli imenom
            foreach ($macs as $m) if (isset($names[$m]) && ($best === null || ((int)$names[$m]['lb'] > (int)$best['lb']))) $best = $names[$m];
            $out[$port] = ['n' => count($macs), 'mac' => $best['mac'] ?? $macs[0], 'ip' => (string)($best['ip'] ?? ''), 'name' => (string)($best['nm'] ?? '')];
        }
        return $out;
    }

    /**
     * Naprava na portu: število MAC naslovov, prvi MAC in (iz DHCP najemov routerja-starša) IP in ime.
     * @return array{n:int, mac:string, ip:string, name:string}
     */
    public static function hostOn(array $d, string $port): array
    {
        $st = db()->prepare('SELECT mac FROM sw_hosts WHERE device_id=? AND port=? ORDER BY mac'); $st->execute([(int)$d['id'], $port]);
        $macs = $st->fetchAll(PDO::FETCH_COLUMN);
        $out = ['n' => count($macs), 'mac' => (string)($macs[0] ?? ''), 'ip' => '', 'name' => ''];
        if (!$macs || empty($d['parent_id'])) return $out;
        $in = implode(',', array_fill(0, count($macs), '?'));
        $st = db()->prepare("SELECT mac, ip, COALESCE(NULLIF(label,''), NULLIF(hostname,''), NULLIF(vendor,''), '') nm FROM lan_hosts WHERE device_id=? AND UPPER(mac) IN ($in) ORDER BY (NULLIF(label,'') IS NULL), last_seen DESC LIMIT 1");
        $st->execute(array_merge([(int)$d['parent_id']], $macs));
        if ($r = $st->fetch()) { $out['mac'] = strtoupper((string)$r['mac']); $out['ip'] = (string)$r['ip']; $out['name'] = (string)$r['nm']; }
        return $out;
    }
}
