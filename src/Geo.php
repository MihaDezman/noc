<?php
declare(strict_types=1);

/**
 * Država in omrežje (ASN) za javne IPv4 naslove – DB-IP Lite (CC BY 4.0, https://db-ip.com), posodobitev enkrat mesečno.
 * Proizvajalec naprave po MAC naslovu – IEEE OUI.
 * Podatki so v bazi (geo_cc, geo_asn, mac_vendor); osvežitev zamenja vsebino v eni transakciji, bralci vidijo staro do konca.
 */
final class Geo
{
    private static array $cc = [], $asn = [];

    public static function attribution(): string
    {
        return '<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>';
    }

    private static function v4(string $ip): ?int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $n = ip2long($ip); return $n === false ? null : $n;
    }

    /** Dvočrkovna oznaka države ali '' */
    public static function cc(string $ip): string
    {
        if (isset(self::$cc[$ip])) return self::$cc[$ip];
        $n = self::v4($ip); $r = '';
        if ($n !== null) {
            static $st = null; $st ??= db()->prepare('SELECT ip_to, cc FROM geo_cc WHERE ip_from <= ? ORDER BY ip_from DESC LIMIT 1'); $st->execute([$n]);
            $row = $st->fetch(); if ($row && (int)$row['ip_to'] >= $n) $r = (string)$row['cc'];
        }
        if (count(self::$cc) > 20000) self::$cc = [];
        return self::$cc[$ip] = $r;
    }

    /** [asn, organizacija] ali [0, ''] */
    public static function asn(string $ip): array
    {
        if (isset(self::$asn[$ip])) return self::$asn[$ip];
        $n = self::v4($ip); $r = [0, ''];
        if ($n !== null) {
            static $st = null; $st ??= db()->prepare('SELECT ip_to, asn, org FROM geo_asn WHERE ip_from <= ? ORDER BY ip_from DESC LIMIT 1'); $st->execute([$n]);
            $row = $st->fetch(); if ($row && (int)$row['ip_to'] >= $n) $r = [(int)$row['asn'], (string)$row['org']];
        }
        if (count(self::$asn) > 20000) self::$asn = [];
        return self::$asn[$ip] = $r;
    }

    public static function ready(): bool
    {
        return (bool)db()->query('SELECT 1 FROM geo_cc LIMIT 1')->fetchColumn();
    }

    /** Ime države (slovensko, kjer ga poznamo) */
    public static function name(string $cc): string
    {
        static $n = null;
        $n ??= ['SI' => 'Slovenija', 'AT' => 'Avstrija', 'HR' => 'Hrvaška', 'IT' => 'Italija', 'HU' => 'Madžarska', 'DE' => 'Nemčija', 'FR' => 'Francija', 'NL' => 'Nizozemska',
                'GB' => 'Velika Britanija', 'IE' => 'Irska', 'US' => 'ZDA', 'CA' => 'Kanada', 'CN' => 'Kitajska', 'HK' => 'Hongkong', 'TW' => 'Tajvan', 'JP' => 'Japonska', 'KR' => 'Južna Koreja',
                'RU' => 'Rusija', 'UA' => 'Ukrajina', 'BY' => 'Belorusija', 'PL' => 'Poljska', 'CZ' => 'Češka', 'SK' => 'Slovaška', 'RS' => 'Srbija', 'BA' => 'Bosna in Hercegovina',
                'ME' => 'Črna gora', 'MK' => 'Severna Makedonija', 'BG' => 'Bolgarija', 'RO' => 'Romunija', 'GR' => 'Grčija', 'TR' => 'Turčija', 'ES' => 'Španija', 'PT' => 'Portugalska',
                'BE' => 'Belgija', 'LU' => 'Luksemburg', 'CH' => 'Švica', 'SE' => 'Švedska', 'NO' => 'Norveška', 'FI' => 'Finska', 'DK' => 'Danska', 'EE' => 'Estonija', 'LV' => 'Latvija',
                'LT' => 'Litva', 'IN' => 'Indija', 'SG' => 'Singapur', 'VN' => 'Vietnam', 'TH' => 'Tajska', 'ID' => 'Indonezija', 'BR' => 'Brazilija', 'AR' => 'Argentina', 'MX' => 'Mehika',
                'AU' => 'Avstralija', 'IL' => 'Izrael', 'IR' => 'Iran', 'AE' => 'ZAE', 'SA' => 'Savdska Arabija', 'ZA' => 'Južna Afrika', 'EG' => 'Egipt', 'NG' => 'Nigerija', 'KP' => 'Severna Koreja'];
        return $n[$cc] ?? $cc;
    }

    /** Zastavica iz kode države */
    public static function flag(string $cc): string
    {
        if (!preg_match('/^[A-Z]{2}$/', $cc)) return '🌐';
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    }

    // ------------------------------------------------------------------ osvežitev (cron/daily.php)
    /** Enkrat mesečno: DB-IP Lite država + ASN, IEEE OUI. Ob napaki ostanejo stari podatki. */
    public static function update(bool $force = false): string
    {
        $out = [];
        $month = date('Y-m');
        if ($force || setting('geo_month') !== $month || !self::ready()) {
            @set_time_limit(0);
            $ok = 0;
            foreach ([$month, date('Y-m', strtotime('first day of last month'))] as $m) {   // na začetku meseca nova datoteka še ni objavljena
                $c = self::import("https://download.db-ip.com/free/dbip-country-lite-$m.csv.gz", 'geo_cc', fn($r) => count($r) >= 3 && preg_match('/^[A-Z]{2}$/', $r[2]) ? [$r[2]] : null, 50000);
                if (!$c) continue;
                $a = self::import("https://download.db-ip.com/free/dbip-asn-lite-$m.csv.gz", 'geo_asn', fn($r) => count($r) >= 3 && ctype_digit($r[2]) ? [(int)$r[2], mb_substr($r[3] ?? '', 0, 120)] : null, 50000);
                $out[] = "DB-IP $m: " . number_format($c, 0, ',', '.') . " / " . number_format($a, 0, ',', '.');
                $ok = 1; break;
            }
            if ($ok) setting_set('geo_month', $month); else $out[] = 'DB-IP: prenos ni uspel';
        }
        if ($force || setting('oui_month') !== $month) {
            $n = self::importOui();
            if ($n > 10000) { setting_set('oui_month', $month); $out[] = "OUI: $n"; } else $out[] = 'OUI: prenos ni uspel';
        }
        return implode(', ', $out);
    }

    private static function download(string $url): ?string
    {
        $f = tempnam(sys_get_temp_dir(), 'noc-geo');
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: Mozilla/5.0 (noc.dezman.net)\r\n"]]);
        $in = @fopen($url, 'rb', false, $ctx); if (!$in) { @unlink($f); return null; }
        $o = fopen($f, 'wb'); stream_copy_to_stream($in, $o); fclose($in); fclose($o);
        if (filesize($f) < 1000) { @unlink($f); return null; }
        return $f;
    }

    /** Uvoz CSV (start,end,...) IPv4 -> tabela; vrne število vrstic ali 0 */
    private static function import(string $url, string $table, callable $map, int $min = 1000): int
    {
        $f = self::download($url); if (!$f) return 0;
        $h = gzopen($f, 'rb'); if (!$h) { @unlink($f); return 0; }
        $pdo = db(); $n = 0; $batch = [];
        $cols = $table === 'geo_cc' ? '(ip_from, ip_to, cc)' : '(ip_from, ip_to, asn, org)';
        $flush = function () use (&$batch, $pdo, $table, $cols) {
            if (!$batch) return;
            $ph = implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, count($batch[0]), '?')) . ')'));
            $pdo->prepare("INSERT IGNORE INTO $table $cols VALUES $ph")->execute(array_merge(...$batch)); $batch = [];
        };
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM $table");
            while (($line = gzgets($h)) !== false) {
                $r = str_getcsv(trim($line));
                if (count($r) < 3 || !filter_var($r[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($r[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                $v = $map($r); if ($v === null) continue;
                $batch[] = array_merge([ip2long($r[0]), ip2long($r[1])], $v); $n++;
                if (count($batch) >= 1000) $flush();
            }
            $flush();
            if ($n < $min || !gzeof($h)) { $pdo->rollBack(); $n = 0; } else $pdo->commit();   // prekinjen prenos: stari podatki ostanejo
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log("noc geo $table: " . $e->getMessage()); $n = 0; }
        gzclose($h); @unlink($f);
        self::$cc = self::$asn = [];
        return $n;
    }

    private static function importOui(string $url = 'https://standards-oui.ieee.org/oui/oui.csv'): int
    {
        $f = self::download($url); if (!$f) return 0;
        $h = fopen($f, 'rb'); $pdo = db(); $n = 0; $batch = [];
        try {
            $pdo->beginTransaction(); $pdo->exec('DELETE FROM mac_vendor');
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) < 3 || !preg_match('/^[0-9A-F]{6}$/', $r[1] ?? '')) continue;
                $batch[] = $r[1]; $batch[] = mb_substr(trim($r[2]), 0, 120); $n++;
                if (count($batch) >= 2000) { $pdo->prepare('INSERT IGNORE INTO mac_vendor VALUES ' . implode(',', array_fill(0, count($batch) / 2, '(?,?)')))->execute($batch); $batch = []; }
            }
            if ($batch) $pdo->prepare('INSERT IGNORE INTO mac_vendor VALUES ' . implode(',', array_fill(0, count($batch) / 2, '(?,?)')))->execute($batch);
            if ($n < 10000) { $pdo->rollBack(); $n = 0; } else $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('noc oui: ' . $e->getMessage()); $n = 0; }
        fclose($h); @unlink($f);
        return $n;
    }

    /** Proizvajalec po MAC naslovu ('' za naključne/zasebne MAC naslove telefonov) */
    public static function vendor(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        if (strlen($hex) < 6 || (hexdec($hex[1]) & 2)) return '';   // lokalno administriran (naključen) MAC
        $st = db()->prepare('SELECT vendor FROM mac_vendor WHERE prefix=?'); $st->execute([substr($hex, 0, 6)]);
        return (string)($st->fetchColumn() ?: '');
    }
}
