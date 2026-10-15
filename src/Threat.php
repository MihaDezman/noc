<?php
declare(strict_types=1);

/**
 * Varnostne liste posameznih zlonamernih strežnikov (poleg Spamhaus DROP omrežij):
 *  - abuse.ch Feodo Tracker: nadzorni strežniki botnetov (Emotet, QakBot, Dridex …)
 *  - abuse.ch SSLBL: strežniki botnetov po SSL certifikatih
 *  - abuse.ch ThreatFox: nedavni IOC naslovi (zahteva brezplačen Auth-Key v nastavitvah)
 * Strežnik jih prenese enkrat dnevno; traffic-flow preveri, ali naprava v LAN-u komunicira z njimi.
 */
final class Threat
{
    private static ?array $map = null;

    private static function file(): string { return rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/threat-ips.txt'; }

    /** Vir zadetka (npr. "Feodo: Emotet") ali null */
    public static function check(string $ip): ?string
    {
        if (self::$map === null) {
            self::$map = [];
            $f = self::file();
            foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
                [$ip0, $src] = array_pad(explode(' ', $l, 2), 2, '');
                if (filter_var($ip0, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) self::$map[$ip0] = $src;
            }
        }
        if (isset(self::$map[$ip])) return self::$map[$ip];
        return FwLog::inDrop($ip) ? 'Spamhaus DROP' : null;
    }

    public static function count(): int { $f = self::file(); return is_file($f) ? count(file($f)) : 0; }

    /** Cron enkrat dnevno; ob napaki posameznega vira ostanejo njegovi zadnji podatki */
    public static function update(): string
    {
        $old = [];
        foreach (is_file(self::file()) ? file(self::file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) { [$ip, $src] = array_pad(explode(' ', $l, 2), 2, ''); $old[explode(':', $src)[0]][$ip] = $src; }
        $key = dec(setting('abuse_key_enc', ''));
        $hdr = "User-Agent: noc.dezman.net\r\n" . ($key !== '' ? "Auth-Key: $key\r\n" : '');
        $get = fn(string $url) => @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'header' => $hdr]]));
        $new = []; $log = [];
        // Feodo (CSV z imenom zlonamerne kode)
        if (($raw = $get('https://feodotracker.abuse.ch/downloads/ipblocklist.csv')) !== false) {
            foreach (explode("\n", $raw) as $l) {
                if ($l === '' || $l[0] === '#') continue;
                $r = str_getcsv($l);
                if (filter_var($r[1] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $new['Feodo'][$r[1]] = 'Feodo: ' . preg_replace('/[^A-Za-z0-9 ._-]/', '', (string)($r[5] ?? 'botnet C2'));
            }
        }
        // SSLBL
        if (($raw = $get('https://sslbl.abuse.ch/blacklist/sslipblacklist.txt')) !== false)
            foreach (explode("\n", $raw) as $l) { $l = trim($l); if ($l !== '' && $l[0] !== '#' && filter_var($l, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $new['SSLBL'][$l] = 'SSLBL: botnet C2'; }
        // ThreatFox (samo z Auth-Key)
        if ($key !== '' && ($raw = $get('https://threatfox.abuse.ch/export/csv/ip-port/recent/')) !== false) {
            foreach (explode("\n", $raw) as $l) {
                if ($l === '' || $l[0] === '#') continue;
                $r = str_getcsv($l);
                $ip = explode(':', (string)($r[2] ?? ''))[0];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $new['ThreatFox'][$ip] = 'ThreatFox: ' . preg_replace('/[^A-Za-z0-9 ._-]/', '', (string)($r[5] ?? $r[4] ?? 'IOC'));
            }
        }
        if ($key === '') unset($old['ThreatFox']);   // brez ključa ThreatFox ne vzdržujemo več
        $all = [];
        foreach (['Feodo', 'SSLBL', 'ThreatFox'] as $s) {
            $src = $new[$s] ?? ($old[$s] ?? []);
            $log[] = "$s " . count($src) . (isset($new[$s]) ? '' : ' (staro)');
            foreach ($src as $ip => $label) $all[$ip] ??= $label;
        }
        if ($all) @file_put_contents(self::file(), implode("\n", array_map(fn($ip, $l) => "$ip $l", array_keys($all), $all)) . "\n");
        self::$map = null;
        return implode(', ', $log);
    }

    /** Naslovi za seznam blokad na routerju (noc-filter-drop): samo nadzorni strežniki botnetov (Feodo, SSLBL), ne ThreatFox (preširok) */
    public static function ips(): array
    {
        self::check('0.0.0.0');
        return array_keys(array_filter(self::$map ?? [], fn($l) => str_starts_with($l, 'Feodo') || str_starts_with($l, 'SSLBL')));
    }
}
