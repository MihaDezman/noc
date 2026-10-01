<?php
declare(strict_types=1);

/**
 * Zaščita omrežja naročnika: filtrirni DNS (Cloudflare/Quad9), DNS samo prek routerja, blokada šifriranega DNS (DoH/DoT),
 * varnostne IP liste (Spamhaus DROP) in lastne blokirane domene. Vse na routerju nosi komentar "noc-filter",
 * zato se da v celoti odstraniti, ob izklopu pa se DNS vrne na prvotne nastavitve.
 */
final class Filter
{
    public const PROFILES = [
        'basic'  => ['1.1.1.2', '9.9.9.9'],    // zlonamerne in phishing strani
        'family' => ['1.1.1.3', '1.0.0.3'],    // + vsebine za odrasle
    ];

    /** Javni DoH/DoT strežniki, prek katerih bi naprave obšle filter */
    public const DOH = ['dns.google', 'cloudflare-dns.com', 'mozilla.cloudflare-dns.com', 'chrome.cloudflare-dns.com', 'one.one.one.one',
        'dns.quad9.net', 'dns11.quad9.net', 'doh.opendns.com', 'dns.adguard-dns.com', 'dns.adguard.com', 'dns.nextdns.io',
        'doh.cleanbrowsing.org', 'doh.mullvad.net', 'dns.sb', 'doh.dns.sb',
        '8.8.8.8', '8.8.4.4', '1.1.1.1', '1.0.0.1', '9.9.9.9', '149.112.112.112', '208.67.222.222', '208.67.220.220', '94.140.14.14', '94.140.15.15'];

    public static function label(string $p): string
    {
        return ['off' => A('Izklopljeno'), 'basic' => A('Osnovna'), 'family' => A('Družinska')][$p] ?? $p;
    }

    public static function networks(array $d): array
    {
        $n = Devices::list((string)($d['filter_networks'] ?? ''));
        return $n ?: Devices::list((string)$d['lan_networks']);
    }

    public static function domains(array $d): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)($d['filter_domains'] ?? '')) as $x) {
            $x = strtolower(trim(preg_replace('#^https?://#i', '', $x), '/. '));
            $x = explode('/', $x)[0];
            if (str_starts_with($x, 'www.')) $x = substr($x, 4);   // blokada velja za domeno z vsemi poddomenami
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $x)) $out[] = $x;
        }
        return array_values(array_unique($out));
    }

    /** Stanje na routerju iz zadnjega pusha: [aktivno, opis] */
    public static function routerState(array $d): array
    {
        $f = $d['status']['flt'] ?? null;
        if (!is_array($f)) return [null, A('ni podatka – naloži paket na router')];
        $want = $d['filter_profile'] ?? 'off';
        $on = (int)($f['n'] ?? 0) > 0 || str_contains((string)($f['dns'] ?? ''), '1.1.1.2') || str_contains((string)($f['dns'] ?? ''), '1.1.1.3');
        if ($want === 'off') return [!$on, $on ? A('na routerju je še aktivna – naloži paket') : A('izklopljena')];
        $dnsOk = str_contains((string)($f['dns'] ?? ''), self::PROFILES[$want][0]);
        if (!$dnsOk) return [false, A('ni naložena – naloži paket na router')];
        return [true, A('aktivna') . ((int)($f['drop'] ?? 0) > 0 ? ' · ' . A('{n} omrežij v varnostni listi', ['n' => (int)$f['drop']]) : '')];
    }

    /** Dodaj pravilo na vrh (pred prvo nedinamično), če ne gre, na konec */
    private static function top(string $menu, string $args): string
    {
        return ":do { $menu add $args place-before=([$menu find where dynamic=no]->0) } on-error={ $menu add $args }\n";
    }

    /** Del namestitvenega paketa: vedno najprej počisti, nato po potrebi nastavi */
    public static function rsc(array $d): string
    {
        $p = $d['filter_profile'] ?? 'off';
        $s = "# --- zascita (NOC): odstrani prejsnje nastavitve\n"
           . "/ip firewall nat remove [find comment~\"^noc-filter\"]\n"
           . "/ip firewall filter remove [find comment~\"^noc-filter\"]\n"
           . "/ip firewall raw remove [find comment~\"^noc-filter\"]\n"
           . "/ip firewall address-list remove [find comment~\"^noc-filter\"]\n"
           . "/ip dns static remove [find comment~\"^noc-filter\"]\n";
        if ($p === 'off') {
            if (($d['dns_original'] ?? null) !== null) $s .= self::restoreDns($d);
            return $s . "\n";
        }
        $orig = json_decode((string)$d['dns_original'], true) ?: [];
        $s .= "\n# --- filtrirni DNS: " . self::label($p) . " (prej: " . (($orig['servers'] ?? '') !== '' ? $orig['servers'] : 'od ponudnika') . ")\n"
            . "/ip dns set servers=" . implode(',', self::PROFILES[$p]) . " allow-remote-requests=yes\n"
            . "/ip dns cache flush\n";
        $wan = (string)$d['wan_iface'];
        if ($wan !== '') {
            $s .= "# DNS routerja ni dostopen z interneta\n:if ([:len [/interface find name=\"$wan\"]] > 0) do={\n";
            foreach (['udp', 'tcp'] as $proto) $s .= "    " . self::top('/ip firewall filter', "chain=input in-interface=\"$wan\" protocol=$proto dst-port=53 action=drop comment=\"noc-filter: DNS ni odprt na WAN\"");
            $s .= "}\n";
        }
        $s .= "# omrezja z zascito\n";
        foreach (self::networks($d) as $n) $s .= "/ip firewall address-list add list=noc-filter-lan address=$n comment=\"noc-filter\"\n";

        if ((int)($d['filter_force_dns'] ?? 1)) {
            $s .= "# naprave z rocno vpisanim DNS gredo vseeno prek routerja\n";
            foreach (['udp', 'tcp'] as $proto) $s .= self::top('/ip firewall nat', "chain=dstnat src-address-list=noc-filter-lan protocol=$proto dst-port=53 action=redirect to-ports=53 comment=\"noc-filter: DNS prek routerja\"");
        }
        if ((int)($d['filter_block_doh'] ?? 1)) {
            $s .= "# sifriran DNS (DoH, DoT) bi obsel filter\n";
            foreach (self::DOH as $h) $s .= ":do { /ip firewall address-list add list=noc-filter-doh address=$h comment=\"noc-filter\" } on-error={}\n";
            $s .= self::top('/ip firewall filter', 'chain=forward src-address-list=noc-filter-lan dst-address-list=noc-filter-doh protocol=tcp dst-port=443 connection-state=new action=reject reject-with=tcp-reset comment="noc-filter: DoH"');
            $s .= self::top('/ip firewall filter', 'chain=forward src-address-list=noc-filter-lan dst-address-list=noc-filter-doh protocol=udp dst-port=443 action=drop comment="noc-filter: DoH (QUIC)"');
            $s .= self::top('/ip firewall filter', 'chain=forward src-address-list=noc-filter-lan protocol=tcp dst-port=853 connection-state=new action=reject reject-with=tcp-reset comment="noc-filter: DoT"');
            $s .= self::top('/ip firewall filter', 'chain=forward src-address-list=noc-filter-lan protocol=udp dst-port=853 action=drop comment="noc-filter: DoT"');
        }
        foreach (self::domains($d) as $dom) {
            $s .= ":do { /ip dns static add name=$dom address=0.0.0.0 match-subdomain=yes ttl=1h comment=\"noc-filter\" } on-error={ :do { /ip dns static add name=$dom address=0.0.0.0 ttl=1h comment=\"noc-filter\" } on-error={} }\n";
        }
        if ((int)($d['filter_ip_lists'] ?? 1)) {
            $base = rtrim((string)cfg('base_url'), '/'); $key = dec($d['api_key_enc'] ?? null); $pol = MikrotikScript::POLICY;
            $upd = ":onerror e in={ /tool fetch url=\"$base/api/filter/drop\" http-header-field=\"X-Api-Key: $key\" dst-path=noc-filter-drop.rsc; :delay 1s; /import noc-filter-drop.rsc; /file remove noc-filter-drop.rsc } do={ :log warning (\"noc: varnostna IP lista ni posodobljena - \" . \$e) }";
            $s .= "# varnostne IP liste (Spamhaus DROP) - posodobitev vsak dan\n"
                . self::top('/ip firewall raw', 'chain=prerouting src-address-list=noc-filter-drop action=drop comment="noc-filter: varnostna IP lista"')
                . self::top('/ip firewall raw', 'chain=prerouting dst-address-list=noc-filter-drop action=drop comment="noc-filter: varnostna IP lista"')
                . "/system script add name=noc-filter-update policy=$pol comment=\"noc.dezman.net\" source=\"" . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $upd) . "\"\n"
                . sprintf("/system scheduler add name=noc-filter-update interval=1d start-time=%02d:%02d:00 on-event=noc-filter-update policy=%s comment=\"noc.dezman.net\"\n", 5, random_int(5, 55), $pol)
                . ":execute script=\"/system script run noc-filter-update\"\n";
        }
        return $s . ":log info \"noc: zascita " . self::label($p) . " nastavljena\"\n\n";
    }

    /** DNS nazaj na stanje pred zaščito */
    public static function restoreDns(array $d): string
    {
        $o = json_decode((string)($d['dns_original'] ?? ''), true);
        if (!is_array($o)) return '';
        $srv = preg_replace('/[^0-9a-fA-F.:,]/', '', (string)($o['servers'] ?? ''));
        $rem = ($o['remote'] ?? 'no') === 'yes' ? 'yes' : 'no';
        return "# zascita izklopljena: DNS nazaj na prvotne nastavitve\n/ip dns set servers=\"$srv\" allow-remote-requests=$rem\n/ip dns cache flush\n";
    }

    // ------------------------------------------------------------------ varnostna IP lista
    private static function dropFile(): string { return rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/spamhaus-drop.txt'; }

    /** Cron enkrat dnevno: prenesi Spamhaus DROP (ob napaki ostane zadnja dobra kopija) */
    public static function updateDropList(): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: noc.dezman.net\r\n"]]);
        foreach (['https://www.spamhaus.org/drop/drop.txt', 'https://www.spamhaus.org/drop/drop_v4.json'] as $url) {
            $raw = @file_get_contents($url, false, $ctx);
            if (!$raw) continue;
            preg_match_all('#\b(\d{1,3}(?:\.\d{1,3}){3}/\d{1,2})\b#', $raw, $m);
            $nets = array_values(array_unique($m[1]));
            if (count($nets) >= 100) { @file_put_contents(self::dropFile(), implode("\n", $nets) . "\n"); return 'DROP: ' . count($nets); }
        }
        return 'DROP: prenos ni uspel';
    }

    /** .rsc za router: zamenja vsebino liste noc-filter-drop */
    public static function dropRsc(): string
    {
        $f = self::dropFile();
        $nets = is_file($f) ? array_filter(array_map('trim', file($f))) : [];
        $s = "# NOC varnostna IP lista (Spamhaus DROP) - " . count($nets) . " omrezij\n/ip firewall address-list remove [find list=noc-filter-drop]\n";
        foreach ($nets as $n) if (preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $n)) $s .= ":do { /ip firewall address-list add list=noc-filter-drop address=$n comment=\"noc-filter\" } on-error={}\n";
        return $s;
    }
}
