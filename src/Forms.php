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
        $nets = implode(',', array_filter(array_map('trim', preg_split('/[\s,;]+/', post('lan_networks'))), fn($n) => preg_match('#^\d+\.\d+\.\d+\.\d+/\d{1,2}$#', $n)));
        $rx = fn($v) => preg_replace('/[^a-zA-Z0-9|,.;_\-]/', '', (string)$v);
        return [
            'name' => mb_substr(post('name') ?: 'MikroTik', 0, 120), 'site' => mb_substr(post('site'), 0, 160),
            'public_ip' => $ip(post('public_ip')), 'wan_iface' => mb_substr(post('wan_iface'), 0, 64), 'wan_gateway' => $ip(post('wan_gateway')),
            'ping_target' => preg_replace('/[^a-zA-Z0-9.\-]/', '', post('ping_target', '1.1.1.1')) ?: '1.1.1.1',
            'lan_networks' => $nets, 'monitor_ifaces' => $csv(post('monitor_ifaces')), 'down_ifaces' => $csv(post('down_ifaces')),
            'wan_down_mbps' => (int)post('wan_down_mbps') ?: null, 'wan_up_mbps' => (int)post('wan_up_mbps') ?: null,
            'flow_enabled' => post('flow_enabled') ? 1 : 0, 'notes' => post('notes'),
            'log_include' => $rx(post('log_include', 'critical|error|warning|system|account|interface|wireguard|health|script')),
            'log_exclude' => $rx(post('log_exclude', 'debug|packet|dhcp.info|hotspot.info')),
        ];
    }
}

final class Ufw
{
    public static function ips(): array
    {
        // vpisan javni IP in naslov, s katerega router dejansko pošilja push (router z več WAN naslovi)
        $ips = [];
        foreach (db()->query('SELECT public_ip, last_seen_ip FROM devices WHERE active=1 AND flow_enabled=1') as $r)
            foreach ([$r['public_ip'], $r['last_seen_ip']] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $ips[$ip] = true;   // samo javni naslovi
        $ips = array_keys($ips); sort($ips);
        return $ips;
    }
    /** Seznam IP-jev za bin/ufw-sync.sh (root cron odpre UDP flow_port samo za te naslove) */
    public static function write(): void
    {
        $f = (string)cfg('ufw_list'); if ($f === '') return;
        $new = implode("\n", self::ips()) . "\n";
        if (!is_file($f) || file_get_contents($f) !== $new) @file_put_contents($f, $new);   // piši samo ob spremembi
    }
}
