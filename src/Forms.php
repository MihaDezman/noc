<?php
declare(strict_types=1);

/** Branje obrazca naprave in seznam IP-jev za ufw */
final class DeviceForm
{
    public static function read(): array
    {
        $ip = fn($v) => filter_var($v, FILTER_VALIDATE_IP) ? $v : '';
        // seznami vmesnikov: ločilo je samo vejica (ali podpičje/nova vrstica) – RouterOS dovoli presledek v imenu vmesnika
        $csv = fn($v) => implode(',', array_unique(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', (string)$v)))));
        $nets = implode(',', LanWatch::nets(post('lan_networks')));   // naslov z masko (172.16.10.1/24) -> omrežje (172.16.10.0/24), brez podvojenih
        $rx = fn($v) => preg_replace('/[^a-zA-Z0-9|,.;_\-]/', '', (string)$v);
        $kind = post('kind') === 'switch' ? 'switch' : 'router';
        $parent = null;
        if ($kind === 'switch' && (int)post('parent_id')) {
            $st = db()->prepare("SELECT id FROM devices WHERE id=? AND kind='router'"); $st->execute([(int)post('parent_id')]);
            $parent = ($x = $st->fetchColumn()) ? (int)$x : null;
        }
        $f = [
            'kind' => $kind, 'parent_id' => $parent,
            'name' => mb_substr(ros_clean(post('name')) ?: 'MikroTik', 0, 120), 'site' => mb_substr(ros_clean(post('site')), 0, 160),
            'public_ip' => $ip(post('public_ip')), 'wan_iface' => mb_substr(trim(ros_clean(post('wan_iface'))), 0, 64), 'wan_gateway' => $ip(post('wan_gateway')),
            'gw_ping' => in_array(post('gw_ping'), ['auto', 'fixed', 'off'], true) ? post('gw_ping') : 'auto',
            'ping_target' => preg_replace('/[^a-zA-Z0-9.\-]/', '', post('ping_target', '1.1.1.1')) ?: '1.1.1.1',
            'lan_networks' => $nets, 'monitor_ifaces' => $csv(post('monitor_ifaces')), 'down_ifaces' => $csv(post('down_ifaces')),
            'wan_down_mbps' => (int)post('wan_down_mbps') ?: null, 'wan_up_mbps' => (int)post('wan_up_mbps') ?: null,
            'flow_enabled' => post('flow_enabled') ? 1 : 0, 'notes' => post('notes'),
            'log_include' => $rx(post('log_include', 'critical|error|warning|system|account|interface|wireguard|health|script')),
            'log_exclude' => $rx(post('log_exclude', 'debug|packet|dhcp.info|hotspot.info')),
        ];
        // stikalo: brez WAN, prehoda, LAN omrežij in traffic-flow (to ima router stranke)
        if ($kind === 'switch') $f = array_merge($f, ['public_ip' => '', 'wan_iface' => '', 'wan_gateway' => '', 'gw_ping' => 'off', 'lan_networks' => '', 'monitor_ifaces' => '', 'flow_enabled' => 0, 'wan_down_mbps' => null, 'wan_up_mbps' => null,
            'down_ifaces' => $csv(post('down_ifaces_sw', post('down_ifaces'))), 'mgmt_ip' => $ip(post('mgmt_ip'))]);
        return $f;
    }

    /** Privzete vrednosti (za notranjo rabo in teste) */
    public static function defaults(): array
    {
        return ['site' => '', 'public_ip' => '', 'wan_iface' => '', 'wan_gateway' => '', 'gw_ping' => 'off', 'ping_target' => '1.1.1.1', 'lan_networks' => '', 'monitor_ifaces' => '',
            'down_ifaces' => '', 'wan_down_mbps' => null, 'wan_up_mbps' => null, 'flow_enabled' => 0, 'notes' => ''];
    }
}

final class Ufw
{
    public static function ips(): array
    {
        // vpisan javni IP in naslov, s katerega router dejansko pošilja push (router z več WAN naslovi)
        $ips = [];
        foreach (db()->query('SELECT public_ip, last_seen_ip FROM devices WHERE active=1 AND (flow_enabled=1 OR fwlog_enabled=1)') as $r)
            foreach ([$r['public_ip'], $r['last_seen_ip']] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $ips[$ip] = true;   // samo javni naslovi
        $ips = array_keys($ips); sort($ips);
        return $ips;
    }
    /** Seznam IP-jev za bin/ufw-sync.sh (root cron odpre UDP flow_port samo za te naslove) */
    public static function write(): void
    {
        $f = (string)cfg('ufw_list'); if ($f === '') return;
        $new = implode("\n", self::ips()) . "\n";
        if (!is_file($f) || file_get_contents($f) !== $new) {   // piši samo ob spremembi; atomarno (root skripta ne sme prebrati pol datoteke)
            $tmp = $f . '.tmp'; if (@file_put_contents($tmp, $new) !== false) @rename($tmp, $f);
        }
    }
}
