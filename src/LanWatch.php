<?php
declare(strict_types=1);

/**
 * Nenormalen promet v LAN (v1.44): router sam zazna napravo, ki odpre izbruh DNS povezav, ogromno povezav, skenira
 * ali pošilja pošto neposredno na port 25 navzven, in jo zapiše v seznam noc-lanw-<vrsta>. Push te vnose sporoči NOC,
 * ta odpre alarm z imenom naprave in portom storitve. SMTP lahko router takoj blokira (samo port 25, 24 h).
 * Vse na routerju nosi oznako "noc-lan" in se ob vsaki namestitvi najprej počisti.
 */
final class LanWatch
{
    /** Meje (hkratne povezave na izvorni naslov) */
    public const DNS = 20, CONN = 1000, SMTP = 5;

    public static function kinds(): array
    {
        return ['dns' => A('DNS izbruh'), 'conn' => A('preveč povezav'), 'scan' => A('skeniranje portov'), 'smtp' => A('pošiljanje pošte navzven')];
    }

    /** Seznam IPv4 naslovov/omrežij: preveri, normaliziraj (/32 -> naslov, omrežje na naslov omrežja) in odstrani podvojene */
    public static function nets(string $txt): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $txt) as $x) {
            if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $x, $m) || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $len = isset($m[2]) ? (int)$m[2] : 32;
            if ($len > 32) continue;
            $ip = long2ip(ip2long($m[1]) & ($len ? (-1 << (32 - $len)) & 0xFFFFFFFF : 0));
            $out[$len === 32 ? $ip : "$ip/$len"] = true;
        }
        return array_keys($out);
    }

    /** Izjeme naprave */
    public static function exceptions(array $d): array { return self::nets((string)($d['lan_ok'] ?? '')); }

    /** Del namestitvenega paketa routerja (tudi ob samodejni posodobitvi): najprej počisti, nato po potrebi nastavi */
    public static function rsc(array $d): string
    {
        $s = "# --- nenormalen promet v LAN (noc-lan): odstrani prejsnje nastavitve\n" . self::cleanup();
        $nets = self::nets((string)($d['lan_networks'] ?? ''));
        if (Devices::isSwitch($d) || !(int)($d['lan_watch'] ?? 1) || !$nets) return $s . "/ip firewall address-list remove [find list~\"^noc-lanw-\"]\n\n";
        $block = (int)($d['lan_smtp_block'] ?? 0) === 1;
        $list = fn(string $l, string $a, string $c = 'noc-lan') => ":do { /ip firewall address-list add list=$l address=$a comment=\"$c\" } on-error={}\n";
        $s .= "# omrezja LAN in izjeme (podvojen vnos ne ustavi uvoza)\n";
        foreach ($nets as $n) $s .= $list('noc-lan-nets', $n);
        foreach (self::exceptions($d) as $n) $s .= $list('noc-lan-ok', $n);
        // poštni strežnik v LAN (dst-nat na port 25) je samodejno izjema
        $s .= ":foreach n in=[/ip firewall nat find where action=dst-nat and dst-port~\"(^|,)25(,|\\\$|-)\"] do={ :do { /ip firewall address-list add list=noc-lan-ok address=[/ip firewall nat get \$n to-addresses] comment=\"noc-lan: postni streznik\" } on-error={} }\n";
        // vsako pravilo v :do – napaka v enem (npr. starejši RouterOS) ne sme ustaviti uvoza preostalega paketa
        $m = fn(string $chain, string $args, string $c) => ":do { /ip firewall mangle add chain=$chain $args comment=\"noc-lan: $c\" } on-error={ :log warning \"noc: pravilo noc-lan ($c) ni dodano\" }\n";
        $add = fn(string $k, string $t) => "action=add-src-to-address-list address-list=noc-lanw-$k address-list-timeout=$t";
        // meja povezav (connection-limit) šteje povezave, ki pridejo do pravila – zato DNS in SMTP v svoji verigi (štejejo samo te povezave)
        $s .= "# zaznavanje (samo nove TCP povezave iz LAN; prometa ne blokira)\n"
            . Firewall::top('/ip firewall mangle', 'chain=prerouting src-address-list=noc-lan-nets protocol=tcp connection-state=new action=jump jump-target=noc-lan comment="noc-lan: zaznavanje nenormalnega prometa"')
            . $m('noc-lan', 'src-address-list=noc-lan-ok action=return', 'izjeme')
            . $m('noc-lan', 'protocol=tcp dst-port=53 action=jump jump-target=noc-lan-dns', 'DNS')
            . $m('noc-lan-dns', 'protocol=tcp connection-limit=' . self::DNS . ',32 ' . $add('dns', '15m'), 'DNS izbruh')
            . $m('noc-lan', 'protocol=tcp connection-limit=' . self::CONN . ',32 ' . $add('conn', '15m'), 'prevec povezav')
            . $m('noc-lan', 'dst-address-list=noc-lan-nets action=jump jump-target=noc-lan-scan', 'skeniranje LAN')
            . $m('noc-lan', 'dst-address-type=local action=jump jump-target=noc-lan-scan', 'skeniranje routerja')
            . $m('noc-lan-scan', 'protocol=tcp psd=21,3s,3,1 ' . $add('scan', '15m'), 'skeniranje portov')
            . $m('noc-lan', 'protocol=tcp dst-port=25 dst-address-type=!local dst-address-list=!noc-lan-nets action=jump jump-target=noc-lan-smtp', 'SMTP')
            . $m('noc-lan-smtp', 'protocol=tcp connection-limit=' . self::SMTP . ',32 ' . $add('smtp', $block ? '24h' : '15m'), 'SMTP navzven');
        if ($block) $s .= "# blokada: naprava, ki posilja posto neposredno navzven, 24 h ne more na port 25 (ostali internet deluje)\n"
            . Firewall::top('/ip firewall filter', 'chain=forward src-address-list=noc-lanw-smtp protocol=tcp dst-port=25 action=drop comment="noc-lan: blokada SMTP (24 h)"');
        return $s . "\n";
    }

    /** Odstrani vsa pravila in sezname noc-lan (namestitev pred novo nastavitvijo; zaznane naprave ostanejo) */
    public static function cleanup(): string
    {
        return "/ip firewall mangle remove [find comment~\"^noc-lan\"]\n/ip firewall filter remove [find comment~\"^noc-lan\"]\n/ip firewall address-list remove [find comment~\"^noc-lan\"]\n";
    }

    /** Za noc-remove.rsc: vse, tudi zaznane naprave (aktivne blokade) */
    public static function removeRsc(): string { return self::cleanup() . "/ip firewall address-list remove [find list~\"^noc-lanw-\"]\n"; }

    /** Del push skripte routerja: vnosi noc-lanw-* (največ 10) in skupno število; pri prvih 2 conn/scan 3 najpogostejši ciljni porti */
    public static function pushPart(): string
    {
        return <<<'RSC'
# --- nenormalen promet v LAN (noc-lan)
:local lw [:toarray ""]
:local lwn 0
:do {
    :local np 0
    :local all [/ip firewall address-list find where list~"^noc-lanw-"]
    :set lwn [:len $all]
    :foreach e in=$all do={
        :if ([:len $lw] < 10) do={ :do { :local ev [/ip firewall address-list get $e]
            :local a [:tostr ($ev->"address")]
            :local l [:pick [:tostr ($ev->"list")] 9 20]
            :local top ""
            :if (($l = "conn" or $l = "scan") and $np < 2) do={
                :set np ($np + 1)
                :local cnt [:toarray ""]
                :foreach c in=[:pick [/ip firewall connection find where src-address~("^" . $a . ":")] 0 300] do={
                    :do { :local da [:tostr [/ip firewall connection get $c dst-address]]
                        :local i [:find $da ":"]
                        :if ([:typeof $i] = "num") do={
                            :local k ("p" . [:pick $da ($i + 1) [:len $da]])
                            :if ([:typeof ($cnt->$k)] = "nothing") do={ :set ($cnt->$k) 1 } else={ :set ($cnt->$k) (($cnt->$k) + 1) }
                        }
                    } on-error={}
                }
                :for n from=1 to=3 do={
                    :local bk ""
                    :local bv 0
                    :foreach pk,pv in=$cnt do={ :if ($pv > $bv) do={ :set bk $pk; :set bv $pv } }
                    :if ($bv > 0) do={ :set top ($top . [:pick $bk 1 [:len $bk]] . "x" . $bv . ","); :set ($cnt->$bk) 0 }
                }
            }
            :set ($lw->[:len $lw]) {"l"=$l;"a"=$a;"p"=$top}
        } on-error={} }
    }
} on-error={}

RSC;
    }

    /** Ob pushu (samo nova skripta pošlje "lw"): alarm za vsak vnos, zapre alarme vnosov, ki jih ni več */
    public static function push(array $d, array $lw, int $total = -1, ?int $now = null): void
    {
        $id = (int)$d['id']; $kinds = self::kinds(); $seen = []; $now ??= time();
        $q = db()->prepare('SELECT v FROM settings WHERE k=?'); $q->execute(['lw:' . $id]);   // neposredno (setting() je predpomnjen za ves proces)
        $last = json_decode((string)($q->fetchColumn() ?: ''), true) ?: [];   // kdaj je bil vnos nazadnje na seznamu (za zapiranje alarmov)
        foreach (array_slice($lw, 0, 10) as $e) {
            $k = (string)($e['l'] ?? ''); $ip = (string)($e['a'] ?? '');
            if (!isset($kinds[$k]) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $key = "lan:$k:$ip"; $seen[$key] = true; $last[$key] = $now;
            $port = match ($k) {
                'dns' => A('port {p}', ['p' => '53 (DNS)']),
                'smtp' => A('port {p}', ['p' => '25 (SMTP)']),
                default => self::ports((string)($e['p'] ?? '')),
            };
            $msg = A('Nenormalen promet v LAN – {k}: {h}', ['k' => $kinds[$k], 'h' => FwLog::host($id, $ip)]) . ($port !== '' ? ' · ' . $port : '');
            if ($k === 'smtp') $msg .= (int)($d['lan_smtp_block'] ?? 0)
                ? ' – ' . A('možen spam bot; port 25 je za to napravo blokiran 24 h (odblokiraš: /ip firewall address-list remove [find list=noc-lanw-smtp address={ip}])', ['ip' => $ip])
                : ' – ' . A('možen spam bot');
            Alerts::raise($d, $key, 'warning', $msg);
        }
        // zapri šele, ko vnosa ni 3 min (vmesen izpad branja ne sproži para alarm/razrešeno); pri več kot 10 vnosih
        // router ne pošlje vseh – takrat ne zapiraj
        if ($total <= count($lw)) {
            $st = db()->prepare("SELECT akey FROM alerts WHERE device_id=? AND akey LIKE 'lan:%' AND ended_at IS NULL"); $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) if (!isset($seen[$k]) && $now - (int)($last[$k] ?? 0) >= 180) { Alerts::clear($d, $k); unset($last[$k]); }
        }
        foreach ($last as $k => $t) if ($now - (int)$t > 86400) unset($last[$k]);
        setting_set('lw:' . $id, json_encode($last));
    }

    /** "443x120,80x30," -> "najpogostejši porti: 443 (120×), 80 (30×)" */
    private static function ports(string $p): string
    {
        $out = [];
        foreach (explode(',', $p) as $x) if (preg_match('/^(\d{1,5})x(\d+)$/', trim($x), $m)) $out[] = "{$m[1]} ({$m[2]}×)";
        return $out ? A('najpogostejši porti: {p}', ['p' => implode(', ', array_slice($out, 0, 3))]) : '';
    }

    /** Za AI razlago alarmov: zaščite, ki jih je NOC že nastavil na routerju (da ne predlaga obstoječih pravil) */
    public static function protections(array $d): string
    {
        if (Devices::isSwitch($d)) return '';
        $p = [];
        if (($d['filter_profile'] ?? 'off') !== 'off') $p[] = 'filtrirni DNS (' . $d['filter_profile'] . '); DNS routerja ni odprt na WAN (drop TCP/UDP 53 z WAN na vrhu input); DNS routerja dovoljen samo za zaščitena omrežja';
        if ((int)($d['fwlog_enabled'] ?? 0)) $p[] = 'varnostna analiza: beleženje zavrnjenih poskusov z interneta (noc-log) in analiza odhodnega prometa';
        if ((int)($d['lan_watch'] ?? 1) && self::nets((string)($d['lan_networks'] ?? '')))
            $p[] = 'zaznavanje: nenormalen promet v LAN (mangle noc-lan: DNS/TCP > ' . self::DNS . ', povezav > ' . self::CONN . ', skeniranje routerja/LAN, SMTP navzven > ' . self::SMTP . ')'
                 . ((int)($d['lan_smtp_block'] ?? 0) ? '; samodejna blokada TCP/25 navzven za tako napravo 24 h' : '');
        if ((int)($d['term_enabled'] ?? 0)) $p[] = 'SSH s strežnika NOC dovoljen (terminal NOC)';
        return $p ? 'ZAŠČITE NOC (že nastavljeno na routerju): ' . implode(' · ', $p) . "\n" : '';
    }
}
