<?php
declare(strict_types=1);

/**
 * WireGuard uporabniki (peerji) na routerjih.
 * NOC ustvari ključe uporabnika, izdela config/QR in peer doda na router: ob pushu ("act":1) router prenese /api/action,
 * kjer je poleg ukrepov tudi uskladitev peerjev. Stanje se potrdi iz naslednjega pusha (router pošlje javne ključe peerjev),
 * zato ni potrebe po ločeni potrditvi. Peerji, ki jih NOC ne pozna (dodani ročno), se samodejno prikažejo (vir: router).
 * Zasebni ključi uporabnikov so šifrirani (enc); vidi jih samo skrbnik.
 */
final class Wg
{
    public const DEFAULT_DNS = '1.1.1.1, 8.8.8.8';

    // ------------------------------------------------------------------ ključi
    public static function validKey(mixed $k): bool
    {
        return is_string($k) && preg_match('#^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$#', $k) === 1;
    }

    /** [zasebni, javni] – Curve25519 kot "wg genkey | wg pubkey" */
    public static function keypair(): array
    {
        $sk = random_bytes(32);
        $sk[0] = chr(ord($sk[0]) & 248); $sk[31] = chr((ord($sk[31]) & 127) | 64);
        return [base64_encode($sk), base64_encode(sodium_crypto_scalarmult_base($sk))];
    }

    public static function pubFromPriv(string $priv): ?string
    {
        if (!self::validKey($priv)) return null;
        $sk = base64_decode($priv, true);
        return $sk === false || strlen($sk) !== 32 ? null : base64_encode(sodium_crypto_scalarmult_base($sk));
    }

    public static function psk(): string { return base64_encode(random_bytes(32)); }

    // ------------------------------------------------------------------ stanje z routerja
    /** WireGuard vmesniki z zadnjega pusha: [ime => ['pk','p','a','dis']] */
    public static function ifaces(array $d): array
    {
        $s = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        $out = [];
        foreach ((array)($s['wgi'] ?? []) as $w) if (is_array($w) && ($w['n'] ?? '') !== '') $out[(string)$w['n']] = ['pk' => (string)($w['pk'] ?? ''), 'p' => (int)($w['p'] ?? 0), 'a' => (string)($w['a'] ?? ''), 'dis' => !empty($w['dis'])];
        return $out;
    }

    /** Peerji z zadnjega pusha, po javnem ključu */
    public static function routerPeers(array $d): array
    {
        $s = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        $out = [];
        foreach ((array)($s['wg'] ?? []) as $p) if (is_array($p) && self::validKey($p['pk'] ?? null)) $out[$p['pk']] = $p;
        return $out;
    }

    /** Ali router že pošilja podatke za upravljanje (nova skripta) */
    public static function ready(array $d): bool
    {
        $s = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        return array_key_exists('wgi', $s);
    }

    /** Vmesniki, ki delujejo kot strežnik (imajo port in naslov) */
    public static function serverIfaces(array $d): array
    {
        return array_filter(self::ifaces($d), fn($w) => $w['p'] > 0 && $w['a'] !== '' && self::validKey($w['pk']));
    }

    public static function peers(int $deviceId): array
    {
        $st = db()->prepare('SELECT * FROM wg_peers WHERE device_id=? ORDER BY iface, INET_ATON(ip), name'); $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    public static function get(int $deviceId, int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM wg_peers WHERE id=? AND device_id=?'); $st->execute([$id, $deviceId]);
        return $st->fetch() ?: null;
    }

    // ------------------------------------------------------------------ uskladitev ob pushu
    /** Primerja želeno stanje (wg_peers) s peerji na routerju; kliče Ingest po vsakem pushu */
    public static function sync(array $d, array $b): void
    {
        if (!array_key_exists('wgi', $b)) return;   // stara skripta brez javnih ključev
        $id = (int)$d['id']; $pdo = db();
        $router = [];
        foreach ((array)($b['wg'] ?? []) as $p) if (is_array($p) && self::validKey($p['pk'] ?? null)) $router[$p['pk']] = $p;
        $complete = empty($b['_wg_cut']);   // seznam peerjev je bil skrajšan: manjkajočih ne označuj
        $rows = self::peers($id);
        $known = []; foreach ($rows as $r) { $known[$r['pubkey']] = 1; if ($r['old_pubkey']) $known[$r['old_pubkey']] = 1; }
        foreach ($rows as $r) {
            $rp = $router[$r['pubkey']] ?? null;
            $upd = null;
            switch ($r['status']) {
                case 'sync': case 'failed':
                    if ($rp && self::sameAllowed((string)($rp['a'] ?? ''), $r['allowed']) && (empty($rp['dis']) === (bool)$r['enabled'])) $upd = ['status' => 'ok', 'tries' => 0, 'old_pubkey' => null];
                    break;
                case 'delete': case 'delfail':
                    if ($complete && !$rp && !($r['old_pubkey'] && isset($router[$r['old_pubkey']]))) { $pdo->prepare('DELETE FROM wg_peers WHERE id=?')->execute([$r['id']]); audit('wg.removed', "{$d['name']} {$r['name']}"); }
                    break;
                case 'ok':
                    if (!$rp) {
                        if (!$complete) break;
                        if ($r['source'] === 'router' && !$r['priv_enc']) $pdo->prepare('DELETE FROM wg_peers WHERE id=?')->execute([$r['id']]);   // izbrisan na routerju, ključa nimamo
                        else $upd = ['status' => 'missing'];
                    }
                    else {   // spremembe, narejene neposredno na routerju, NOC prevzame
                        $a = ros_clean((string)($rp['a'] ?? ''));
                        if (strlen($a) <= 2000 && !self::sameAllowed($a, $r['allowed'])) $upd = ['allowed' => $a, 'ip' => self::firstIp($a)];
                        if (empty($rp['dis']) !== (bool)$r['enabled']) $upd = ($upd ?? []) + ['enabled' => empty($rp['dis']) ? 1 : 0];
                    }
                    break;
                case 'missing':
                    if ($rp) $upd = ['status' => 'ok'];
                    elseif ($complete && $r['source'] === 'router' && !$r['priv_enc']) { $pdo->prepare('DELETE FROM wg_peers WHERE id=?')->execute([$r['id']]); }   // izbrisan na routerju, ključa nimamo
                    break;
            }
            if ($upd) {   // samo, če se stanje medtem ni spremenilo (npr. skrbnik je ravno kliknil izklop)
                $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($upd)));
                $pdo->prepare("UPDATE wg_peers SET $set WHERE id=? AND status=? AND updated_at=?")->execute(array_merge(array_values($upd), [$r['id'], $r['status'], $r['updated_at']]));
            }
        }
        // peerji na routerju, ki jih NOC še ne pozna (dodani ročno ali pred NOC)
        foreach ($router as $pk => $p) {
            if (isset($known[$pk])) continue;
            $a = mb_substr(ros_clean((string)($p['a'] ?? '')), 0, 2000);
            $name = mb_substr(ros_clean((string)(($p['c'] ?? '') !== '' ? $p['c'] : ($p['n'] ?? ''))), 0, 100);
            $pdo->prepare("INSERT IGNORE INTO wg_peers (device_id, iface, name, pubkey, ip, allowed, enabled, status, source) VALUES (?,?,?,?,?,?,?, 'ok', 'router')")
                ->execute([$id, mb_substr(ros_clean((string)($p['i'] ?? '')), 0, 64), $name, $pk, self::firstIp($a), $a, empty($p['dis']) ? 1 : 0]);
        }
    }

    private static function sameAllowed(string $a, string $b): bool
    {
        $n = function (string $s): array { $x = array_filter(array_map('trim', preg_split('/[;,\s]+/', $s))); $x = array_map(fn($v) => str_contains($v, '/') ? $v : $v . '/32', $x); sort($x); return $x; };
        return $n($a) === $n($b);
    }

    public static function firstIp(string $allowed): string
    {
        foreach (preg_split('/[;,\s]+/', $allowed) as $a) {   // samo posamezen naslov (/32), ne omrežje (site-to-site)
            [$ip, $m] = array_pad(explode('/', trim($a), 2), 2, '32');
            if ($m === '32' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
        }
        return '';
    }

    // ------------------------------------------------------------------ pošiljanje na router
    /** Ali ima router neuporabljene spremembe peerjev (za odgovor na push) */
    public static function pending(int $deviceId): bool
    {
        db()->prepare("UPDATE wg_peers SET status=IF(status='delete', 'delfail', 'failed') WHERE device_id=? AND status IN ('sync','delete') AND tries >= 5 AND sent_at < NOW() - INTERVAL 10 MINUTE")->execute([$deviceId]);
        $st = db()->prepare("SELECT 1 FROM wg_peers WHERE device_id=? AND status IN ('sync','delete') AND (sent_at IS NULL OR sent_at < NOW() - INTERVAL 10 MINUTE) LIMIT 1"); $st->execute([$deviceId]);
        return (bool)$st->fetchColumn();
    }

    /** Ukazi za router (del /api/action); vsak ukaz je idempotenten – ponovitev ne škodi */
    public static function rsc(array $d): string
    {
        $st = db()->prepare("SELECT * FROM wg_peers WHERE device_id=? AND status IN ('sync','delete') AND (sent_at IS NULL OR sent_at < NOW() - INTERVAL 10 MINUTE) ORDER BY id LIMIT 50");
        $st->execute([$d['id']]); $rows = $st->fetchAll();
        if (!$rows) return '';
        $s = "# NOC WireGuard uporabniki\n";
        $sent = []; $bad = [];
        foreach ($rows as $r) {
            if (!self::validKey($r['pubkey']) || ($r['old_pubkey'] && !self::validKey($r['old_pubkey']))) { $bad[] = (int)$r['id']; continue; }
            $pk = $r['pubkey']; $nm = ros_esc($r['name']);
            if ($r['status'] === 'delete') {
                $s .= ":do { /interface wireguard peers remove [find where public-key=\"$pk\"] } on-error={ :log warning \"noc: wg $nm - brisanje ni uspelo\" }\n";
                if ($r['old_pubkey']) $s .= ":do { /interface wireguard peers remove [find where public-key=\"{$r['old_pubkey']}\"] } on-error={}\n";
                $sent[] = (int)$r['id'];
                continue;
            }
            // ime vmesnika mora ostati natančno tako (brez transliteracije), sicer bi peer pristal na napačnem vmesniku
            $al = self::rosAllowed($r['allowed']);
            if (!preg_match('/^[\x21-\x7e][\x20-\x7e]{0,63}$/', $r['iface']) || $al === '') { $bad[] = (int)$r['id']; continue; }
            $if = ros_esc($r['iface']); $sent[] = (int)$r['id'];
            $psk = $r['psk_enc'] ? dec($r['psk_enc']) : '';
            $pskArg = self::validKey($psk) ? " preshared-key=\"$psk\"" : '';
            $dis = (int)$r['enabled'] ? 'no' : 'yes';
            $common = "public-key=\"$pk\" allowed-address=$al comment=\"$nm\" disabled=$dis$pskArg";
            $s .= ":do {\n  :local f [/interface wireguard peers find where public-key=\"$pk\"]\n";
            if ($r['old_pubkey']) $s .= "  :if ([:len \$f] = 0) do={ :set f [/interface wireguard peers find where public-key=\"{$r['old_pubkey']}\"] }\n";
            $s .= "  :if ([:len \$f] = 0) do={ /interface wireguard peers add interface=\"$if\" $common } else={ /interface wireguard peers set \$f $common }\n"
                . "  :log info \"noc: wg $nm usklajen\"\n} on-error={ :log warning \"noc: wg $nm - uskladitev ni uspela\" }\n";
        }
        if ($sent) db()->exec('UPDATE wg_peers SET sent_at=NOW(), tries=tries+1 WHERE id IN (' . implode(',', $sent) . ')');
        if ($bad) db()->exec("UPDATE wg_peers SET status=IF(status='delete', 'delfail', 'failed') WHERE id IN (" . implode(',', $bad) . ')');
        return $sent ? $s : '';
    }

    /** allowed-address v obliki za RouterOS (seznam CIDR, ločen z vejico) ali '' */
    private static function rosAllowed(string $a): string
    {
        $out = [];
        foreach (array_filter(array_map('trim', preg_split('/[;,\s]+/', $a))) as $c) {
            [$ip, $m] = array_pad(explode('/', $c, 2), 2, null);
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) return '';
            $max = $v6 ? 128 : 32; $m = $m === null ? $max : $m;
            if (!ctype_digit((string)$m) || (int)$m > $max) return '';
            $out[] = $ip . '/' . (int)$m;
        }
        return implode(',', $out);
    }

    // ------------------------------------------------------------------ urejanje
    /** Prvi prosti naslov v omrežju vmesnika */
    public static function suggestIp(array $d, string $iface): string
    {
        $w = self::ifaces($d)[$iface] ?? null; if (!$w || !str_contains($w['a'], '/')) return '';
        [$ip, $m] = explode('/', $w['a']); $m = (int)$m;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $m < 16 || $m > 30) return '';
        $used = [$ip => 1];
        foreach (self::peers((int)$d['id']) as $r) foreach (preg_split('/[;,\s]+/', $r['allowed']) as $a) $used[explode('/', trim($a))[0]] = 1;
        foreach (self::routerPeers($d) as $p) foreach (preg_split('/[;,\s]+/', (string)($p['a'] ?? '')) as $a) $used[explode('/', trim($a))[0]] = 1;
        $net = ip2long($ip) & (-1 << (32 - $m)); $size = 1 << (32 - $m);
        for ($i = 2; $i < $size - 1; $i++) { $c = long2ip($net + $i); if (!isset($used[$c])) return $c; }
        return '';
    }

    /** Ali je IP v omrežju vmesnika in prost */
    public static function checkIp(array $d, string $iface, string $ip, ?int $exceptId = null): string
    {
        $w = self::ifaces($d)[$iface] ?? null;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return A('ni veljaven IPv4 naslov');
        if ($w && str_contains($w['a'], '/')) {
            [$gw, $m] = explode('/', $w['a']);
            if (!ip_in_cidr($ip, long2ip(ip2long($gw) & (-1 << (32 - (int)$m))) . '/' . (int)$m)) return A('naslov ni v omrežju vmesnika {i} ({a})', ['i' => $iface, 'a' => $w['a']]);
            if ($ip === $gw) return A('to je naslov routerja');
        }
        foreach (self::peers((int)$d['id']) as $r) if ((int)$r['id'] !== $exceptId && $r['status'] !== 'delete' && in_array($ip, array_map(fn($a) => explode('/', trim($a))[0], preg_split('/[;,\s]+/', $r['allowed'])), true)) return A('naslov že uporablja {n}', ['n' => $r['name'] ?: '?']);
        return '';
    }

    /** Promet skozi tunel: vse ali samo omrežje vmesnika + LAN */
    public static function clientAllowed(array $d, string $iface, string $mode): string
    {
        if ($mode !== 'lan') return '0.0.0.0/0, ::/0';
        $nets = [];
        $w = self::ifaces($d)[$iface] ?? null;
        if ($w && str_contains($w['a'], '/')) { [$gw, $m] = explode('/', $w['a']); $nets[] = long2ip(ip2long($gw) & (-1 << (32 - (int)$m))) . '/' . (int)$m; }
        foreach (preg_split('/[;,\s]+/', (string)$d['lan_networks']) as $n) if (preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', trim($n))) $nets[] = trim($n);
        return implode(', ', array_unique($nets)) ?: '0.0.0.0/0, ::/0';
    }

    public static function create(array $d, string $iface, string $name, string $kind, string $ip, string $mode, string $dns, string $by): int|string
    {
        if (!isset(self::serverIfaces($d)[$iface])) return A('vmesnik {i} ni WireGuard strežnik (manjka port ali naslov)', ['i' => $iface]);
        $name = mb_substr(trim(ros_clean($name)), 0, 100); if ($name === '') return A('vpiši ime');
        if (($e = self::checkIp($d, $iface, $ip)) !== '') return $e;
        $dns = self::cleanDns($dns);
        [$priv, $pub] = self::keypair();
        db()->prepare("INSERT INTO wg_peers (device_id, iface, name, kind, pubkey, priv_enc, psk_enc, ip, allowed, client_allowed, dns, enabled, status, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,'sync','noc',?)")
            ->execute([$d['id'], $iface, $name, in_array($kind, ['phone', 'pc'], true) ? $kind : 'other', $pub, enc($priv), enc(self::psk()), $ip, $ip . '/32', self::clientAllowed($d, $iface, $mode), $dns, mb_substr($by, 0, 60)]);
        $id = (int)db()->lastInsertId();
        audit('wg.create', "{$d['name']} $name $ip");
        return $id;
    }

    public static function cleanDns(string $dns): string
    {
        $ok = array_filter(array_map('trim', preg_split('/[,\s]+/', $dns)), fn($x) => (bool)filter_var($x, FILTER_VALIDATE_IP));
        return $ok ? implode(', ', array_slice($ok, 0, 4)) : self::DEFAULT_DNS;
    }

    /** Sprememba imena/naslova/DNS/prometa – kar gre na router, se ponovno uskladi */
    public static function update(array $d, array $r, string $name, string $ip, string $dns, string $mode): string
    {
        if (in_array($r['status'], ['delete', 'delfail'], true)) return A('uporabnik se briše');
        $name = mb_substr(trim(ros_clean($name)), 0, 100); if ($name === '') return A('vpiši ime');
        $toRouter = $name !== $r['name'];
        $allowed = $r['allowed'];
        if ($ip !== $r['ip']) {
            if (($e = self::checkIp($d, $r['iface'], $ip, (int)$r['id'])) !== '') return $e;
            // site-to-site peer z več omrežji: zamenja se samo naslov uporabnika
            $new = $r['ip'] !== '' ? (string)preg_replace('#(^|[;,\s])' . preg_quote($r['ip'], '#') . '(/32)?(?=$|[;,\s])#', '${1}' . $ip . '/32', $allowed) : $allowed;
            if ($new === $allowed) {   // star naslov ni v seznamu: zamenjamo samo, če peer nima drugih omrežij
                if (array_filter(preg_split('/[;,\s]+/', $allowed), fn($x) => trim($x) !== '' && !preg_match('#^[\d.]+(/32)?$#', trim($x)))) return A('peer ima na routerju več omrežij – naslov spremeni na routerju');
                $new = $ip . '/32';
            }
            $allowed = $new;
            $toRouter = true;
        }
        $ca = $mode === 'keep' ? $r['client_allowed'] : self::clientAllowed($d, $r['iface'], $mode);
        $toRouter = $toRouter && in_array($r['status'], ['ok', 'sync', 'failed'], true);   // manjkajočega doda šele "Dodaj nazaj"
        db()->prepare('UPDATE wg_peers SET name=?, ip=?, allowed=?, dns=?, client_allowed=?' . ($toRouter ? ", status='sync', tries=0, sent_at=NULL" : '') . ' WHERE id=?')
            ->execute([$name, $ip, $allowed, self::cleanDns($dns), $ca, $r['id']]);
        audit('wg.update', "{$d['name']} $name");
        return '';
    }

    public static function setEnabled(array $d, array $r, bool $on): void
    {
        $live = in_array($r['status'], ['ok', 'sync', 'failed'], true);
        db()->prepare('UPDATE wg_peers SET enabled=?' . ($live ? ", status='sync', tries=0, sent_at=NULL" : '') . ' WHERE id=?')->execute([$on ? 1 : 0, $r['id']]);
        audit($on ? 'wg.enable' : 'wg.disable', "{$d['name']} {$r['name']}");
    }

    public static function remove(array $d, array $r): void
    {
        $rp = self::routerPeers($d);
        $onRouter = isset($rp[$r['pubkey']]) || ($r['old_pubkey'] && isset($rp[$r['old_pubkey']]));
        if ($r['status'] === 'missing' || (!$onRouter && !$r['sent_at'])) {   // na routerju ga ni in mu ga tudi nismo poslali: samo izbris
            db()->prepare('DELETE FROM wg_peers WHERE id=?')->execute([$r['id']]);
        } else {
            db()->prepare("UPDATE wg_peers SET status='delete', tries=0, sent_at=NULL WHERE id=?")->execute([$r['id']]);
        }
        audit('wg.delete', "{$d['name']} {$r['name']}");
    }

    /** Nov par ključev (npr. izgubljen telefon ali uvožen peer brez zasebnega ključa); router prevzame nov javni ključ */
    public static function rotate(array $d, array $r): void
    {
        [$priv, $pub] = self::keypair();
        // stari ključ si zapomnimo samo, če je na routerju (da ga router zamenja namesto doda novega peerja)
        $rp = self::routerPeers($d);
        $old = isset($rp[$r['pubkey']]) ? $r['pubkey'] : ($r['old_pubkey'] && isset($rp[$r['old_pubkey']]) ? $r['old_pubkey'] : null);
        $live = in_array($r['status'], ['ok', 'sync', 'failed'], true);
        db()->prepare('UPDATE wg_peers SET old_pubkey=?, pubkey=?, priv_enc=?, psk_enc=?' . ($live ? ", status='sync', tries=0, sent_at=NULL" : '') . ' WHERE id=?')
            ->execute([$old, $pub, enc($priv), enc(self::psk()), $r['id']]);
        audit('wg.rotate', "{$d['name']} {$r['name']}");
    }

    /** Ponovno doda peer, ki ga na routerju ni več */
    public static function restore(array $d, array $r): void
    {
        db()->prepare('UPDATE wg_peers SET status=?, tries=0, sent_at=NULL WHERE id=?')->execute([$r['status'] === 'delfail' ? 'delete' : 'sync', $r['id']]);
        audit('wg.restore', "{$d['name']} {$r['name']}");
    }

    // ------------------------------------------------------------------ config uporabnika
    /** Endpoint za config: wg_endpoint naprave (domena[:port]) ali javni IP + port vmesnika */
    public static function endpoint(array $d, string $iface): string
    {
        $port = (self::ifaces($d)[$iface]['p'] ?? 0) ?: 51820;
        $e = trim((string)($d['wg_endpoint'] ?? ''));
        if ($e !== '') return preg_match('/:\d+$/', $e) ? $e : $e . ':' . $port;
        $ip = (string)($d['public_ip'] ?: $d['last_seen_ip']);
        return $ip !== '' ? $ip . ':' . $port : '';
    }

    public static function config(array $d, array $r): ?string
    {
        $priv = $r['priv_enc'] ? dec($r['priv_enc']) : '';
        $srv = self::ifaces($d)[$r['iface']]['pk'] ?? '';
        if (!self::validKey($priv) || !self::validKey($srv) || $r['ip'] === '') return null;
        $psk = $r['psk_enc'] ? dec($r['psk_enc']) : '';
        return "[Interface]\nPrivateKey = $priv\nAddress = {$r['ip']}/32\n" . ($r['dns'] !== '' ? "DNS = {$r['dns']}\n" : '')
            . "\n[Peer]\nPublicKey = $srv\n" . (self::validKey($psk) ? "PresharedKey = $psk\n" : '')
            . "AllowedIPs = {$r['client_allowed']}\nEndpoint = " . self::endpoint($d, $r['iface']) . "\nPersistentKeepalive = 25\n";
    }

    public static function fileName(array $d, array $r): string
    {
        $s = preg_replace(['/[^A-Za-z0-9_-]+/', '/-{2,}/'], ['-', '-'], (string)(iconv('UTF-8', 'ASCII//TRANSLIT', $d['name'] . '-' . $r['name']) ?: 'wg'));
        return (trim(substr(trim($s, '-'), 0, 32), '-') ?: 'wg') . '.conf';   // ime datoteke = ime tunela; WireGuard za Windows dovoli največ 32 znakov
    }

    // ------------------------------------------------------------------ uvoz iz "WireGuard Generator Pro"
    /**
     * Analiza datoteke data.json: vrne ['rows' => [...], 'stats' => [...]].
     * Vsak uporabnik iz zgodovine se poveže z napravo po javnem ključu routerja (iz configa) in s peerjem po javnem ključu,
     * izračunanem iz zasebnega ključa (točno ujemanje, brez ugibanja po imenih).
     */
    public static function importParse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['history']) || !is_array($data['history'])) throw new \RuntimeException(A('Datoteka ni v pričakovani obliki (manjka "history").'));
        // javni ključi strežnikov po endpointu (za vnose brez configa)
        $mtByEp = [];
        foreach ((array)($data['mikrotiks'] ?? []) as $m) if (is_array($m) && self::validKey($m['pubkey'] ?? null)) $mtByEp[trim((string)($m['endpoint'] ?? ''))] = ['pk' => $m['pubkey'], 'name' => (string)($m['name'] ?? '')];
        // naprave v NOC po javnem ključu WireGuard vmesnika
        $devByPk = [];
        foreach (db()->query('SELECT * FROM devices WHERE active=1')->fetchAll() as $d)
            foreach (self::ifaces($d) as $n => $w) if (self::validKey($w['pk'])) $devByPk[$w['pk']] = [$d, $n];
        $rows = []; $seen = [];
        foreach ($data['history'] as $h) {
            if (!is_array($h)) continue;
            $priv = trim((string)($h['privateKey'] ?? ''));
            $pub = self::pubFromPriv($priv);
            $cfg = (string)($h['config'] ?? '');
            $srv = preg_match('/\[Peer\][^\[]*?PublicKey\s*=\s*([A-Za-z0-9+\/=]{44})/s', $cfg, $m) ? $m[1] : ($mtByEp[trim((string)($h['endpoint'] ?? ''))]['pk'] ?? '');
            $row = [
                'name' => mb_substr(trim(ros_clean((string)($h['comment'] ?? ''))), 0, 100), 'kind' => ['phone' => 'phone', 'pc' => 'pc', 'computer' => 'pc'][(string)($h['type'] ?? '')] ?? 'other',
                'ip' => preg_match('/Address\s*=\s*([\d.]+)/', $cfg, $m) ? $m[1] : explode('/', trim((string)($h['ip'] ?? '')))[0],
                'dns' => preg_match('/DNS\s*=\s*([^\n]+)/', $cfg, $m) ? trim($m[1]) : (string)($h['dns'] ?? ''),
                'client_allowed' => preg_match('/AllowedIPs\s*=\s*([^\n]+)/', $cfg, $m) ? mb_substr(trim($m[1]), 0, 500) : '0.0.0.0/0, ::/0',
                'psk' => preg_match('/PresharedKey\s*=\s*([A-Za-z0-9+\/=]{44})/', $cfg, $m) && self::validKey($m[1]) ? $m[1] : '',
                'endpoint' => trim((string)($h['endpoint'] ?? '')), 'ts' => (string)($h['timestamp'] ?? ''),
                'priv' => $priv, 'pub' => $pub, 'srv' => $srv, 'device_id' => null, 'device' => '', 'iface' => '', 'state' => '',
            ];
            if (!$pub) { $row['state'] = 'badkey'; $rows[] = $row; continue; }
            if (isset($h['pubkey']) && $h['pubkey'] !== '' && $h['pubkey'] !== $pub) { $row['state'] = 'badkey'; $rows[] = $row; continue; }   // zapis je poškodovan
            if (isset($seen[$pub])) continue;   // novejši vnos za isti ključ je že obdelan (zgodovina je od najnovejšega)
            $seen[$pub] = 1;
            if (!isset($devByPk[$srv])) { $row['state'] = 'nodevice'; $row['device'] = $mtByEp[$row['endpoint']]['name'] ?? $row['endpoint']; $rows[] = $row; continue; }
            [$d, $if] = $devByPk[$srv];
            $row['device_id'] = (int)$d['id']; $row['device'] = (string)$d['name']; $row['iface'] = $if;
            $row['state'] = isset(self::routerPeers($d)[$pub]) ? 'match' : 'notonrouter';
            $rows[] = $row;
        }
        $stats = array_count_values(array_column($rows, 'state'));
        return ['rows' => $rows, 'stats' => $stats];
    }

    /** Uvoz izbranih vrstic; $addMissing = indeksi vrstic "ni na routerju", ki jih je treba dodati */
    public static function importApply(array $parsed, array $addMissing, string $by): array
    {
        $n = ['imported' => 0, 'added' => 0, 'skipped' => 0];
        foreach ($parsed['rows'] as $i => $r) {
            if (!in_array($r['state'], ['match', 'notonrouter'], true)) continue;
            if ($r['state'] === 'notonrouter' && !in_array($i, $addMissing, true)) continue;
            $d = Devices::full((int)$r['device_id']); if (!$d) continue;
            $ip = filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $r['ip'] : '';
            $st = db()->prepare('SELECT * FROM wg_peers WHERE device_id=? AND pubkey=?'); $st->execute([$d['id'], $r['pub']]);
            if ($ex = $st->fetch()) {   // peer z routerja: dopolni s ključem in nastavitvami configa
                db()->prepare("UPDATE wg_peers SET priv_enc=?, psk_enc=COALESCE(?, psk_enc), kind=?, dns=?, client_allowed=?, name=IF(name='', ?, name), ip=IF(ip='', ?, ip), source=IF(source='router', 'import', source) WHERE id=?")
                    ->execute([enc($r['priv']), $r['psk'] !== '' ? enc($r['psk']) : null, $r['kind'], self::cleanDns($r['dns']), $r['client_allowed'], $r['name'], $ip, $ex['id']]);
                $n['imported']++;
            } else {
                if ($ip === '') continue;
                if ($r['state'] === 'notonrouter' && Wg::checkIp($d, $r['iface'], $ip) !== '') { $n['skipped']++; continue; }   // naslov je medtem zaseden
                db()->prepare("INSERT INTO wg_peers (device_id, iface, name, kind, pubkey, priv_enc, psk_enc, ip, allowed, client_allowed, dns, enabled, status, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?, 'import', ?)")
                    ->execute([$d['id'], $r['iface'], $r['name'] ?: $ip, $r['kind'], $r['pub'], enc($r['priv']), $r['psk'] !== '' ? enc($r['psk']) : null, $ip, $ip . '/32', $r['client_allowed'], self::cleanDns($r['dns']),
                               $r['state'] === 'match' ? 'ok' : 'sync', mb_substr($by, 0, 60)]);
                $n[$r['state'] === 'match' ? 'imported' : 'added']++;
            }
            // endpoint iz generatorja (npr. domena ali drug port), če naprava še nima svojega
            $ep = $r['endpoint']; $host = preg_replace('/:\d+$/', '', $ep);
            if ($ep !== '' && trim((string)$d['wg_endpoint']) === '' && $host !== (string)$d['public_ip'] && preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $ep))
                db()->prepare('UPDATE devices SET wg_endpoint=? WHERE id=? AND (wg_endpoint IS NULL OR wg_endpoint=\'\')')->execute([$ep, $d['id']]);
        }
        audit('wg.import', "uvoženih {$n['imported']}, dodanih {$n['added']}");
        return $n;
    }

    public static function statusLabel(array $r): array
    {
        return ['ok' => ['up', A('na routerju')], 'sync' => ['info', A('čaka na router')], 'delete' => ['warn', A('se briše')],
                'missing' => ['down', A('ni na routerju')], 'failed' => ['down', A('ni uspelo')], 'delfail' => ['down', A('brisanje ni uspelo')]][$r['status']] ?? ['', $r['status']];
    }
}
