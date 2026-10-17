<?php
declare(strict_types=1);

/**
 * Meritev hitrosti interneta z routerja (ukaz prek /api/action, kot blokade in posodobitve):
 *  - btest: /tool bandwidth-test (TCP) na strežnik NOC – download in upload, brez šifriranja (realna hitrost tudi na šibkih routerjih)
 *  - http:  prenos testne datoteke (Cloudflare ali NOC) – samo download; pri HTTPS lahko šibek router omeji CPU
 * Če btest ne uspe (strežnik nedosegljiv, napačno geslo), router sam izmeri še s prenosom datoteke.
 * Nočne meritve (izbirno po napravi) tečejo ena za drugo med 04:00 in 05:59, da se na strežniku NOC ne motijo.
 */
final class Speed
{
    public const METHODS = ['btest', 'http'];

    public static function methodLabel(string $m): string
    {
        return ['btest' => A('btest na strežnik NOC'), 'http' => A('prenos datoteke')][$m] ?? $m;
    }

    /** Nastavitve btest strežnika: [host, user, pass] ali null, če ni nastavljen */
    public static function btest(): ?array
    {
        $host = setting('speed_btest_host', (string)cfg('noc_ip', ''));
        $user = setting('speed_btest_user', ''); $pass = dec(setting('speed_btest_pass_enc', ''));
        if (!self::validHost($host) || $user === '') return null;
        return [$host, $user, $pass];
    }

    public static function validHost(string $h): bool
    {
        return (bool)filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $h);
    }

    /** Uporabnik in geslo za btest: samo varni znaki (gresta v skripto routerja) */
    public static function validCred(string $s): bool { return (bool)preg_match('/^[A-Za-z0-9._@+-]{1,64}$/', $s); }

    /** URL testne datoteke za {n} bajtov ter ali gre na NOC (takrat z API ključem) */
    private static function httpUrl(string $base, int $bytes): array
    {
        return setting('speed_http_src', 'cf') === 'noc'
            ? ["$base/api/speed-file?bytes=$bytes", true]
            : ["https://speed.cloudflare.com/__down?bytes=$bytes", false];
    }

    /** Ukaz za meritev (zahteva skrbnika ali nočni cron); vrne id ukaza ali napako */
    public static function request(array $d, string $method, string $source, string $by = ''): int|string
    {
        if (!in_array($method, self::METHODS, true)) return A('neznana metoda');
        if ($method === 'btest' && !self::btest()) $method = 'http';   // btest ni nastavljen
        return Actions::create($d, 'speed', $method, 1, 'approved', $source === 'noc' ? A('nočna meritev') : A('ročno v NOC'), $source, null, $by ?: $source);
    }

    // ------------------------------------------------------------------ skripta za router
    public static function rsc(array $d, int $id, string $method, string $base, string $key, string $chk): string
    {
        // :timestamp (merjenje časa z delčki sekunde) obstaja od RouterOS 7.10; starejši imajo samo čas iz fetch
        $ver = Ros::ver((string)($d['os_version'] ?? ''));
        $ts = $ver !== '' && version_compare($ver, '7.10', '>=');
        $hdr = "http-header-field=\"X-Api-Key: $key\"";
        $bt = $method === 'btest' ? self::btest() : null;
        $s = "# NOC meritev hitrosti #$id\n:global nocSpdR \"\"\n:global nocSpdT \"\"\n:global nocSpdM \"http\"\n";
        if ($bt) {
            [$host, $user, $pass] = $bt;
            $auth = "user=\"$user\" password=\"$pass\"";
            // do-blok se izvede ob vsakem izpisu; zadnja vrednost je povprečje celotne meritve
            $s .= ":do { /tool bandwidth-test address=$host $auth protocol=tcp direction=receive duration=12s do={ :global nocSpdR; :set nocSpdR \$\"rx-total-average\" } } on-error={}\n"
                . ":delay 2s\n"
                . ":do { /tool bandwidth-test address=$host $auth protocol=tcp direction=transmit duration=12s do={ :global nocSpdT; :set nocSpdT \$\"tx-total-average\" } } on-error={}\n"
                . ":do { :if ([:len [:tostr \$nocSpdR]] > 0) do={ :if ([:tonum \$nocSpdR] > 0) do={ :set nocSpdM \"btest\" } } } on-error={}\n";
        }
        // prenos datoteke: 10 MB, pri hitri liniji (pod 3 s) še 100 MB za natančnejšo meritev
        [$u1, $n1] = self::httpUrl($base, 10_000_000); [$u2, $n2] = self::httpUrl($base, 100_000_000);
        // API ključ in preverjanje certifikata NOC samo za prenos z NOC (Cloudflare ima drugo verigo certifikatov)
        $h1 = $n1 ? "$chk $hdr" : ''; $h2 = $n2 ? "$chk $hdr" : '';
        $t0 = $ts ? ':set s [:timestamp]; ' : '';
        $tm = fn(string $var) => $ts ? " :set $var [:tostr ([:timestamp] - \$s)];" : '';
        $s .= ":local d1 \"\"\n:local t1 \"\"\n:local d2 \"\"\n:local t2 \"\"\n:local s 0\n"
            . ":if (\$nocSpdM = \"http\") do={\n"
            . "  :do { {$t0}:local r [/tool fetch url=\"$u1\"$h1 output=none as-value];" . $tm('t1') . " :set d1 [:tostr (\$r->\"duration\")]\n"
            . "    :if ((\$r->\"duration\") < 3s) do={ :do { {$t0}:local r2 [/tool fetch url=\"$u2\"$h2 output=none as-value];" . $tm('t2') . " :set d2 [:tostr (\$r2->\"duration\")] } on-error={} }\n"
            . "  } on-error={}\n"
            . "}\n"
            . ":do { /tool fetch url=(\"$base/api/speed-done/$id?m=\" . \$nocSpdM . \"&r=\" . [:tostr \$nocSpdR] . \"&t=\" . [:tostr \$nocSpdT] . \"&d1=\" . \$d1 . \"&t1=\" . \$t1 . \"&d2=\" . \$d2 . \"&t2=\" . \$t2)$chk $hdr output=none } on-error={}\n"
            . ":log info (\"noc: meritev hitrosti koncana (\" . \$nocSpdM . \")\")\n"
            . ":do { /system script remove [find name=\"noc-speed\"] } on-error={}\n";
        // skripta teče v ozadju (traja do ~40 s) – uvoz ukazov in sinhronizacija WireGuard ne čakata nanjo
        $esc = fn($x) => str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $x);
        $pol = MikrotikScript::POLICY;
        return "# NOC: meritev hitrosti (ukaz #$id)\n"
            . ":do { /system script remove [find name=\"noc-speed\"] } on-error={}\n"
            . "/system script add name=noc-speed policy=$pol comment=\"noc.dezman.net\" source=\"" . $esc($s) . "\"\n"
            . ":execute script=\"/system script run noc-speed\"\n";
    }

    // ------------------------------------------------------------------ rezultat z routerja
    /** RouterOS čas ("00:00:04.231", "4s231ms", "1m4s", "4.23") -> sekunde */
    public static function seconds(string $t): ?float
    {
        $t = trim($t);
        if ($t === '') return null;
        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(\d+):(\d{2}):(\d{2}(?:\.\d+)?)$/', $t, $m))
            return (int)$m[1] * 604800 + (int)$m[2] * 86400 + (int)$m[3] * 3600 + (int)$m[4] * 60 + (float)$m[5];
        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+(?:\.\d+)?)s)?(?:(\d+)ms)?(?:(\d+)us)?$/', $t, $m) && $t !== '') {
            $v = (int)($m[1] ?? 0) * 604800 + (int)($m[2] ?? 0) * 86400 + (int)($m[3] ?? 0) * 3600 + (int)($m[4] ?? 0) * 60 + (float)($m[5] ?? 0) + (int)($m[6] ?? 0) / 1000 + (int)($m[7] ?? 0) / 1e6;
            return $v > 0 ? $v : null;
        }
        return is_numeric($t) ? (float)$t : null;
    }

    /** bps iz btest ("412345678", "412.3Mbps", "412.3 Mbps") -> Mb/s */
    public static function mbps(string $v): ?float
    {
        if (!preg_match('/^\s*([\d.]+)\s*([kMG]?)(?:bps|b\/s)?\s*$/i', $v, $m) || (float)$m[1] <= 0) return null;
        $mul = ['' => 1, 'k' => 1e3, 'm' => 1e6, 'g' => 1e9][strtolower($m[2])];
        return round((float)$m[1] * $mul / 1e6, 1);
    }

    public static function done(array $d, int $id, array $q): void
    {
        $st = db()->prepare("SELECT * FROM noc_actions WHERE id=? AND device_id=? AND kind='speed' AND status='sent'"); $st->execute([$id, $d['id']]);
        if (!($a = $st->fetch())) return;
        $m = ($q['m'] ?? '') === 'btest' ? 'btest' : 'http';
        $down = $up = null; $note = '';
        if ($m === 'btest') { $down = self::mbps((string)($q['r'] ?? '')); $up = self::mbps((string)($q['t'] ?? '')); }
        else {
            // večja datoteka je natančnejša; čas iz fetch ali iz razlike časovnih žigov
            foreach ([['d2', 't2', 100_000_000], ['d1', 't1', 10_000_000]] as [$dk, $tk, $bytes]) {
                $sec = self::seconds((string)($q[$dk] ?? '')) ?: self::seconds((string)($q[$tk] ?? ''));
                if ($sec && $sec > 0.05 && $sec < 3600) { $down = round($bytes * 8 / $sec / 1e6, 1); break; }
            }
            if ($a['ip'] === 'btest') $note = A('btest ni uspel – izmerjeno s prenosom datoteke');
        }
        $ok = $down !== null;
        if (!$ok) $note = A('meritev ni uspela (strežnik ni dosegljiv ali prenos prekinjen)');
        db()->prepare('INSERT INTO speed_tests (device_id, at, method, down_mbps, up_mbps, ok, note, action_id, source) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['id'], $m, $down, $up, $ok ? 1 : 0, mb_substr($note, 0, 200), $id, $a['source'] === 'noc' ? 'noc' : 'rocno']);
        db()->prepare("UPDATE noc_actions SET status=?, done_at=NOW() WHERE id=?")->execute([$ok ? 'done' : 'failed', $id]);
        $full = Devices::full((int)$d['id']); if (!$full) return;
        $full = Devices::decorate($full);
        if ($a['source'] !== 'noc') {   // ročna meritev: rezultat na Telegram
            Notify::telegram('⚡ <b>' . htmlspecialchars((string)$full['name']) . '</b> – ' . ($ok
                ? '↓ ' . self::fmt($down) . ($up !== null ? '  ↑ ' . self::fmt($up) : '') . ' <i>(' . htmlspecialchars(self::methodLabel($m)) . ')</i>' . ($note ? "\n" . htmlspecialchars($note) : '')
                : htmlspecialchars($note)));
        } else self::evaluate($full);
    }

    public static function fmt(?float $v): string { return $v === null ? '–' : number_format($v, $v < 10 ? 1 : 0, ',', '.') . ' Mb/s'; }

    /** Nočne meritve: 3 zaporedne pod pragom zakupljene hitrosti -> opozorilo; ko je spet nad pragom, se zapre */
    public static function evaluate(array $d): void
    {
        $zak = (float)($d['wan_down_mbps'] ?? 0); if ($zak <= 0) return;
        $pct = max(10, min(100, (int)setting('speed_low_pct', '70')));
        $st = db()->prepare("SELECT down_mbps FROM speed_tests WHERE device_id=? AND source='noc' AND ok=1 ORDER BY at DESC LIMIT 3"); $st->execute([$d['id']]);
        $v = array_map('floatval', $st->fetchAll(PDO::FETCH_COLUMN)); if (!$v) return;
        $lim = $zak * $pct / 100;
        if (count($v) === 3 && max($v) < $lim)
            Alerts::raise($d, 'speedlow', 'warning', A('Hitrost interneta je 3 noči zapored pod {p} % zakupljene: izmerjeno {m} (zakup {z} Mb/s)', ['p' => $pct, 'm' => self::fmt($v[0]), 'z' => (int)$zak]));
        elseif ($v[0] >= $lim) Alerts::clear($d, 'speedlow');
    }

    /** Cron vsako minuto: med 04:00 in 05:59 sproži naslednjo nočno meritev (ena naenkrat, vsaj 2 min razmika) */
    public static function nightly(): string
    {
        $h = (int)date('G'); if ($h < 4 || $h > 5) return '';
        $busy = db()->query("SELECT COUNT(*) FROM noc_actions WHERE kind='speed' AND (status IN ('approved','sent') AND created_at > NOW() - INTERVAL 10 MINUTE OR created_at > NOW() - INTERVAL 2 MINUTE)")->fetchColumn();
        if ($busy) return '';
        $d = db()->query("SELECT d.* FROM devices d WHERE d.active=1 AND d.speed_auto=1 AND d.last_seen_at > NOW() - INTERVAL 3 MINUTE
                          AND NOT EXISTS (SELECT 1 FROM noc_actions a WHERE a.device_id=d.id AND a.kind='speed' AND a.source='noc' AND a.created_at >= CURDATE())
                          ORDER BY d.id LIMIT 1")->fetch();
        if (!$d) return '';
        $r = self::request($d, self::btest() ? 'btest' : 'http', 'noc');
        return is_int($r) ? "meritev hitrosti: {$d['name']}" : '';
    }

    /** Zadnje meritve naprave */
    public static function history(int $deviceId, int $limit = 10): array
    {
        $st = db()->prepare('SELECT * FROM speed_tests WHERE device_id=? ORDER BY at DESC LIMIT ' . (int)$limit); $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    /** Testna datoteka za meritev s prenosom z NOC (naključni podatki – stiskanje ne pokvari meritve) */
    public static function serveFile(int $bytes): never
    {
        $bytes = max(1_000_000, min(200_000_000, $bytes));
        @set_time_limit(300);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $bytes);
        header('Cache-Control: no-store');
        header('Content-Encoding: identity');
        $block = random_bytes(65536); $left = $bytes;
        while ($left > 0 && !connection_aborted()) { $n = min($left, 65536); echo $n === 65536 ? $block : substr($block, 0, $n); $left -= $n; flush(); }
        exit;
    }
}
