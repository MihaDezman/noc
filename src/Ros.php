<?php
declare(strict_types=1);

/**
 * RouterOS verzije: zadnja stabilna verzija in varnostni popravki iz uradnih changelogov (upgrade.mikrotik.com).
 * Strežnik jih osveži enkrat dnevno (cron/daily.php); stran bere samo predpomnjene datoteke.
 * Varnostni popravek = vrstica changeloga, ki omenja CVE, "security" ali "vulnerab".
 */
final class Ros
{
    private const BASE = 'https://upgrade.mikrotik.com/routeros/';

    private static function dir(): string { return rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/ros-changelog'; }

    /** Verzija iz niza "7.20.2 (stable)" ali '' */
    public static function ver(?string $s): string
    {
        return preg_match('/^(\d+\.\d+(?:\.\d+)?)/', trim((string)$s), $m) ? $m[1] : '';
    }

    public static function latest(): string { return explode('|', setting('ros_latest', ''))[0]; }

    private static function get(string $url): ?string
    {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: noc.dezman.net\r\n"]]));
        $code = (int)(preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
        return $code === 200 && $raw !== false ? $raw : null;
    }

    /** Changelog verzije iz predpomnilnika: niz, '' (ne obstaja) ali null (še ni prenesen) */
    public static function changelog(string $v): ?string
    {
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $v)) return '';
        $f = self::dir() . "/$v.txt";
        if (is_file($f)) return (string)file_get_contents($f);
        return is_file(self::dir() . "/$v.none") ? '' : null;
    }

    /** Cron: zadnja stabilna verzija + changelogi verzij med najstarejšo nameščeno in zadnjo */
    public static function refresh(): string
    {
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0750, true);
        $raw = self::get(self::BASE . 'NEWESTa7.stable');
        $latest = $raw !== null && preg_match('/^(7\.\d+(?:\.\d+)?)/', trim($raw), $m) ? $m[1] : self::latest();
        if ($latest === '') return 'RouterOS: zadnja verzija ni znana';
        setting_set('ros_latest', $latest . '|' . time());
        $vers = array_filter(array_map(fn($x) => self::ver((string)$x), db()->query("SELECT DISTINCT os_version FROM devices WHERE active=1")->fetchAll(PDO::FETCH_COLUMN)), fn($v) => str_starts_with($v, '7.'));
        $min = $vers ? array_reduce($vers, fn($a, $b) => $a === null || version_compare($b, $a, '<') ? $b : $a) : $latest;
        $n = 0; $gone = [];
        foreach (self::between($min, $latest, true) as $v) {
            $parts = explode('.', $v); $minor = $parts[1]; $patch = (int)($parts[2] ?? 0);
            if (isset($gone[$minor])) continue;   // veja nima več popravkov
            $none = self::dir() . "/$v.none";
            if (self::changelog($v) !== null && !(is_file($none) && filemtime($none) < time() - 7 * 86400)) { if ($patch > 0 && is_file($none)) $gone[$minor] = 1; continue; }
            $c = self::get(self::BASE . "$v/CHANGELOG");
            if ($c !== null) { file_put_contents(self::dir() . "/$v.txt", $c); @unlink($none); $n++; }
            else { touch($none); if ($patch > 0) $gone[$minor] = 1; }
        }
        return "RouterOS $latest" . ($n ? ", changelogov: $n" : '');
    }

    /** Kandidati verzij (min, latest]; $probe = tudi verzije, ki morda ne obstajajo (za prenos) */
    private static function between(string $from, string $to, bool $probe = false): array
    {
        [$fa, $fb] = array_map('intval', array_slice(explode('.', $from) + [0, 0], 0, 2));
        [$ta, $tb, $tc] = array_map('intval', array_slice(explode('.', $to) + [0, 0, 0], 0, 3));
        if ($fa !== 7 || $ta !== 7) return [];
        $out = [];
        for ($minor = $fb; $minor <= $tb; $minor++) {
            $maxPatch = $minor === $tb ? $tc : 20;
            for ($p = 0; $p <= $maxPatch; $p++) {
                $v = $p === 0 ? "7.$minor" : "7.$minor.$p";
                if (version_compare($v, $from, '<=') || version_compare($v, $to, '>')) continue;
                if (!$probe && self::changelog($v) === '') { if ($p > 0) break; continue; }
                $out[] = $v;
            }
        }
        return $out;
    }

    /** Varnostni popravki v verzijah, novejših od nameščene: [[verzija, besedilo]] */
    public static function securityFixes(string $installed): array
    {
        $installed = self::ver($installed); $latest = self::latest();
        if ($installed === '' || $latest === '' || version_compare($installed, $latest, '>=')) return [];
        $out = [];
        foreach (self::between($installed, $latest) as $v) {
            foreach (preg_split('/\R/', (string)self::changelog($v)) as $l) {
                $l = trim($l);
                if ($l !== '' && preg_match('/CVE-\d{4}-\d+|security|vulnerab|exploit/i', $l)) $out[] = [$v, mb_substr(ltrim($l, '*) '), 0, 300)];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ načrtovana posodobitev (ukaz prek /api/action)
    /** Ukazi za router: scheduler, ki ob 03:30 namesti zadnjo stabilno verzijo in se nato sam izbriše */
    public static function upgradeRsc(int $actionId, string $base, string $key, string $chk): string
    {
        $done = ":do { /tool fetch url=\"$base/api/action-done/$actionId\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}";
        $fail = ":do { /tool fetch url=\"$base/api/action-done/$actionId?fail=1\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}";
        $ev = '/system scheduler remove [find name=\\"noc-upgrade\\"]; /system package update set channel=stable; /system package update check-for-updates once; :delay 20s; /system package update install';
        return "# NOC: nacrtovana posodobitev RouterOS (ukaz #$actionId)\n"
            . ":do { /system scheduler remove [find name=\"noc-upgrade\"] } on-error={}\n"
            . ":do { /system scheduler add name=\"noc-upgrade\" start-time=03:30:00 interval=1d policy=read,write,policy,test,reboot comment=\"noc: posodobitev RouterOS\" on-event=\"$ev\" } on-error={}\n"
            . "{ :local nocPol \"\"\n:do { :set nocPol [:tostr [/system scheduler get [find name=\"noc-upgrade\"] policy]] } on-error={}\n"
            . ":if (\$nocPol~\"reboot\") do={ :log info \"noc: posodobitev nacrtovana ob 03:30\"; $done } else={ :do { /system scheduler remove [find name=\"noc-upgrade\"] } on-error={}; :log warning \"noc: posodobitve ni mogoce nacrtovati (manjka pravica reboot)\"; $fail } }\n";
    }
}
