<?php
declare(strict_types=1);

/**
 * WireGuard strežnik na routerju, ki ga ustvari NOC (ukaz prek /api/action – router ga izvede ob naslednjem pushu).
 *  - omrežje: prvo prosto 172.31.10.0/24, 172.31.11.0/24 … (ne prekriva se z LAN-i in VPN-i nobene naprave v NOC)
 *  - dostop "lan": vmesnik v seznam LAN (kot naprava v LAN-u, tudi upravljanje routerja, če ga LAN dovoljuje)
 *    dostop "net": samo omrežja LAN + DNS in ping routerja (brez upravljanja routerja)
 *  - pravila na routerju imajo oznako "noc-wg: <vmesnik> …"; odstraniti je mogoče samo strežnik, ki ga je ustvaril NOC
 */
final class WgServer
{
    public const MODES = ['lan', 'net'];

    public static function validName(string $n): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{1,30}$/', $n); }

    /** [začetek, konec] IPv4 omrežja ali null */
    private static function range(string $cidr): ?array
    {
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', trim($cidr), $m)) return null;
        $ip = ip2long($m[1]); $bits = isset($m[2]) ? (int)$m[2] : 32;
        if ($ip === false || $bits > 32) return null;
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $net = $ip & $mask;
        return [$net, $net | (~$mask & 0xFFFFFFFF)];
    }

    private static function overlaps(string $a, string $b): bool
    {
        $x = self::range($a); $y = self::range($b);
        return $x && $y && $x[0] <= $y[1] && $y[0] <= $x[1];
    }

    /** Vsa omrežja, ki jih poznamo pri napravah v NOC (LAN, WireGuard, čakajoči ukazi) */
    public static function usedNets(): array
    {
        $out = ['10.250.0.0/24'];   // tunel strežnika NOC
        foreach (db()->query('SELECT * FROM devices WHERE active=1')->fetchAll() as $d) {
            foreach (Devices::list((string)($d['lan_networks'] ?? '')) as $n) $out[] = $n;
            foreach (Devices::list((string)($d['filter_networks'] ?? '')) as $n) $out[] = $n;
            foreach (Wg::ifaces($d) as $w) if ($w['a'] !== '') $out[] = $w['a'];
        }
        foreach (db()->query("SELECT data FROM noc_actions WHERE kind='wgsrv' AND status IN ('approved','sent','done')")->fetchAll(PDO::FETCH_COLUMN) as $j)
            if (($n = (json_decode((string)$j, true) ?: [])['net'] ?? '') !== '') $out[] = $n;
        return array_values(array_unique(array_filter($out, fn($n) => self::range($n) !== null)));
    }

    /** Prvo prosto omrežje 172.31.N.0/24 (N od 10 naprej) */
    public static function nextNet(): string
    {
        $used = self::usedNets();
        for ($n = 10; $n <= 255; $n++) {
            $c = "172.31.$n.0/24";
            if (!array_filter($used, fn($u) => self::overlaps($c, $u))) return $c;
        }
        return '';
    }

    public static function nextPort(array $d): int
    {
        $used = array_map(fn($w) => (int)$w['p'], Wg::ifaces($d));
        for ($p = 13231; $p < 13300; $p++) if (!in_array($p, $used, true)) return $p;
        return 13231;
    }

    public static function nextName(array $d): string
    {
        $have = array_keys(Wg::ifaces($d));
        foreach (['wg-vpn', 'wg-vpn2', 'wg-vpn3', 'wg-vpn4'] as $n) if (!in_array($n, $have, true)) return $n;
        return 'wg-vpn' . random_int(5, 99);
    }

    /** Čakajoč ukaz za napravo (ustvarjanje ali odstranitev) */
    public static function pending(int $deviceId): array
    {
        $st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND kind IN ('wgsrv','wgdel') AND status IN ('approved','sent') ORDER BY id"); $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    /** Vmesniki, ki jih je ustvaril NOC (in še niso odstranjeni) */
    public static function managed(int $deviceId): array
    {
        $st = db()->prepare("SELECT kind, ip, data FROM noc_actions WHERE device_id=? AND kind IN ('wgsrv','wgdel') AND status='done' ORDER BY id"); $st->execute([$deviceId]);
        $out = [];
        foreach ($st->fetchAll() as $r) { if ($r['kind'] === 'wgsrv') $out[$r['ip']] = json_decode((string)$r['data'], true) ?: []; else unset($out[$r['ip']]); }
        return $out;
    }

    /** Zahteva za nov strežnik; vrne id ukaza ali napako */
    public static function create(array $d, string $name, string $net, int $port, string $mode, string $by): int|string
    {
        if (!self::validName($name)) return A('ime vmesnika: črke, številke, - in _ (do 30 znakov)');
        if (isset(Wg::ifaces($d)[$name])) return A('vmesnik {n} na routerju že obstaja', ['n' => $name]);
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $net, $m) || (int)$m[2] < 16 || (int)$m[2] > 29) return A('omrežje mora biti v obliki 172.31.10.0/24 (maska /16 do /29)');
        $r = self::range($net); if (!$r) return A('neveljavno omrežje');
        $first = long2ip($r[0]); $net = $first . '/' . $m[2];
        if (!filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) return A('omrežje mora biti zasebno (10.x, 172.16–31.x, 192.168.x)');
        foreach (self::usedNets() as $u) if (self::overlaps($net, $u)) return A('omrežje {n} se prekriva z {u}, ki je že v uporabi', ['n' => $net, 'u' => $u]);
        if ($port < 1024 || $port > 65535) return A('port mora biti med 1024 in 65535');
        foreach (Wg::ifaces($d) as $w) if ((int)$w['p'] === $port) return A('port {p} že uporablja drug WireGuard vmesnik', ['p' => $port]);
        if (!in_array($mode, self::MODES, true)) $mode = 'lan';
        $addr = long2ip($r[0] + 1) . '/' . $m[2];
        return Actions::create($d, 'wgsrv', $name, 1, 'approved', A('WireGuard strežnik {n} ({a}, port {p})', ['n' => $name, 'a' => $addr, 'p' => $port]), 'splet', null, $by,
            ['net' => $net, 'addr' => $addr, 'port' => $port, 'mode' => $mode]);
    }

    public static function remove(array $d, string $name, string $by): int|string
    {
        if (!isset(self::managed((int)$d['id'])[$name])) return A('odstraniti je mogoče samo strežnik, ki ga je ustvaril NOC');
        return Actions::create($d, 'wgdel', $name, 1, 'approved', A('odstranitev WireGuard strežnika {n}', ['n' => $name]), 'splet', null, $by);
    }

    // ------------------------------------------------------------------ ukazi za router
    public static function rsc(array $d, array $a): string
    {
        $n = (string)$a['ip']; if (!self::validName($n)) return "# neveljavno ime vmesnika\n";
        if ($a['kind'] === 'wgdel') {
            $re = $n;   // ime ima samo črke, številke, - in _ (brez posebnih znakov za regex)
            return "# NOC: odstranitev WireGuard streznika $n (ukaz #{$a['id']})\n"
                . ":do { /interface wireguard peers remove [find interface=\"$n\"] } on-error={}\n"
                . ":do { /ip address remove [find interface=\"$n\"] } on-error={}\n"
                . ":do { /interface list member remove [find interface=\"$n\"] } on-error={}\n"
                . "/ip firewall filter remove [find comment~\"^noc-wg: $re \"]\n"
                . ":do { /interface wireguard remove [find name=\"$n\"] } on-error={}\n"
                . ":log info \"noc: WireGuard $n odstranjen\"\n";
        }
        $p = json_decode((string)$a['data'], true) ?: [];
        $addr = (string)($p['addr'] ?? ''); $port = (int)($p['port'] ?? 0); $mode = (string)($p['mode'] ?? 'lan');
        if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $addr) || $port < 1024) return "# neveljavni parametri\n";
        $top = fn(string $args) => Firewall::top('/ip firewall filter', $args);
        $s = "# NOC: WireGuard strežnik $n ($addr, port $port, dostop $mode) – ukaz #{$a['id']}\n"
            . ":if ([:len [/interface wireguard find name=\"$n\"]] = 0) do={ /interface wireguard add name=$n listen-port=$port mtu=1420 comment=\"noc: VPN uporabniki\" }\n"
            . ":if ([:len [/ip address find interface=\"$n\" address=\"$addr\"]] = 0) do={ /ip address add address=$addr interface=$n comment=\"noc: VPN uporabniki\" }\n"
            . "/ip firewall filter remove [find comment~\"^noc-wg: $n \"]\n"
            . $top("chain=input protocol=udp dst-port=$port action=accept comment=\"noc-wg: $n WireGuard port\"");
        // dostop iz VPN: "kot LAN" (seznam LAN) ali samo LAN omrežja + DNS/ping routerja
        $nets = array_values(array_filter(Filter::networks($d), fn($x) => self::range($x) !== null));
        $netRules = $top("chain=input in-interface=$n protocol=udp dst-port=53 action=accept comment=\"noc-wg: $n DNS\"")
            . $top("chain=input in-interface=$n protocol=tcp dst-port=53 action=accept comment=\"noc-wg: $n DNS tcp\"")
            . $top("chain=input in-interface=$n protocol=icmp action=accept comment=\"noc-wg: $n ping\"");
        foreach ($nets ?: ['0.0.0.0/0'] as $x) $netRules .= $top("chain=forward in-interface=$n dst-address=$x action=accept comment=\"noc-wg: $n v LAN\"");
        if ($mode === 'lan') {
            $s .= ":if ([:len [/interface list find name=\"LAN\"]] > 0) do={\n"
                . "  :if ([:len [/interface list member find list=LAN interface=\"$n\"]] = 0) do={ /interface list member add list=LAN interface=$n comment=\"noc: VPN uporabniki\" }\n"
                . "} else={\n" . $netRules . "}\n";
        } else $s .= $netRules;
        return $s . ":log info \"noc: WireGuard strežnik $n nastavljen ($addr, port $port)\"\n";
    }
}
