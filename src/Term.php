<?php
declare(strict_types=1);

/**
 * Terminal routerja v brskalniku: strežnik NOC se z routerjem poveže prek SSH (routerji so na javnih IP-jih),
 * brskalnik pa s strežnikom prek WebSocket (bin/noc-term.py, za Apache na /term/ws).
 *
 *  - na routerju uporabnik "noc-term" (skupina full) samo s ključem NOC; geslo je naključno in se nikjer ne shrani
 *  - SSH na routerju je dovoljen z IP-ja strežnika NOC (pravilo noc-term na vrhu input, NOC IP v available-from)
 *  - nastavitev na routerju samo z ročnim namestitvenim ukazom (kot požarni zid), ne s samodejno posodobitvijo
 *  - sejo odpre samo superadmin: enkratni žeton (60 s, vezan na uporabnika in IP brskalnika), vsaka seja v revizijski sled
 *  - izpis seje se shrani (data_dir/term/<id>.log) za pregled, kaj je bilo narejeno
 */
final class Term
{
    public const USER = 'noc-term';
    public const PORT = 8765;           // WebSocket most (samo 127.0.0.1)
    private const TOKEN_TTL = 60;

    private static function dir(string $sub = ''): string { return rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/' . $sub; }
    public static function keyFile(string $type): string { return self::dir('ssh') . '/id_' . ($type === 'rsa' ? 'rsa' : 'ed25519'); }
    public static function knownHosts(): string { return self::dir('ssh') . '/known_hosts'; }
    public static function logFile(int $sid): string { return self::dir('term') . "/$sid.log"; }

    /** Javni ključ ('' če ga ni) */
    public static function pubKey(string $type): string
    {
        $f = self::keyFile($type) . '.pub';
        return is_file($f) ? trim((string)file_get_contents($f)) : '';
    }

    public static function hasKeys(): bool { return is_file(self::keyFile('ed')) && is_file(self::keyFile('rsa')); }

    /** Ustvari ključa NOC (Ed25519 in RSA kot rezerva za starejše verzije RouterOS); vrne napako ali '' */
    public static function ensureKeys(): string
    {
        $dir = self::dir('ssh');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return A('mape {d} ni mogoče ustvariti', ['d' => $dir]);
        @chmod($dir, 0700);
        foreach (['ed' => '-t ed25519', 'rsa' => '-t rsa -b 3072'] as $t => $opt) {
            $f = self::keyFile($t);
            if (is_file($f)) continue;
            exec('ssh-keygen -q ' . $opt . ' -N "" -C "noc.dezman.net" -f ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
            if ($rc !== 0 || !is_file($f)) return A('ssh-keygen ni uspel: {e}', ['e' => implode(' ', $out)]);
            @chmod($f, 0600);
        }
        if (!is_file(self::knownHosts())) { @touch(self::knownHosts()); @chmod(self::knownHosts(), 0600); }
        return '';
    }

    /** Prstni odtis ključa za prikaz */
    public static function fingerprint(string $type): string
    {
        $f = self::keyFile($type) . '.pub'; if (!is_file($f)) return '';
        exec('ssh-keygen -lf ' . escapeshellarg($f) . ' 2>/dev/null', $out);
        return preg_match('/(SHA256:\S+)/', $out[0] ?? '', $m) ? $m[1] : '';
    }

    /** Ali most (bin/noc-term.py) posluša */
    public static function bridgeUp(): bool
    {
        $s = @fsockopen('127.0.0.1', self::PORT, $e, $m, 0.5);
        if ($s) { fclose($s); return true; }
        return false;
    }

    // ------------------------------------------------------------------ stanje na routerju
    /** Port SSH na routerju (iz zadnjega backupa, privzeto 22) */
    public static function sshPort(array $d): int
    {
        $a = Firewall::audit($d);
        return (int)($a['conf']['services']['ssh']['port'] ?? 22) ?: 22;
    }

    /** Ali router poroča uporabnika noc-term (seznam uporabnikov iz pusha) */
    public static function userOnRouter(array $d): ?bool
    {
        $s = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        if (!is_array($s['usr'] ?? null)) return null;
        foreach ($s['usr'] as $u) if (is_array($u) && ($u['n'] ?? '') === self::USER) return empty($u['dis']);
        return false;
    }

    /** Ukazi za namestitveni paket (samo ročna namestitev) */
    public static function rsc(array $d): string
    {
        $noc = (string)cfg('noc_ip', '');
        if (!(int)($d['term_enabled'] ?? 0)) {
            return "# --- terminal NOC: izklopljen (odstrani uporabnika in pravilo, ce obstajata)\n"
                . ":do { /user ssh-keys remove [find user=\"" . self::USER . "\"] } on-error={}\n"
                . ":do { /user remove [find name=\"" . self::USER . "\"] } on-error={}\n"
                . "/ip firewall filter remove [find comment~\"^noc-term\"]\n\n";
        }
        if ($noc === '' || !self::hasKeys()) return "# --- terminal NOC: kljuci na strezniku se niso ustvarjeni (Alarmi in obvestila > Terminal)\n\n";
        $base = rtrim((string)cfg('base_url'), '/'); $key = dec($d['api_key_enc'] ?? null);
        $chk = (int)($d['tls_check'] ?? 0) ? ' check-certificate=yes-without-crl' : '';
        $u = self::USER; $port = self::sshPort($d);
        $s = "# --- terminal NOC: SSH s streznika NOC ($noc), uporabnik $u samo s kljucem\n"
            . ":do { /tool fetch url=\"$base/api/term-key?t=ed\"$chk http-header-field=\"X-Api-Key: $key\" dst-path=noc-term-ed.pub } on-error={}\n"
            . ":do { /tool fetch url=\"$base/api/term-key?t=rsa\"$chk http-header-field=\"X-Api-Key: $key\" dst-path=noc-term-rsa.pub } on-error={}\n"
            . ":delay 2s\n"
            // geslo nastane na routerju (naključno, 27 števk) in ga nihče ne pozna – prijava samo s ključem
            . "{ :local nocPw (\"N\" . [:rndnum from=100000000 to=999999999] . [:rndnum from=100000000 to=999999999] . [:rndnum from=100000000 to=999999999])\n"
            . "  :if ([:len [/user find name=\"$u\"]] = 0) do={ /user add name=$u group=full password=\$nocPw comment=\"noc: terminal iz NOC (samo SSH kljuc)\" } else={ /user set [find name=\"$u\"] group=full disabled=no password=\$nocPw } }\n"
            . ":do { /user ssh-keys remove [find user=\"$u\"] } on-error={}\n"
            . ":do { /user ssh-keys import public-key-file=noc-term-ed.pub user=$u } on-error={ :do { /user ssh-keys import public-key-file=noc-term-rsa.pub user=$u } on-error={ :log warning \"noc: kljuca za terminal ni bilo mogoce uvoziti\" } }\n"
            . ":do { /file remove [find name~\"noc-term-\"] } on-error={}\n"
            . "/ip firewall filter remove [find comment~\"^noc-term\"]\n"
            . Firewall::top('/ip firewall filter', "chain=input src-address=$noc protocol=tcp dst-port=$port action=accept comment=\"noc-term: SSH s streznika NOC\"");
        // storitev ssh: vklopljena; če je omejena na naslove, dodamo naslov NOC (iz zadnjega backupa)
        $a = Firewall::audit($d); $svc = $a['conf']['services']['ssh'] ?? null;
        $set = fn(string $v) => "/ip service set ssh " . Firewall::svcAddrKey($d) . "=$v\n";
        if ($svc === null) $s .= "# storitev ssh: ni backupa – preveri, da je SSH vklopljen in dosegljiv z $noc\n";
        elseif (!($svc['on'] ?? true)) $s .= "/ip service set ssh disabled=no\n" . $set("$noc/32");
        elseif (($svc['address'] ?? '') !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', (string)$svc['address']))));
            $has = (bool)array_filter($list, fn($n) => str_contains($n, ':') ? false : ip_in_cidr($noc, str_contains($n, '/') ? $n : "$n/32"));
            if (!$has) $s .= $set(implode(',', array_map(fn($n) => preg_replace('/[^0-9a-fA-F.:\/]/', '', $n), $list)) . ",$noc/32");
        }
        return $s . ":log info \"noc: terminal NOC nastavljen\"\n\n";
    }

    // ------------------------------------------------------------------ seje
    /** Nov enkratni žeton za brskalnik; vrne žeton ali napako (string z začetnim '!') */
    public static function token(array $d, array $user): string
    {
        $t = random_token(24);
        db()->prepare("DELETE FROM term_sessions WHERE status='new' AND created_at < NOW() - INTERVAL 1 HOUR")->execute();
        db()->prepare('INSERT INTO term_sessions (device_id, user_id, token_hash, client_ip, created_at, status) VALUES (?,?,?,?,NOW(),\'new\')')
            ->execute([$d['id'], $user['id'], hash('sha256', $t), mb_substr(client_ip(), 0, 45)]);
        return $t;
    }

    /** CLI (most): preveri žeton in odpre sejo; vrne podatke za ssh ali ['error' => …] */
    public static function check(string $token, string $ip): array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) return ['error' => A('Neveljaven žeton.')];
        $st = db()->prepare("SELECT s.*, u.email, u.role, u.active u_active FROM term_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?");
        $st->execute([hash('sha256', $token)]); $s = $st->fetch();
        if (!$s || $s['status'] !== 'new') return ['error' => A('Žeton ne velja ali je že porabljen – osveži stran.')];
        if (strtotime((string)$s['created_at']) < time() - self::TOKEN_TTL) return ['error' => A('Žeton je potekel – osveži stran.')];
        if ($ip !== '' && $s['client_ip'] !== '' && $ip !== $s['client_ip']) return ['error' => A('Seja je bila odprta z drugega naslova.')];
        if ($s['role'] !== 'superadmin' || !(int)$s['u_active']) return ['error' => A('Terminal je na voljo samo skrbniku.')];
        $q = db()->prepare('SELECT * FROM devices WHERE id=? AND active=1'); $q->execute([$s['device_id']]); $d = $q->fetch();
        if (!$d || !(int)$d['term_enabled']) return ['error' => A('Terminal pri tej napravi ni vklopljen.')];
        $host = (string)$d['public_ip'];
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return ['error' => A('Naprava nima znanega javnega IP-ja.')];
        if (!self::hasKeys()) return ['error' => A('Ključi NOC še niso ustvarjeni.')];
        $up = db()->prepare("UPDATE term_sessions SET status='open', started_at=NOW() WHERE id=? AND status='new'"); $up->execute([$s['id']]);
        if (!$up->rowCount()) return ['error' => A('Žeton je že porabljen.')];
        db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')->execute([$s['user_id'], 'device.terminal', mb_substr("#{$s['id']} {$d['name']} ($host)", 0, 500), $ip ?: $s['client_ip']]);
        if (!is_dir(self::dir('term'))) @mkdir(self::dir('term'), 0750, true);
        return ['id' => (int)$s['id'], 'host' => $host, 'port' => self::sshPort($d), 'user' => self::USER, 'name' => (string)$d['name'],
                'keys' => [self::keyFile('ed'), self::keyFile('rsa')], 'known_hosts' => self::knownHosts(), 'log' => self::logFile((int)$s['id'])];
    }

    /** CLI (most): konec seje */
    public static function end(int $sid, int $bytes, string $reason): void
    {
        db()->prepare("UPDATE term_sessions SET status='closed', ended_at=NOW(), bytes=?, note=? WHERE id=? AND status='open'")->execute([$bytes, mb_substr($reason, 0, 100), $sid]);
    }

    public static function sessions(int $deviceId, int $limit = 10): array
    {
        $st = db()->prepare("SELECT s.*, u.email FROM term_sessions s LEFT JOIN users u ON u.id=s.user_id WHERE s.device_id=? AND s.status<>'new' ORDER BY s.id DESC LIMIT " . (int)$limit);
        $st->execute([$deviceId]); return $st->fetchAll();
    }

    /** Izpis seje brez ANSI kod (za pregled v brskalniku) */
    public static function transcript(int $sid): ?string
    {
        $f = self::logFile($sid); if (!is_file($f)) return null;
        $t = (string)file_get_contents($f);
        $t = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b[()][0-9A-Za-z]|\x1b[=>78DEHMc]|\x1b\][^\x07]*\x07/', '', $t);
        $t = str_replace("\r\n", "\n", $t);
        return preg_replace('/[^\n\t\x20-\x7E\x{80}-\x{10FFFF}]/u', '', mb_convert_encoding($t, 'UTF-8', 'UTF-8')) ?? '';
    }

    /** Čiščenje (cron daily): izpisi starejši od 180 dni */
    public static function purge(): string
    {
        $n = 0;
        foreach (glob(self::dir('term') . '/*.log') ?: [] as $f) if (filemtime($f) < time() - 180 * 86400) { @unlink($f); $n++; }
        db()->exec("UPDATE term_sessions SET status='closed', note='prekinjeno' WHERE status='open' AND started_at < NOW() - INTERVAL 1 DAY");
        return $n ? "izpisi terminala: $n" : '';
    }
}
