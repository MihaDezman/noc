<?php
declare(strict_types=1);

/**
 * Uveljavitev predlogov AI pregleda konfiguracije z enim klikom – v RouterOS Safe Mode prek SSH (uporabnik noc-term):
 *  1. NOC iz izbranih ugotovitev sestavi skripto in vsako vrstico preveri (prepovedano / tvegano / v redu)
 *  2. bin/noc-apply.py: SSH -> Safe Mode (Ctrl+X) -> ukaz za ukazom; ob napaki se seja prekine in router vse razveljavi
 *  3. na koncu router prek /tool fetch doseže NOC (preverjanje, da internet in povezava delujeta); šele nato se Safe Mode potrdi
 *  4. ugotovitve se označijo kot urejene, router naredi nov backup
 */
final class AiApply
{
    /** Ukazi, ki jih NOC nikoli ne izvede samodejno */
    private const BLOCK = [
        '/noc[-_.:]|noc\.dezman/i' => 'dotika se nastavitev NOC',
        '/reset-configuration|\/system\s+(reboot|shutdown)|backup\s+load|\/system\s+package\s+(downgrade|uninstall)/i' => 'ponastavitev ali ponovni zagon routerja',
        '/(^|[\s;{])(:execute|\/import)\b|\/system\s+(script|scheduler)|\/tool\s+fetch/i' => 'izvajanje skript ali prenos datotek',
        '/\/file\b/i' => 'spreminjanje datotek na routerju',
        '/\/user\s+(remove|group)/i' => 'brisanje uporabnikov ali skupin',
        '/\/certificate\b|\/ip\s+ssh\b/i' => 'certifikati ali nastavitve SSH (prek tega teče uveljavitev)',
    ];
    /** Ukazi, ki zahtevajo izrecno potrditev (lahko odrežejo dostop ali promet) */
    private const WARN = [
        '/\/user\b/i' => 'uporabniki routerja – preveri, da sam ne izgubiš dostopa',
        '/\/ip\s+service\b/i' => 'storitve (Winbox, SSH …) – preveri dovoljene naslove',
        '/action=(drop|reject)/i' => 'pravilo, ki zavrže promet',
        '/\/interface\s+list\b/i' => 'seznami vmesnikov (LAN/WAN) vplivajo na požarni zid',
        '/\b(remove|disable)\b/i' => 'odstranitev ali izklop',
        '/\/ip\s+(route|address|dhcp-client|pool|dhcp-server)\b/i' => 'naslovi in usmerjanje – lahko prekine promet',
        '/\/tool\s+mac-server|\/ip\s+neighbor/i' => 'dostop prek MAC',
    ];

    /** Vrstice popravka: brez komentarjev; večvrstične bloke {…} in nadaljevanja \ združi v eno vrstico */
    public static function lines(string $fix): array
    {
        $out = []; $buf = ''; $depth = 0;
        foreach (preg_split('/\R/', str_replace("\t", ' ', $fix)) as $l) {
            $l = rtrim($l);
            if ($buf === '' && (trim($l) === '' || str_starts_with(ltrim($l), '#'))) continue;
            if (str_ends_with($l, '\\')) { $buf .= rtrim(substr($l, 0, -1)) . ' '; continue; }
            $t = trim($l);
            $sep = $buf === '' ? '' : ($depth > 0 && !str_ends_with(rtrim($buf), '{') && !str_starts_with($t, '}') ? '; ' : ' ');
            $buf .= $sep . $t;
            $depth += substr_count($l, '{') - substr_count($l, '}');
            if ($depth <= 0) { $out[] = preg_replace('/;\s*;/', ';', trim($buf)); $buf = ''; $depth = 0; }
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return array_values(array_filter($out, fn($x) => $x !== ''));
    }

    /** [stopnja ok|warn|block, razlog] */
    public static function check(string $line): array
    {
        foreach (self::BLOCK as $re => $why) if (preg_match($re, $line)) return ['block', A($why)];
        if (preg_match('/[^\x20-\x7E]/', $line)) return ['block', A('nenavadni znaki v ukazu')];
        if (strlen($line) > 1500) return ['block', A('predolga vrstica')];
        foreach (self::WARN as $re => $why) if (preg_match($re, $line)) return ['warn', A($why)];
        return ['ok', ''];
    }

    /** Predogled: [[k, naslov, [[vrstica, stopnja, razlog]]]] za izbrane ugotovitve */
    public static function plan(array $review, array $keys): array
    {
        $f = json_decode((string)$review['findings'], true) ?: []; $out = [];
        foreach ($keys as $k) {
            $k = (int)$k; if (!isset($f[$k]) || trim((string)($f[$k]['fix'] ?? '')) === '') continue;
            $rows = [];
            foreach (self::lines((string)$f[$k]['fix']) as $l) { [$lv, $why] = self::check($l); $rows[] = [$l, $lv, $why]; }
            if ($rows) $out[] = [$k, (string)$f[$k]['title'], $rows];
        }
        return $out;
    }

    /** Ali je naprava pripravljena (terminal) – vrne razlog ali '' */
    public static function blocker(array $d): string
    {
        if (!(int)($d['term_enabled'] ?? 0)) return A('Pri napravi vklopi terminal (Nastavitve → Terminal) – popravki se uveljavijo prek SSH v Safe Mode.');
        if (Term::userOnRouter($d) === false) return A('Router še nima uporabnika noc-term – počakaj na naslednji push.');
        if (!Term::hasKeys()) return A('Ključi NOC za terminal še niso ustvarjeni.');
        if (!filter_var((string)$d['public_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return A('Naprava nima znanega javnega IP-ja.');
        return '';
    }

    /** Nova uveljavitev; vrne id ali napako */
    public static function start(array $d, array $review, array $keys, bool $warnOk, int $userId): int|string
    {
        if (($b = self::blocker($d)) !== '') return $b;
        $plan = self::plan($review, $keys); if (!$plan) return A('Izbrane ugotovitve nimajo ukazov.');
        $script = []; $used = [];
        foreach ($plan as [$k, $title, $rows]) {
            foreach ($rows as [$l, $lv]) { if ($lv === 'block') return A('Ugotovitev "{t}" vsebuje ukaz, ki ga NOC ne izvede samodejno.', ['t' => $title]); if ($lv === 'warn' && !$warnOk) return A('Potrdi tvegane ukaze (kljukica pod predogledom).'); }
            $script[] = '# ' . preg_replace('/[\r\n]+/', ' ', $title);   // komentar se ne pošlje na router
            foreach ($rows as [$l]) $script[] = $l;
            $used[] = $k;
        }
        $st = db()->prepare("SELECT COUNT(*) FROM ai_applies WHERE device_id=? AND status IN ('queued','running') AND created_at > NOW() - INTERVAL 10 MINUTE"); $st->execute([$d['id']]);
        if ((int)$st->fetchColumn()) return A('Uveljavitev za to napravo že poteka.');
        db()->prepare("INSERT INTO ai_applies (device_id, review_id, user_id, keys_json, script, status, check_token, created_at) VALUES (?,?,?,?,?, 'queued', ?, NOW())")
            ->execute([$d['id'], $review['id'], $userId, json_encode($used), implode("\n", $script), random_token(16)]);
        $id = (int)db()->lastInsertId();
        audit('ai.apply', "#$id {$d['name']} " . implode(',', $used));
        $py = APP_ROOT . '/bin/noc-apply.py';
        exec('HOME=' . escapeshellarg(rtrim((string)cfg('data_dir', '/var/lib/noc'), '/')) . ' nohup python3 ' . escapeshellarg($py) . ' ' . $id . ' > /dev/null 2>&1 &');
        return $id;
    }

    // ------------------------------------------------------------------ za bin/noc-apply.py (CLI)
    public static function info(int $id): array
    {
        $st = db()->prepare("SELECT * FROM ai_applies WHERE id=? AND status='queued'"); $st->execute([$id]); $a = $st->fetch();
        if (!$a) return ['error' => 'ni čakajoče uveljavitve'];
        $q = db()->prepare('SELECT * FROM devices WHERE id=?'); $q->execute([$a['device_id']]); $d = $q->fetch();
        if (!$d || ($b = self::blocker($d)) !== '') { self::finish($id, 'failed', $b ?? 'naprava ne obstaja', ''); return ['error' => $b ?? 'naprava ne obstaja']; }
        db()->prepare("UPDATE ai_applies SET status='running', started_at=NOW() WHERE id=?")->execute([$id]);
        $lines = array_values(array_filter(explode("\n", (string)$a['script']), fn($l) => $l !== '' && $l[0] !== '#'));
        return ['id' => $id, 'host' => (string)$d['public_ip'], 'port' => Term::sshPort($d), 'user' => Term::USER, 'name' => (string)$d['name'],
                'keys' => [Term::keyFile('ed'), Term::keyFile('rsa')], 'known_hosts' => Term::knownHosts(), 'lines' => $lines,
                'check' => rtrim((string)cfg('base_url'), '/') . '/apply-check/' . $a['check_token']];
    }

    /** Router je med Safe Mode dosegel NOC (javna pot /apply-check/<žeton>) */
    public static function checkSeen(string $token): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) return false;
        $st = db()->prepare("UPDATE ai_applies SET check_seen=NOW() WHERE check_token=? AND status='running' AND check_seen IS NULL"); $st->execute([$token]);
        return $st->rowCount() > 0;
    }

    public static function seen(int $id): bool
    {
        $st = db()->prepare('SELECT check_seen IS NOT NULL FROM ai_applies WHERE id=?'); $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }

    /** Konec: status done|reverted|failed, opomba, dnevnik */
    public static function finish(int $id, string $status, string $note, string $log): void
    {
        if (!in_array($status, ['done', 'reverted', 'failed'], true)) $status = 'failed';
        $st = db()->prepare('SELECT a.*, d.name FROM ai_applies a JOIN devices d ON d.id=a.device_id WHERE a.id=?'); $st->execute([$id]); $a = $st->fetch();
        if (!$a || !in_array($a['status'], ['queued', 'running'], true)) return;
        $log = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b[()][0-9A-Za-z]|\x1b[=>78DEHMc]/', '', $log);
        db()->prepare('UPDATE ai_applies SET status=?, note=?, log=?, ended_at=NOW() WHERE id=?')->execute([$status, mb_substr($note, 0, 300), mb_substr((string)$log, 0, 200000), $id]);
        if ($status === 'done') {   // ugotovitve -> urejeno
            $r = db()->prepare('SELECT id, findings FROM ai_reviews WHERE id=?'); $r->execute([$a['review_id']]);
            if ($rv = $r->fetch()) {
                $f = json_decode((string)$rv['findings'], true) ?: [];
                foreach (json_decode((string)$a['keys_json'], true) ?: [] as $k) if (isset($f[$k])) $f[$k]['state'] = 'done';
                db()->prepare('UPDATE ai_reviews SET findings=? WHERE id=?')->execute([json_encode($f, JSON_UNESCAPED_UNICODE), $rv['id']]);
            }
        }
        $ico = ['done' => '✅', 'reverted' => '↩️', 'failed' => '⚠️'][$status];
        Notify::telegram($ico . ' <b>' . htmlspecialchars((string)$a['name']) . '</b> – ' . htmlspecialchars(['done' => A('AI popravki uveljavljeni'), 'reverted' => A('AI popravki niso uveljavljeni – router je vse razveljavil (Safe Mode)'), 'failed' => A('AI popravkov ni bilo mogoče uveljaviti')][$status]) . ($note !== '' ? "\n" . htmlspecialchars($note) : ''));
    }

    public static function get(int $deviceId, int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM ai_applies WHERE id=? AND device_id=?'); $st->execute([$id, $deviceId]);
        return $st->fetch() ?: null;
    }

    public static function recent(int $deviceId, int $limit = 3): array
    {
        $st = db()->prepare('SELECT id, status, note, created_at FROM ai_applies WHERE device_id=? ORDER BY id DESC LIMIT ' . (int)$limit); $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    /** Čiščenje (cron daily): obtičale uveljavitve */
    public static function purge(): string
    {
        $n = db()->exec("UPDATE ai_applies SET status='failed', note='prekinjeno (runner se ni zaključil)', ended_at=NOW() WHERE status IN ('queued','running') AND created_at < NOW() - INTERVAL 1 HOUR");
        return $n ? "ai uveljavitve: $n" : '';
    }
}
