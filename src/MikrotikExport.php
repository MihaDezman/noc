<?php
declare(strict_types=1);

/**
 * Analiza MikroTik /export (RouterOS 7): model, verzija, vmesniki, WAN/LAN, prehod, požarni zid, traffic-flow, obstoječi noc-*.
 * Vrne predlog nastavitev naprave + opozorila.
 */
final class MikrotikExport
{
    /** Razbije export v ukaze: [[path, verb, [k=>v], raw]] */
    public static function parse(string $rsc): array
    {
        $rsc = str_replace("\r", '', $rsc);
        $rsc = preg_replace("/\\\\\n\\s*/", '', $rsc);           // nadaljevanje vrstice
        $cmds = []; $path = '';
        foreach (explode("\n", $rsc) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if ($line[0] === '/') {
                // "/ip address" (glava) ali terse "/ip address add address=..."
                if (preg_match('#^(/[a-z0-9\-]+(?:\s+[a-z0-9\-]+)*?)\s+(add|set|remove)(?:\s+(.*))?$#i', $line, $m)) {
                    $path = trim(preg_replace('/\s+/', ' ', $m[1])); $cmds[] = [$path, strtolower($m[2]), self::kv($m[3] ?? ''), $m[3] ?? '']; continue;
                }
                $path = trim(preg_replace('/\s+/', ' ', $line)); continue;
            }
            if (preg_match('/^(add|set|remove)\b\s*(.*)$/', $line, $m)) $cmds[] = [$path, $m[1], self::kv($m[2]), $m[2]];
        }
        return $cmds;
    }

    /** k=v k2="v z presledki" [ find default-name=ether1 ] */
    private static function kv(string $s): array
    {
        $out = [];
        if (preg_match('/^\[\s*find\s+(.*?)\]\s*(.*)$/', $s, $m)) { foreach (self::kv($m[1]) as $k => $v) $out['@find.' . $k] = $v; $s = $m[2]; }
        preg_match_all('/([a-zA-Z0-9\-\.]+)=("((?:[^"\\\\]|\\\\.)*)"|\S+)/', $s, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) $out[$m[1]] = isset($m[3]) && $m[2][0] === '"' ? stripcslashes($m[3]) : $m[2];
        return $out;
    }

    public static function analyze(string $rsc): array
    {
        $r = ['model' => '', 'serial' => '', 'ros_version' => '', 'identity' => '', 'ifaces' => [], 'bridges' => [], 'vlans' => [],
              'addresses' => [], 'dhcp_clients' => [], 'pppoe' => [], 'gateway' => '', 'wan_iface' => '', 'public_ip' => '', 'lan_networks' => [],
              'lists' => [], 'wg' => [], 'needs_icmp_rule' => false, 'flow_existing' => false, 'flow_targets' => [], 'has_noc' => false,
              'dhcp_servers' => [], 'warnings' => [], 'facts' => [], 'dns' => ['servers' => '', 'remote' => 'no']];

        if (preg_match('/by RouterOS\s+([0-9.]+[a-z0-9]*)/i', $rsc, $m)) $r['ros_version'] = $m[1];
        if (preg_match('/^#\s*model\s*=\s*(.+)$/mi', $rsc, $m)) $r['model'] = trim($m[1]);
        if (preg_match('/^#\s*serial number\s*=\s*(.+)$/mi', $rsc, $m)) $r['serial'] = trim($m[1]);

        $renamed = []; $inputRules = []; $flowEnabled = false;
        foreach (self::parse($rsc) as [$path, $verb, $kv]) {
            switch ($path) {
                case '/system identity': if (isset($kv['name'])) $r['identity'] = $kv['name']; break;
                case '/interface ethernet':
                    if ($verb === 'set' && isset($kv['@find.default-name'])) { $def = $kv['@find.default-name']; $nm = $kv['name'] ?? $def; $renamed[$def] = $nm; $r['ifaces'][$nm] = ['type' => 'ether', 'default' => $def, 'comment' => $kv['comment'] ?? '']; }
                    break;
                case '/interface bridge': if ($verb === 'add' && isset($kv['name'])) $r['bridges'][] = $kv['name']; break;
                case '/interface vlan': if ($verb === 'add' && isset($kv['name'])) $r['vlans'][$kv['name']] = ['id' => $kv['vlan-id'] ?? '', 'on' => $kv['interface'] ?? '']; break;
                case '/interface wireguard': if ($verb === 'add' && isset($kv['name'])) $r['wg'][] = $kv['name']; break;
                case '/interface pppoe-client': if ($verb === 'add') $r['pppoe'][] = $kv['name'] ?? 'pppoe-out1'; break;
                case '/interface list member': if ($verb === 'add' && isset($kv['list'], $kv['interface'])) $r['lists'][$kv['list']][] = $kv['interface']; break;
                case '/ip address':
                    if ($verb === 'add' && isset($kv['address'])) $r['addresses'][] = ['address' => $kv['address'], 'iface' => $kv['interface'] ?? '', 'network' => $kv['network'] ?? '', 'disabled' => ($kv['disabled'] ?? 'no') === 'yes'];
                    break;
                case '/ip dhcp-client': if ($verb === 'add') $r['dhcp_clients'][] = $kv['interface'] ?? ''; break;
                case '/ip dhcp-server': if ($verb === 'add') $r['dhcp_servers'][] = ['name' => $kv['name'] ?? '', 'iface' => $kv['interface'] ?? '']; break;
                case '/ip route':
                    if ($verb === 'add' && in_array($kv['dst-address'] ?? '0.0.0.0/0', ['0.0.0.0/0'], true) && isset($kv['gateway']) && ($kv['disabled'] ?? 'no') !== 'yes' && !$r['gateway']) $r['gateway'] = $kv['gateway'];
                    break;
                case '/ip firewall filter': if ($verb === 'add' && ($kv['chain'] ?? '') === 'input') $inputRules[] = $kv; break;
                case '/ip dns':
                    if ($verb === 'set') { if (isset($kv['servers'])) $r['dns']['servers'] = $kv['servers']; if (isset($kv['allow-remote-requests'])) $r['dns']['remote'] = $kv['allow-remote-requests']; }
                    break;
                case '/ip traffic-flow': if ($verb === 'set' && ($kv['enabled'] ?? '') === 'yes') $flowEnabled = true; break;
                case '/ip traffic-flow target': if ($verb === 'add') $r['flow_targets'][] = ($kv['dst-address'] ?? '?') . ':' . ($kv['port'] ?? '2055'); break;
                case '/system script': case '/system scheduler': if (str_starts_with($kv['name'] ?? '', 'noc-')) $r['has_noc'] = true; break;
            }
        }
        $r['flow_existing'] = $flowEnabled && $r['flow_targets'];

        // --- WAN: interface list WAN > dhcp-client > pppoe > vmesnik, v čigar omrežju je prehod
        $wan = '';
        foreach (['WAN', 'wan', 'Internet', 'internet'] as $ln) if (!empty($r['lists'][$ln])) { $wan = $r['lists'][$ln][0]; break; }
        if (!$wan && $r['pppoe']) $wan = $r['pppoe'][0];
        if (!$wan && $r['dhcp_clients']) $wan = $r['dhcp_clients'][0];
        if (!$wan && filter_var($r['gateway'], FILTER_VALIDATE_IP)) {
            foreach ($r['addresses'] as $a) if (ip_in_cidr($r['gateway'], $a['address'])) { $wan = $a['iface']; break; }
        }
        if (!$wan && $r['gateway'] && !filter_var($r['gateway'], FILTER_VALIDATE_IP)) $wan = preg_replace('/^.*%/', '', $r['gateway']);
        if (!$wan && (isset($r['ifaces']['ether1']) || !$r['ifaces'])) { $wan = 'ether1'; $r['warnings'][] = 'WAN vmesnika ni bilo mogoče zanesljivo določiti – predpostavljen ether1, preveri.'; }
        $r['wan_iface'] = $wan;
        if (filter_var($r['gateway'], FILTER_VALIDATE_IP) === false && $r['gateway'] !== '') $r['gateway'] = preg_replace('/%.*$/', '', $r['gateway']);
        if (!filter_var($r['gateway'], FILTER_VALIDATE_IP)) $r['gateway'] = '';

        // --- javni IP in LAN omrežja
        foreach ($r['addresses'] as $a) {
            if ($a['disabled']) continue;
            [$ip, $bits] = array_pad(explode('/', $a['address']), 2, '32');
            $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($a['iface'] === $wan) { if (!$isPrivate && !$r['public_ip']) $r['public_ip'] = $ip; continue; }
            if ($isPrivate && !in_array($a['iface'], $r['wg'], true)) {
                $net = long2ip(ip2long($ip) & ((-1 << (32 - (int)$bits)) & 0xFFFFFFFF)) . '/' . $bits;
                $r['lan_networks'][$net] = $a['iface'];
            }
        }
        if (!$r['public_ip']) $r['warnings'][] = 'Javnega IP-ja na WAN vmesniku ni v konfiguraciji (DHCP ali NAT pred routerjem) – vpiši ga ročno, potreben je za ping s strežnika in traffic-flow.';
        if ($r['pppoe']) $r['warnings'][] = 'Router uporablja PPPoE (' . implode(', ', $r['pppoe']) . ') – WAN je PPPoE vmesnik.';

        // --- požarni zid: ali input sprejme ICMP?
        $icmpOk = false; $hasDrop = false;
        foreach ($inputRules as $k) {
            if (($k['disabled'] ?? 'no') === 'yes') continue;
            $act = $k['action'] ?? 'accept';
            if ($act === 'accept' && ($k['protocol'] ?? '') === 'icmp' && !isset($k['src-address']) && !isset($k['src-address-list']) && !isset($k['in-interface-list']) && !isset($k['in-interface'])) $icmpOk = true;
            if (in_array($act, ['drop', 'reject'], true) && !isset($k['protocol'])) $hasDrop = true;
        }
        $r['needs_icmp_rule'] = $hasDrop && !$icmpOk;

        // --- opozorila
        if ($r['ros_version'] !== '' && version_compare($r['ros_version'], '7.13', '<')) $r['warnings'][] = "RouterOS {$r['ros_version']} je prestar – skripta potrebuje 7.13 ali novejši (:serialize, /file read). Najprej nadgradi.";
        if ($r['has_noc']) $r['warnings'][] = 'Na routerju so že skripte noc-* – namestitev jih bo zamenjala.';
        if ($r['flow_existing']) $r['warnings'][] = 'Traffic-flow že pošilja na ' . implode(', ', $r['flow_targets']) . ' – NOC bo dodan kot dodaten cilj, obstoječe nastavitve ostanejo.';
        if (!$r['lan_networks']) $r['warnings'][] = 'V konfiguraciji ni zasebnih LAN omrežij – promet po napravah ne bo na voljo.';
        if (preg_match('/l3-hw-offloading=yes/', $rsc)) $r['warnings'][] = 'Vklopljen je L3 HW offload – promet, ki gre mimo CPU-ja, traffic-flow ne vidi (števci vmesnikov so pravilni).';

        // --- vmesniki za spremljanje: WAN + bridge/VLAN z LAN omrežji
        $mon = [$wan];
        foreach (array_unique(array_values($r['lan_networks'])) as $if) if ($if && !in_array($if, $mon, true)) $mon[] = $if;
        $r['monitor_ifaces'] = array_values(array_filter($mon));
        $r['lan_networks'] = array_keys($r['lan_networks']);

        // --- povzetek za prikaz
        $r['facts'] = array_filter([
            'Model' => $r['model'], 'Serijska' => $r['serial'], 'RouterOS' => $r['ros_version'], 'Ime' => $r['identity'],
            'WAN' => $wan, 'Prehod' => $r['gateway'] ?: ($r['dhcp_clients'] ? 'DHCP' : ''), 'Javni IP' => $r['public_ip'],
            'LAN omrežja' => implode(', ', $r['lan_networks']), 'Bridge' => implode(', ', $r['bridges']),
            'VLAN' => implode(', ', array_map(fn($n, $v) => "$n ({$v['id']})", array_keys($r['vlans']), $r['vlans'])),
            'WireGuard' => implode(', ', $r['wg']), 'DHCP strežniki' => implode(', ', array_column($r['dhcp_servers'], 'name')),
            'Ping s strežnika' => $r['needs_icmp_rule'] ? 'doda se pravilo v input' : 'že dovoljen',
            'DNS' => ($r['dns']['servers'] !== '' ? $r['dns']['servers'] : 'od ponudnika') . ($r['dns']['remote'] === 'yes' ? ' · za LAN' : ''),
        ]);
        return $r;
    }
}
