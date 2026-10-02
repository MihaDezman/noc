<?php
declare(strict_types=1);

/**
 * Pregled požarnega zidu po pravilih dobre prakse (brez AI): bere zadnji nočni /export, vrne oceno in ugotovitve
 * s predlaganimi popravki. Izbrani popravki gredo na router z oznako "noc-fw", z varovalkama:
 * upravljalni naslovi so vedno dovoljeni, brez potrditve povezave z NOC se po 5 min vse povrne.
 */
final class Firewall
{
    /** Privzeto stanje storitev v RouterOS 7 (export pokaže samo spremembe) */
    private const SERVICE_DEFAULT_ON = ['telnet' => true, 'ftp' => true, 'www' => true, 'ssh' => true, 'api' => true, 'api-ssl' => true, 'winbox' => true, 'www-ssl' => false];

    /** Vir: zadnji backup konfiguracije ali /export ob dodajanju */
    public static function source(array $d): array
    {
        $st = db()->prepare('SELECT content, created_at FROM config_backups WHERE device_id=? ORDER BY created_at DESC LIMIT 1');
        $st->execute([$d['id']]); $b = $st->fetch();
        if ($b) return [$b['content'], $b['created_at']];
        return [(string)($d['export_raw'] ?? ''), $d['created_at']];
    }

    /** Prebere konfiguracijo v strukturo za preverjanja */
    public static function read(string $rsc): array
    {
        $c = ['lists' => [], 'input' => [], 'forward' => [], 'services' => [], 'mac' => null, 'macwinbox' => null, 'neighbor' => null,
              'dns_remote' => 'no', 'upnp' => 'no', 'proxy' => 'no', 'socks' => 'no', 'bw' => null, 'hotspot' => false];
        foreach (self::SERVICE_DEFAULT_ON as $n => $on) $c['services'][$n] = ['on' => $on, 'address' => ''];
        $c['vpn'] = [];   // [opis, protocol, port] – VPN strežniki, ki morajo ostati dostopni z WAN
        foreach (MikrotikExport::parse($rsc) as [$path, $verb, $kv, $raw]) {
            if (($kv['disabled'] ?? 'no') === 'yes' && $verb === 'add') continue;
            switch ($path) {
                case '/interface wireguard':
                    if ($verb === 'add') $c['vpn'][] = ['WireGuard ' . ($kv['name'] ?? ''), 'udp', (string)($kv['listen-port'] ?? '13231')];
                    break;
                case '/interface l2tp-server server':
                    if ($verb === 'set' && ($kv['enabled'] ?? 'no') === 'yes') { $c['vpn'][] = ['L2TP', 'udp', '1701']; $c['vpn'][] = ['IPsec', 'udp', '500,4500']; $c['vpn'][] = ['IPsec ESP', 'ipsec-esp', '']; }
                    break;
                case '/interface pptp-server server':
                    if ($verb === 'set' && ($kv['enabled'] ?? 'no') === 'yes') { $c['vpn'][] = ['PPTP', 'tcp', '1723']; $c['vpn'][] = ['PPTP GRE', 'gre', '']; }
                    break;
                case '/interface sstp-server server':
                    if ($verb === 'set' && ($kv['enabled'] ?? 'no') === 'yes') $c['vpn'][] = ['SSTP', 'tcp', (string)($kv['port'] ?? '443')];
                    break;
                case '/interface ovpn-server server':
                    if (($verb === 'set' || $verb === 'add') && ($kv['disabled'] ?? 'no') !== 'yes' && ($verb === 'add' || ($kv['enabled'] ?? 'no') === 'yes'))
                        $c['vpn'][] = ['OpenVPN', ($kv['protocol'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp', (string)($kv['port'] ?? '1194')];
                    break;
                case '/ip ipsec peer':
                    if ($verb === 'add' && ($kv['passive'] ?? 'no') === 'yes') { $c['vpn'][] = ['IPsec', 'udp', '500,4500']; $c['vpn'][] = ['IPsec ESP', 'ipsec-esp', '']; }
                    break;
                case '/interface list member': if ($verb === 'add' && isset($kv['list'])) $c['lists'][$kv['list']][] = $kv['interface'] ?? ''; break;
                case '/interface list': if ($verb === 'add' && isset($kv['name'])) $c['lists'][$kv['name']] ??= []; break;
                case '/ip firewall filter':
                    if ($verb === 'add' && in_array($kv['chain'] ?? '', ['input', 'forward'], true)) $c[$kv['chain']][] = $kv;
                    break;
                case '/ip service':
                    if ($verb !== 'set') break;
                    $name = $kv['@find.name'] ?? (preg_match('/^([a-z][a-z-]*)\s/', $raw, $m) ? $m[1] : '');
                    if ($name === '') break;
                    $c['services'][$name] ??= ['on' => true, 'address' => ''];
                    if (isset($kv['disabled'])) $c['services'][$name]['on'] = $kv['disabled'] !== 'yes';
                    if (isset($kv['address'])) $c['services'][$name]['address'] = $kv['address'];
                    break;
                case '/tool mac-server': if ($verb === 'set' && isset($kv['allowed-interface-list'])) $c['mac'] = $kv['allowed-interface-list']; break;
                case '/tool mac-server mac-winbox': if ($verb === 'set' && isset($kv['allowed-interface-list'])) $c['macwinbox'] = $kv['allowed-interface-list']; break;
                case '/ip neighbor discovery-settings': if ($verb === 'set' && isset($kv['discover-interface-list'])) $c['neighbor'] = $kv['discover-interface-list']; break;
                case '/ip dns': if ($verb === 'set' && isset($kv['allow-remote-requests'])) $c['dns_remote'] = $kv['allow-remote-requests']; break;
                case '/ip upnp': if ($verb === 'set' && isset($kv['enabled'])) $c['upnp'] = $kv['enabled']; break;
                case '/ip proxy': if ($verb === 'set' && isset($kv['enabled'])) $c['proxy'] = $kv['enabled']; break;
                case '/ip socks': if ($verb === 'set' && isset($kv['enabled'])) $c['socks'] = $kv['enabled']; break;
                case '/tool bandwidth-server': if ($verb === 'set' && isset($kv['enabled'])) $c['bw'] = $kv['enabled']; break;
                case '/ip hotspot': if ($verb === 'add') $c['hotspot'] = true; break;
            }
        }
        return $c;
    }

    /** Ali pravilo velja za promet z WAN */
    private static function fromWan(array $r, string $wan): bool
    {
        $il = $r['in-interface-list'] ?? null; $ii = $r['in-interface'] ?? null;
        if ($il === 'WAN' || $il === '!LAN') return true;
        if ($ii !== null && trim($ii, '"') === $wan) return true;
        return $il === null && $ii === null;   // brez omejitve vmesnika = velja za vse, tudi WAN
    }

    private static function isDrop(array $r): bool { return in_array($r['action'] ?? 'accept', ['drop', 'reject'], true); }

    /** [ocena 0-10, ugotovitve[]] */
    public static function audit(array $d): array
    {
        [$rsc, $at] = self::source($d);
        if (trim($rsc) === '') return ['score' => null, 'at' => null, 'findings' => [], 'wanSel' => ''];
        $c = self::read($rsc); $wan = (string)$d['wan_iface'];
        $hasWanList = isset($c['lists']['WAN']); $hasLanList = isset($c['lists']['LAN']);
        $wanSel = $hasWanList ? 'in-interface-list=WAN' : 'in-interface="' . $wan . '"';
        $f = [];
        $add = function (string $id, string $sev, string $title, string $why, bool $fixable = true, string $note = '') use (&$f) { $f[$id] = compact('id', 'sev', 'title', 'why', 'fixable', 'note'); };

        $hasEst = fn(array $rules) => (bool)array_filter($rules, fn($r) => ($r['action'] ?? 'accept') === 'accept' && str_contains($r['connection-state'] ?? '', 'established'));
        $hasInvalid = fn(array $rules) => (bool)array_filter($rules, fn($r) => self::isDrop($r) && str_contains($r['connection-state'] ?? '', 'invalid'));

        // --- input
        $inputDrop = (bool)array_filter($c['input'], fn($r) => self::isDrop($r) && !isset($r['protocol']) && !isset($r['dst-port']) && !isset($r['connection-state']) && !isset($r['src-address']) && !isset($r['src-address-list']) && self::fromWan($r, $wan));
        if (!$inputDrop) $add('input_drop', 'critical', A('Router je z interneta dostopen na vseh portih'), A('V verigi input ni pravila, ki bi zavrglo promet z WAN. Odprte storitve (Winbox, SSH, DNS …) so dosegljive od kjerkoli.'));
        if (!$hasEst($c['input'])) $add('input_est', 'warning', A('Input nima pravila za vzpostavljene povezave'), A('Brez "accept established,related" router ne prejme odgovorov na lastne povezave, ko je dodan končni drop. Doda se samodejno skupaj z zaščito.'));
        if (!$hasInvalid($c['input'])) $add('input_invalid', 'info', A('Input ne zavrže neveljavnih paketov'), A('Pravilo "drop invalid" zavrže pakete, ki ne pripadajo nobeni povezavi.'));

        // --- forward
        $fwdNat = (bool)array_filter($c['forward'], fn($r) => self::isDrop($r) && str_contains($r['connection-nat-state'] ?? '', '!dstnat') && self::fromWan($r, $wan));
        $fwdDrop = $fwdNat || (bool)array_filter($c['forward'], fn($r) => self::isDrop($r) && !isset($r['protocol']) && !isset($r['connection-state']) && self::fromWan($r, $wan) && (isset($r['in-interface-list']) || isset($r['in-interface'])));
        if (!$fwdDrop) $add('forward_wan', 'critical', A('Omrežje za routerjem ni zaščiteno pred internetom'), A('V verigi forward ni pravila "drop new from WAN not dst-nated". Nove povezave z interneta lahko dosežejo naprave v LAN-u.'));
        if (!$hasInvalid($c['forward'])) $add('forward_invalid', 'info', A('Forward ne zavrže neveljavnih paketov'), A('Pravilo "drop invalid" v forward je del privzete konfiguracije MikroTik.'));

        // --- DNS
        $dns53 = (bool)array_filter($c['input'], fn($r) => self::isDrop($r) && ($r['dst-port'] ?? '') === '53');
        if ($c['dns_remote'] === 'yes' && !$inputDrop && !$dns53) $add('dns_open', 'critical', A('DNS strežnik routerja je odprt na internet'), A('Odprt DNS je mogoče zlorabiti za DDoS napade na tretje osebe; ponudnik lahko zato blokira linijo.'));

        // --- storitve
        foreach (['telnet' => A('Telnet (nešifriran)'), 'ftp' => A('FTP (nešifriran)')] as $n => $l)
            if ($c['services'][$n]['on'] ?? false) $add('svc_' . $n, 'warning', A('Vklopljen {s}', ['s' => $l]), A('Gesla gredo po omrežju v čistem besedilu. Storitev ni potrebna – za dostop uporabi Winbox ali SSH.'));
        if ($c['services']['www']['on'] ?? false) $add('svc_www', 'warning', A('Vklopljen WebFig prek HTTP'), A('Spletni vmesnik brez šifriranja. Če ga ne uporabljaš, ga izklopi (Winbox in SSH ostaneta).'), true, $c['hotspot'] ? A('Na routerju je HotSpot – preveri, da prijavna stran deluje tudi brez te storitve.') : '');
        foreach (['api', 'api-ssl'] as $n) if (($c['services'][$n]['on'] ?? false) && ($c['services'][$n]['address'] ?? '') === '')
            $add('svc_' . $n, 'warning', A('Vklopljen RouterOS API ({s}) brez omejitve naslovov', ['s' => $n]), A('API omogoča popoln nadzor routerja. NOC ga ne potrebuje; izklopi ga, če ga ne uporablja drug program.'));
        foreach (['winbox', 'ssh'] as $n) if (($c['services'][$n]['on'] ?? false) && ($c['services'][$n]['address'] ?? '') === '')
            $add('svc_' . $n . '_addr', $inputDrop ? 'info' : 'warning', A('{s} sprejema prijave z vseh naslovov', ['s' => $n === 'winbox' ? 'Winbox' : 'SSH']), A('Omeji dostop na tvoje upravljalne naslove in zasebna omrežja (LAN, VPN) – napadalci z interneta potem sploh ne pridejo do prijave.'));

        // --- odkrivanje in ostalo
        if ($hasLanList && $c['mac'] !== 'LAN') $add('mac_server', 'warning', A('MAC Telnet dostopen z vseh vmesnikov'), A('MAC strežnik naj bo dostopen samo iz LAN-a.'));
        if ($hasLanList && $c['macwinbox'] !== 'LAN') $add('mac_winbox', 'warning', A('MAC Winbox dostopen z vseh vmesnikov'), A('Winbox prek MAC naslova naj bo dostopen samo iz LAN-a.'));
        if ($hasLanList && $c['neighbor'] !== 'LAN') $add('neighbor', 'warning', A('Router se oglaša tudi proti ponudniku'), A('Neighbor discovery (MNDP/CDP/LLDP) naj deluje samo v LAN-u – sicer razkriva model in verzijo.'));
        if ($c['upnp'] === 'yes') $add('upnp', 'warning', A('Vklopljen UPnP'), A('Naprave v LAN-u lahko same odpirajo porte na internet. Izklopi, razen če ga potrebujejo igralne konzole.'));
        if ($c['proxy'] === 'yes') $add('proxy', 'warning', A('Vklopljen web proxy'), A('Odprt proxy je pogosta tarča zlorabe.'));
        if ($c['socks'] === 'yes') $add('socks', 'warning', A('Vklopljen SOCKS'), A('SOCKS strežnik ni potreben in je lahko zlorabljen.'));
        if ($c['bw'] !== 'no') $add('bwserver', 'info', A('Vklopljen Bandwidth test strežnik'), A('Uporablja se samo za meritve; izklopi, ko ga ne rabiš.'));

        if (isset($f['input_drop']) && $c['vpn']) {
            $names = array_values(array_unique(array_map(fn($v) => $v[0] . ($v[2] !== '' ? ' ' . $v[2] : ''), $c['vpn'])));
            $f['input_drop']['note'] = A('Najdeni VPN strežniki ostanejo dostopni: {v}', ['v' => implode(', ', $names)]);
        }
        $score = 10;
        foreach ($f as $x) $score -= ['critical' => 3, 'warning' => 0.5, 'info' => 0][$x['sev']];
        uasort($f, fn($a, $b) => ['critical' => 0, 'warning' => 1, 'info' => 2][$a['sev']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b['sev']]);
        return ['score' => max(0, (int)round($score)), 'at' => $at, 'findings' => $f, 'wanSel' => $wanSel, 'conf' => $c];
    }

    // ------------------------------------------------------------------ popravki
    private static function top(string $menu, string $args): string
    {
        return ":do { $menu add $args place-before=([$menu find where dynamic=no]->0) } on-error={ $menu add $args }\n";
    }

    public static function mgmtIps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', setting('mgmt_ips', ''))), fn($x) => preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $x)));
    }

    /** [uveljavi, povrni] za izbrane popravke */
    private static function build(array $d, array $ids, array $orig): array
    {
        $a = Firewall::audit($d); $ws = $a['wanSel'] ?: 'in-interface="' . $d['wan_iface'] . '"';
        $c = $a['conf'] ?? ['input' => [], 'forward' => [], 'vpn' => []];
        $hasEst = fn(array $rules) => (bool)array_filter($rules, fn($r) => ($r['action'] ?? 'accept') === 'accept' && str_contains($r['connection-state'] ?? '', 'established'));
        $hasInv = fn(array $rules) => (bool)array_filter($rules, fn($r) => self::isDrop($r) && str_contains($r['connection-state'] ?? '', 'invalid'));
        $ap = ''; $rb = '';
        $sel = fn($id) => in_array($id, $ids, true);
        // vrstni red: pravila, dodana z top(), pristanejo na vrhu v obratnem vrstnem redu
        if ($sel('forward_wan')) $ap .= self::top('/ip firewall filter', "chain=forward connection-state=new connection-nat-state=!dstnat $ws action=drop comment=\"noc-fw: nove povezave z WAN\"");
        // obstoječe "accept established" (in fasttrack pred njim) ostane nedotaknjeno – dodamo samo, če ga ni
        if (($sel('forward_invalid') || $sel('forward_wan')) && !$hasInv($c['forward'])) $ap .= self::top('/ip firewall filter', 'chain=forward connection-state=invalid action=drop comment="noc-fw: invalid"');
        if ($sel('forward_wan') && !$hasEst($c['forward'])) $ap .= self::top('/ip firewall filter', 'chain=forward connection-state=established,related,untracked action=accept comment="noc-fw: vzpostavljene povezave"');
        if ($sel('dns_open')) foreach (['udp', 'tcp'] as $p) $ap .= self::top('/ip firewall filter', "chain=input $ws protocol=$p dst-port=53 action=drop comment=\"noc-fw: DNS ni odprt na WAN\"");
        if (($sel('input_invalid') || $sel('input_drop')) && !$hasInv($c['input'])) $ap .= self::top('/ip firewall filter', 'chain=input connection-state=invalid action=drop comment="noc-fw: invalid"');
        if (($sel('input_est') || $sel('input_drop')) && !$hasEst($c['input'])) $ap .= self::top('/ip firewall filter', 'chain=input connection-state=established,related,untracked action=accept comment="noc-fw: vzpostavljene povezave"');
        if ($sel('input_drop')) {
            $ap .= self::top('/ip firewall filter', 'chain=input protocol=icmp action=accept comment="noc-fw: ICMP"');
            // VPN strežniki na routerju morajo ostati dostopni z interneta
            $seenVpn = [];
            foreach ($c['vpn'] as [$what, $proto, $port]) {
                $k = "$proto/$port"; if (isset($seenVpn[$k])) continue; $seenVpn[$k] = true;
                $ap .= "/ip firewall filter add chain=input protocol=$proto" . ($port !== '' ? " dst-port=$port" : '') . " action=accept comment=\"noc-fw: VPN $what\"\n";
            }
            $ap .= "/ip firewall filter add chain=input $ws action=drop comment=\"noc-fw: vse ostalo z WAN\"\n";   // na konec
        }
        // zasebna omrežja (LAN, VLAN-i, WireGuard) + upravljalni naslovi – tako se ne zakleneš tudi prek VPN tunela
        $svcAddr = implode(',', array_unique(array_merge(self::mgmtIps(), ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'])));
        foreach (['telnet', 'ftp', 'www', 'api', 'api-ssl'] as $n) if ($sel('svc_' . $n)) { $ap .= "/ip service set $n disabled=yes\n"; $rb .= "/ip service set $n disabled=" . (($orig['svc'][$n]['on'] ?? true) ? 'no' : 'yes') . "\n"; }
        foreach (['winbox', 'ssh'] as $n) if ($sel('svc_' . $n . '_addr') && $svcAddr !== '') { $ap .= "/ip service set $n address=$svcAddr\n"; $rb .= "/ip service set $n address=\"" . ($orig['svc'][$n]['address'] ?? '') . "\"\n"; }
        if ($sel('mac_server')) { $ap .= "/tool mac-server set allowed-interface-list=LAN\n"; $rb .= "/tool mac-server set allowed-interface-list=" . ($orig['mac'] ?? 'all') . "\n"; }
        if ($sel('mac_winbox')) { $ap .= "/tool mac-server mac-winbox set allowed-interface-list=LAN\n"; $rb .= "/tool mac-server mac-winbox set allowed-interface-list=" . ($orig['macwinbox'] ?? 'all') . "\n"; }
        if ($sel('neighbor')) { $ap .= "/ip neighbor discovery-settings set discover-interface-list=LAN\n"; $rb .= "/ip neighbor discovery-settings set discover-interface-list=\"" . ($orig['neighbor'] ?? '!dynamic') . "\"\n"; }
        foreach (['upnp' => '/ip upnp', 'proxy' => '/ip proxy', 'socks' => '/ip socks', 'bwserver' => '/tool bandwidth-server'] as $id => $menu)
            if ($sel($id)) { $ap .= "$menu set enabled=no\n"; $rb .= "$menu set enabled=yes\n"; }
        return [$ap, $rb];
    }

    /** Del namestitvenega paketa */
    public static function rsc(array $d): string
    {
        $fx = json_decode((string)($d['fw_fixes'] ?? ''), true) ?: [];
        $ids = $fx['ids'] ?? []; $orig = $fx['orig'] ?? [];
        $s = "# --- pozarni zid (NOC): odstrani prejsnja pravila noc-fw\n/ip firewall filter remove [find comment~\"^noc-fw\"]\n/ip firewall address-list remove [find list=noc-mgmt]\n";
        if (!$ids) return $s . "\n";
        [$ap, $rb] = self::build($d, $ids, $orig);
        $base = rtrim((string)cfg('base_url'), '/'); $key = dec($d['api_key_enc'] ?? null); $pol = MikrotikScript::POLICY;
        $rollback = "/ip firewall filter remove [find comment~\"^noc-fw\"]\n/ip firewall address-list remove [find list=noc-mgmt]\n" . $rb . ":log warning \"noc: pozarni zid povrnjen na prejsnje stanje\"\n";
        $esc = fn($x) => str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $x);
        $s .= "# varovalka: brez potrditve povezave z NOC se vse povrne cez 5 minut\n"
            . ":global nocFwOk false\n"
            . "/system script add name=noc-fw-rollback policy=$pol comment=\"noc.dezman.net\" source=\"" . $esc($rollback) . "\"\n"
            . ":execute script=\":delay 300s; :global nocFwOk; :if (\\\$nocFwOk != true) do={ /system script run noc-fw-rollback }\"\n"
            . "# upravljalni naslovi so vedno dovoljeni\n";
        foreach (self::mgmtIps() as $ip) $s .= "/ip firewall address-list add list=noc-mgmt address=$ip comment=\"noc-fw\"\n";
        $s .= self::top('/ip firewall filter', 'chain=input src-address-list=noc-mgmt action=accept comment="noc-fw: upravljanje"');
        $s .= "# izbrani popravki\n" . $ap
            . ":delay 3s\n"
            . ":onerror e in={ /tool fetch url=\"$base/api/fw-confirm\" http-header-field=\"X-Api-Key: $key\" output=none; :set nocFwOk true; :log info \"noc: pozarni zid potrjen\" } do={ :log warning (\"noc: pozarni zid ni potrjen - povrnitev cez 5 min - \" . \$e) }\n\n";
        return $s;
    }

    /** Za noc-remove.rsc */
    public static function removeRsc(array $d): string
    {
        $fx = json_decode((string)($d['fw_fixes'] ?? ''), true) ?: [];
        if (empty($fx['ids'])) return "/ip firewall filter remove [find comment~\"^noc-fw\"]\n/ip firewall address-list remove [find list=noc-mgmt]\n";
        [, $rb] = self::build($d, $fx['ids'], $fx['orig'] ?? []);
        return "/ip firewall filter remove [find comment~\"^noc-fw\"]\n/ip firewall address-list remove [find list=noc-mgmt]\n" . $rb;
    }

    /** Shranjevanje izbire; prvotne vrednosti nastavitev se zapomnijo ob prvi izbiri (za povrnitev) */
    public static function save(array $d, array $ids): void
    {
        $a = self::audit($d); $old = json_decode((string)($d['fw_fixes'] ?? ''), true) ?: [];
        // dovoljeni: trenutne ugotovitve s popravkom + že uveljavljeni (ti se v pregledu ne pokažejo več, ker so odpravljeni)
        $allowed = array_merge(array_keys(array_filter($a['findings'], fn($x) => $x['fixable'])), $old['ids'] ?? []);
        $ids = array_values(array_unique(array_intersect($ids, $allowed)));
        $titles = $old['titles'] ?? [];
        foreach ($a['findings'] as $k => $x) $titles[$k] = $x['title'];
        $c = $a['conf'] ?? [];
        $orig = $old['orig'] ?? [];
        if (!$orig && $c) $orig = ['svc' => $c['services'], 'mac' => $c['mac'], 'macwinbox' => $c['macwinbox'], 'neighbor' => $c['neighbor']];
        db()->prepare('UPDATE devices SET fw_fixes=?, fw_confirmed_at=NULL WHERE id=?')->execute([json_encode(['ids' => $ids, 'orig' => $orig, 'titles' => $titles], JSON_UNESCAPED_UNICODE), $d['id']]);
    }
}
