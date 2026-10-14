<?php
declare(strict_types=1);

/**
 * AI pomočnik (Claude, Anthropic Messages API). Samo bere in svetuje – nikoli ne spreminja routerjev ali NOC.
 *  - razlaga alarmov: ob novem opozorilu/kritičnem alarmu analiza z okoliščinami (grafi, log, spremembe)
 *  - pregled sprememb konfiguracije: nočni diff razložen po človeško, tvegane spremembe kot opozorilo
 *  - samonadzor NOC: zavrnjeni pushi, napake cronov, routerji, ki se ne posodobijo
 * Vse teče v cron/ai.php (v ozadju, svoj zaklep) – nikoli med pushom ali na spletni strani.
 */
final class Ai
{
    public const MODELS = ['claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-opus-5-5' => 'Claude Opus 5.5'];
    private const API = 'https://api.anthropic.com/v1/messages';

    public static function key(): string { return dec(setting('ai_key_enc', '')); }
    public static function model(): string { $m = setting('ai_model', 'claude-sonnet-5-5'); return isset(self::MODELS[$m]) ? $m : 'claude-sonnet-5-5'; }
    public static function on(string $feature = ''): bool
    {
        if (self::key() === '') return false;
        return $feature === '' || setting('ai_' . $feature, '1') === '1';
    }

    /** Klici danes (za dnevno omejitev) */
    public static function callsToday(): int
    {
        return (int)db()->query('SELECT COUNT(*) FROM ai_log WHERE at >= CURDATE()')->fetchColumn();
    }

    /** Poraba zadnjih 30 dni: [klicev, vhodnih žetonov, izhodnih žetonov] */
    public static function usage(): array
    {
        $r = db()->query('SELECT COUNT(*) n, COALESCE(SUM(in_tok),0) i, COALESCE(SUM(out_tok),0) o FROM ai_log WHERE at > NOW() - INTERVAL 30 DAY')->fetch();
        return [(int)$r['n'], (int)$r['i'], (int)$r['o']];
    }

    /**
     * En klic modela. Vrne besedilo odgovora ali null (napaka, omejitev). Vsak klic se zapiše v ai_log.
     * $limit=false samo za ročni preizkus v nastavitvah.
     */
    public static function ask(string $kind, ?int $ref, string $system, string $user, int $maxTokens = 700, bool $limit = true, ?string $sig = null): ?string
    {
        $key = self::key(); if ($key === '') return null;
        if ($limit && self::callsToday() >= max(1, (int)setting('ai_daily', '100'))) return null;
        $body = ['model' => self::model(), 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => self::redact($user)]]];
        [$code, $res] = self::post($key, $body);
        $j = json_decode((string)$res, true);
        $text = '';
        foreach ((array)($j['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
        $ok = $code === 200 && $text !== '';
        $err = $ok ? '' : mb_substr('HTTP ' . $code . ' ' . (string)($j['error']['message'] ?? $res ?? 'ni odgovora'), 0, 300);
        db()->prepare('INSERT INTO ai_log (kind, ref_id, sig, model, in_tok, out_tok, ok, err, result) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$kind, $ref, $sig, self::model(), (int)($j['usage']['input_tokens'] ?? 0), (int)($j['usage']['output_tokens'] ?? 0), $ok ? 1 : 0, $err, $ok ? mb_substr($text, 0, 4000) : null]);
        if (!$ok) error_log('noc ai: ' . $err);
        return $ok ? trim($text) : null;
    }

    /** [HTTP koda, telo] – testi lahko podtaknejo odgovor z ai_mock_file v config.php */
    private static function post(string $key, array $body): array
    {
        if ($f = (string)cfg('ai_mock_file', '')) {   // samo za testiranje
            file_put_contents($f . '.req', json_encode($body, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            $mock = is_file($f) ? (string)file_get_contents($f) : 'Testni odgovor.';
            return [200, json_encode(['content' => [['type' => 'text', 'text' => $mock]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]])];
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 60, 'ignore_errors' => true,
            'header' => "x-api-key: $key\r\nanthropic-version: 2023-06-01\r\ncontent-type: application/json\r\n",
            'content' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]]);
        $res = @file_get_contents(self::API, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1];
        return [$code, $res === false ? null : $res];
    }

    /** Pred pošiljanjem odstrani gesla, ključe in skrivnosti (konfiguracija, logi) */
    public static function redact(string $s): string
    {
        // vrednosti nastavitev (tudi v narekovajih in znotraj virov skript, kjer so narekovaji ubežani z \\)
        $val = '(\\\\?"(?:[^"\\\\]|\\\\[^"])*\\\\?"|\S+)';
        $s = preg_replace('/\b((?:password|passwd|secrets?|private-key|preshared-key|pre-shared-key|auth-key|authentication-password|encryption-password|wpa2?-pre-shared-key|wpa-pre-shared-key|passphrase|static-key-\d|value|key|token)=)' . $val . '/i', '$1***', $s);
        $s = preg_replace('/(:(?:local|global) \w+ \\\\?")[^"\\\\]{8,}/', '$1***', $s);      // :local key "...", :local token "..." v skriptah
        $s = preg_replace('/\bbot\d+:[\w-]{30,}/', 'bot***', $s);                   // Telegram bot token
        $s = preg_replace('/(Bearer|Basic) [A-Za-z0-9._~+\/=-]{8,}/', '$1 ***', $s);
        $s = preg_replace('/\b[0-9a-f]{32,}\b/i', '***', $s);                       // API ključi, hashi
        $s = preg_replace('#(?<![A-Za-z0-9+/])[A-Za-z0-9+/]{43}=#', '***', $s);       // WireGuard ključi
        return (string)$s;
    }

    private const SYSTEM = 'Si izkušen omrežni inženir za MikroTik RouterOS 7, ki pomaga serviserju (samostojni podjetnik, vzdržuje routerje pri strankah). '
        . 'Odgovarjaš v slovenščini, kratko in konkretno, brez uvoda in brez markdowna (brez **, #, tabel). Največ 6 kratkih vrstic. '
        . 'Ne ugibaj čez podatke: če podatkov ni dovolj, to povej in predlagaj, kaj preveriti. Ukaze RouterOS piši v enaki obliki, kot se vpišejo v terminal.';

    // ================================================================== 1. razlaga alarmov
    /** Nove alarme (opozorilo/kritično) analizira in pošlje razlago na Telegram. Največ $max na krog. */
    public static function explainAlerts(int $max = 3): string
    {
        if (!self::on('alerts')) return '';
        $rows = db()->query("SELECT a.*, d.name device_name FROM alerts a JOIN devices d ON d.id=a.device_id
                             WHERE a.ai_note IS NULL AND a.severity IN ('warning','critical') AND a.started_at > NOW() - INTERVAL 30 MINUTE
                               AND a.ended_at IS NULL AND d.active=1
                             ORDER BY FIELD(a.severity,'critical','warning'), a.id LIMIT 20")->fetchAll();
        $n = 0;
        foreach ($rows as $a) {
            // isti alarm na isti napravi v zadnjih 24 h že razložen -> ponovi razlago brez novega klica
            $st = db()->prepare("SELECT ai_note FROM alerts WHERE device_id=? AND akey=? AND id<>? AND ai_note IS NOT NULL AND ai_note<>'' AND started_at > NOW() - INTERVAL 24 HOUR ORDER BY id DESC LIMIT 1");
            $st->execute([$a['device_id'], $a['akey'], $a['id']]); $prev = $st->fetchColumn();
            if ($prev !== false) { db()->prepare('UPDATE alerts SET ai_note=? WHERE id=?')->execute([$prev, $a['id']]); continue; }
            if ($n >= $max) break;
            $d = Devices::full((int)$a['device_id']); if (!$d) continue;
            $txt = self::ask('alert', (int)$a['id'], self::SYSTEM, self::alertContext($d, $a));
            $n++;
            db()->prepare('UPDATE alerts SET ai_note=? WHERE id=?')->execute([$txt ?? '', $a['id']]);   // '' = poskus ni uspel, ne ponavljaj
            if ($txt) self::tg('🤖 <b>' . htmlspecialchars($d['name']) . '</b> – ' . htmlspecialchars($a['message']) . "\n\n" . htmlspecialchars(mb_substr($txt, 0, 2500)), (int)$d['id']);
        }
        return $n ? "alarmi: $n" : '';
    }

    /** Okoliščine alarma za model: naprava, alarm, zgodovina, metrike, log, spremembe */
    public static function alertContext(array $d, array $a): string
    {
        $pdo = db(); $id = (int)$d['id']; $st = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        $o = "NAPRAVA: {$d['name']} · model {$d['model']} · RouterOS {$d['os_version']} · WAN {$d['wan_iface']} · uptime " . fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null) . "\n";
        $o .= "ALARM: [{$a['severity']}] {$a['message']} (ključ {$a['akey']}, začetek {$a['started_at']})\n";
        $q = $pdo->prepare('SELECT COUNT(*) FROM alerts WHERE device_id=? AND akey=? AND started_at > NOW() - INTERVAL 30 DAY'); $q->execute([$id, $a['akey']]);
        $o .= 'Isti alarm v zadnjih 30 dneh: ' . (int)$q->fetchColumn() . "x\n";
        $q = $pdo->prepare("SELECT severity, message, started_at FROM alerts WHERE device_id=? AND ended_at IS NULL AND id<>? ORDER BY started_at DESC LIMIT 10"); $q->execute([$id, $a['id']]);
        $open = $q->fetchAll(); if ($open) { $o .= "DRUGI ODPRTI ALARMI:\n"; foreach ($open as $x) $o .= "- [{$x['severity']}] {$x['message']} (od {$x['started_at']})\n"; }
        $q = $pdo->prepare("SELECT started_at, message FROM alerts WHERE device_id=? AND akey IN ('config','reboot') AND started_at > NOW() - INTERVAL 3 DAY ORDER BY started_at DESC LIMIT 5"); $q->execute([$id]);
        foreach ($q->fetchAll() as $x) $o .= "Nedavno: {$x['started_at']} {$x['message']}\n";
        // metrike zadnji 2 h (povzetek po 10 min)
        $q = $pdo->prepare('SELECT DATE_FORMAT(MIN(ts),"%H:%i") t, ROUND(AVG(cpu)) cpu, MAX(cpu) cpu_max, ROUND(AVG(gw_ms),1) gw_ms, MAX(gw_loss) gw_loss, ROUND(AVG(ext_ms),1) ext_ms, MAX(ext_loss) ext_loss, MAX(temp) temp, MAX(conns) conns
                            FROM health_1m WHERE device_id=? AND ts > NOW() - INTERVAL 2 HOUR GROUP BY FLOOR(UNIX_TIMESTAMP(ts)/600) ORDER BY MIN(ts)');
        $q->execute([$id]); $rows = $q->fetchAll();
        if ($rows) { $o .= "METRIKE (10 min): čas cpu% cpu_max gw_ms gw_izguba% ext_ms ext_izguba% temp povezave\n"; foreach ($rows as $r) $o .= implode(' ', array_map(fn($v) => $v ?? '-', $r)) . "\n"; }
        // vmesnik ali SFP iz ključa alarma
        if (preg_match('/^(?:iface|sfp\w*|speed|flap):(.+)$/', $a['akey'], $m)) {
            $q = $pdo->prepare('SELECT name, type, running, rate, rx_error, tx_error, link_downs, last_down FROM device_ifaces WHERE device_id=? AND name=?'); $q->execute([$id, $m[1]]);
            if ($r = $q->fetch()) $o .= 'VMESNIK: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            $q = $pdo->prepare('SELECT DATE(ts) dan, ROUND(AVG(rx),2) rx, ROUND(MIN(rx),2) rx_min, ROUND(AVG(tx),2) tx, ROUND(AVG(temp),1) temp FROM sfp_5m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL 7 DAY GROUP BY DATE(ts) ORDER BY dan');
            $q->execute([$id, $m[1]]); $rows = $q->fetchAll();
            if ($rows) { $o .= "SFP po dnevih (dan rx_avg rx_min tx temp, dBm/°C):\n"; foreach ($rows as $r) $o .= implode(' ', array_map(fn($v) => $v ?? '-', $r)) . "\n"; }
            $q = $pdo->prepare('SELECT vendor, part, speed_class, th_warn, th_crit, wavelength, length_km FROM sfp_state WHERE device_id=? AND iface=?'); $q->execute([$id, $m[1]]);
            if ($r = $q->fetch()) $o .= 'SFP modul: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
        // log routerja (zadnjih 6 h, pomembnejše najprej)
        $q = $pdo->prepare("SELECT DATE_FORMAT(ts,'%d.%m %H:%i:%s') t, topics, message FROM logs WHERE device_id=? AND ts > NOW() - INTERVAL 6 HOUR ORDER BY ts DESC LIMIT 60"); $q->execute([$id]);
        $logs = array_reverse($q->fetchAll());
        if ($logs) { $o .= "LOG ROUTERJA (zadnjih 6 h):\n"; foreach ($logs as $l) $o .= "{$l['t']} {$l['topics']} " . mb_substr($l['message'], 0, 200) . "\n"; }
        if (!empty($st['upd']['latest'])) $o .= "RouterOS posodobitev: nameščen {$st['upd']['inst']}, na voljo {$st['upd']['latest']}\n";
        return mb_substr($o, 0, 14000) . "\nNaloga: kaj je najverjetnejši vzrok tega alarma in kaj naj serviser preveri ali naredi (po vrsti). Če gre verjetno za težavo pri ponudniku ali na strani stranke, to povej.";
    }

    // ================================================================== 2. pregled sprememb konfiguracije
    /** Nove backupe s spremembo primerja s prejšnjim, razloži in tvegane označi kot opozorilo */
    public static function reviewConfigs(int $max = 3): string
    {
        if (!self::on('config')) return '';
        $rows = db()->query('SELECT b.id, b.device_id, b.created_at FROM config_backups b JOIN devices d ON d.id=b.device_id
                             WHERE b.ai_note IS NULL AND b.created_at > NOW() - INTERVAL 2 DAY AND d.active=1 ORDER BY b.id LIMIT ' . (int)$max)->fetchAll();
        $n = 0;
        foreach ($rows as $b) {
            $st = db()->prepare('SELECT content FROM config_backups WHERE device_id=? AND id<? ORDER BY id DESC LIMIT 1'); $st->execute([$b['device_id'], $b['id']]);
            $prev = $st->fetchColumn();
            if ($prev === false) { db()->prepare("UPDATE config_backups SET ai_note='' WHERE id=?")->execute([$b['id']]); continue; }   // prvi backup – ni primerjave
            $st = db()->prepare('SELECT content FROM config_backups WHERE id=?'); $st->execute([$b['id']]); $cur = (string)$st->fetchColumn();
            $d = Devices::full((int)$b['device_id']); if (!$d) continue;
            $diff = '';
            foreach (Diff::ops(explode("\n", Diff::normalize((string)$prev)), explode("\n", Diff::normalize($cur))) as [$op, $line]) if ($op !== ' ') $diff .= $op . ' ' . $line . "\n";
            if (trim($diff) === '') { db()->prepare("UPDATE config_backups SET ai_note='' WHERE id=?")->execute([$b['id']]); continue; }
            $sys = self::SYSTEM . ' Pregleduješ spremembo konfiguracije (vrstice /export terse; "-" odstranjeno, "+" dodano). '
                . 'Pravila z oznako noc-, noc-log, noc-fw, noc-filter in skripte noc-push/noc-backup dodaja nadzorni sistem NOC sam – te so pričakovane in niso tveganje. '
                . 'Odgovori SAMO z JSON: {"risk":"ok" ali "warn","summary":"kaj se je spremenilo, po človeško, največ 4 kratke vrstice","why":"če warn: zakaj je tvegano in kaj predlagaš, sicer prazno"}. '
                . 'warn samo za resnična tveganja: odprt dostop z interneta (winbox, ssh, www, api, dns, proxy, socks), izklopljena ali odstranjena zaščitna pravila požarnega zidu, nov uporabnik z full pravicami, izklopljen NOC (noc- skripte/scheduler odstranjeni), sprememba WAN/IP, ki lahko odreže dostop.';
            $user = "NAPRAVA: {$d['name']} · model {$d['model']} · RouterOS {$d['os_version']} · WAN {$d['wan_iface']}\nSPREMEMBE:\n" . mb_substr($diff, 0, 12000);
            $txt = self::ask('config', (int)$b['id'], $sys, $user, 600);
            $n++;
            if ($txt === null) { db()->prepare("UPDATE config_backups SET ai_note='' WHERE id=?")->execute([$b['id']]); continue; }
            $j = json_decode(preg_replace('/^```(?:json)?|```$/m', '', $txt), true);
            $risk = ($j['risk'] ?? '') === 'warn' ? 'warn' : 'ok';
            $note = mb_substr(trim((string)($j['summary'] ?? $txt)) . (trim((string)($j['why'] ?? '')) !== '' ? "\n" . trim((string)$j['why']) : ''), 0, 2500);
            db()->prepare('UPDATE config_backups SET ai_note=?, ai_risk=? WHERE id=?')->execute([$note, $risk, $b['id']]);
            if ($risk === 'warn') {   // tvegano: alarm gre po običajni poti (Telegram + e-pošta), razlaga je pri alarmu – brez dodatnega sporočila
                $why = trim((string)($j['why'] ?? '')) ?: $note;
                Alerts::event($d, 'cfgrisk', 'warning', mb_substr(A('Tvegana sprememba konfiguracije: {s}', ['s' => (string)strtok($why, "\n")]), 0, 480));
                db()->prepare("UPDATE alerts SET ai_note=? WHERE device_id=? AND akey='cfgrisk' ORDER BY id DESC LIMIT 1")->execute([$note, $d['id']]);
                continue;
            }
            self::tg('🤖 <b>' . htmlspecialchars($d['name']) . '</b> – ' . A('sprememba konfiguracije') . "\n\n" . htmlspecialchars($note)
                . "\n<a href=\"" . rtrim((string)cfg('base_url'), '/') . '/devices/' . $d['id'] . '/backups/' . $b['id'] . '">' . A('Poglej razlike') . '</a>', (int)$d['id']);
        }
        return $n ? "konfiguracije: $n" : '';
    }

    // ================================================================== 3. samonadzor NOC
    /** Zazna težave NOC samega (pravila) in jih da AI v diagnozo; ista težava največ enkrat na 12 h */
    public static function watch(): string
    {
        if (!self::on('watch')) return '';
        $found = [];
        // zavrnjeni pushi: shranjen pokvarjen push v zadnjih 30 min
        $dir = rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/push';
        foreach (glob($dir . '/*.bad.json') ?: [] as $f) {
            if (filemtime($f) < time() - 1800) continue;
            $id = (int)basename($f); $raw = (string)file_get_contents($f);
            json_decode($raw); $err = json_last_error_msg();
            $pos = self::jsonErrorPos($raw);
            $d = Devices::full($id);
            $found['badpush:' . $id] = 'NOC zavrača push naprave ' . ($d['name'] ?? "#$id") . " kot neveljaven JSON ($err). Velikost " . strlen($raw) . " B.\n"
                . "Okolica mesta napake (znak $pos): " . mb_substr(substr($raw, max(0, $pos - 400), 600), 0, 600) . "\n"
                . 'Push sestavi skripta na routerju z RouterOS :serialize to=json.';
        }
        // cron opravila, ki zamujajo ali javljajo napako
        $limits = ['minute' => 5, 'flows' => 20, 'daily' => 1560, 'fwlog' => 10];
        foreach (db()->query('SELECT * FROM cron_runs')->fetchAll() as $c) {
            $age = (time() - strtotime($c['last_at'])) / 60;
            if (isset($limits[$c['name']]) && $age > $limits[$c['name']] && !($c['name'] === 'fwlog' && !db()->query('SELECT COUNT(*) FROM devices WHERE active=1 AND fwlog_enabled=1')->fetchColumn()))
                $found['cron:' . $c['name']] = "Cron opravilo '{$c['name']}' se ni izvedlo že " . (int)$age . " min (pričakovano vsaj vsakih {$limits[$c['name']]} min). Zadnje sporočilo: {$c['info']}";
            if (stripos((string)$c['info'], 'napaka') !== false) $found['cronerr:' . $c['name']] = "Cron opravilo '{$c['name']}' javlja napako: {$c['info']}";
        }
        // routerji, ki se že več kot 2 h ne posodobijo (zanka posodabljanja)
        foreach (db()->query('SELECT id, name, script_ver, upd_attempt_at, os_version FROM devices WHERE active=1 AND auto_update=1 AND upd_attempt_at IS NOT NULL AND last_seen_at > NOW() - INTERVAL 10 MINUTE')->fetchAll() as $d) {
            $full = Devices::full((int)$d['id']); if (!$full) continue;
            $exp = MikrotikScript::expectedVersion($full);
            if ($d['script_ver'] === $exp) continue;
            $q = db()->prepare("SELECT COUNT(*) FROM audit WHERE action='device.auto-update' AND detail LIKE ? AND at > NOW() - INTERVAL 3 HOUR"); $q->execute([$d['name'] . ' %']);
            if ((int)$q->fetchColumn() < 3) continue;
            $q = db()->prepare("SELECT DATE_FORMAT(ts,'%H:%i:%s') t, message FROM logs WHERE device_id=? AND message LIKE 'noc:%' AND ts > NOW() - INTERVAL 3 HOUR ORDER BY ts DESC LIMIT 15"); $q->execute([$d['id']]);
            $found['updloop:' . $d['id']] = "Router {$d['name']} (RouterOS {$d['os_version']}) se v 3 h vsaj 3x poskusil samodejno posodobiti, a še vedno javlja staro verzijo skripte ({$d['script_ver']}, pričakovana $exp).\n"
                . "Sporočila noc: iz loga routerja:\n" . implode("\n", array_map(fn($r) => $r['t'] . ' ' . $r['message'], array_reverse($q->fetchAll())));
        }
        $n = 0;
        foreach ($found as $sig => $desc) {
            $q = db()->prepare("SELECT COUNT(*) FROM ai_log WHERE kind='watch' AND sig=? AND at > NOW() - INTERVAL 12 HOUR"); $q->execute([$sig]);
            if ((int)$q->fetchColumn()) continue;
            if ($n >= 2) break;
            $sys = self::SYSTEM . ' Tokrat ne gre za router, ampak za sam nadzorni sistem NOC (PHP 8 + MariaDB na Linux strežniku; routerji vsako minuto pošljejo JSON z RouterOS skripto, cron opravila obdelujejo podatke). '
                . 'Na podlagi podatkov povej najverjetnejši vzrok in kaj naj skrbnik naredi. Če je verjetno napaka v kodi NOC, to jasno povej in opiši, kaj bi bilo treba popraviti.';
            $txt = self::ask('watch', null, $sys, "TEŽAVA:\n" . $desc, 700, true, $sig);
            $n++;
            if ($txt) self::tg('🤖 <b>' . A('NOC samonadzor') . '</b>' . "\n" . htmlspecialchars(mb_substr((string)strtok($desc, "\n"), 0, 300)) . "\n\n" . htmlspecialchars(mb_substr($txt, 0, 2500)));
        }
        return $n ? "samonadzor: $n" : '';
    }

    /** Položaj napake v neveljavnem JSON (za okolico v diagnozi) */
    private static function jsonErrorPos(string $raw): int
    {
        // najpogostejša vzroka: inf/nan iz :serialize in kontrolni znaki; sicer začetek
        if (preg_match('/[:\[,]\s*(-?inf|nan)\b/', $raw, $m, PREG_OFFSET_CAPTURE)) return (int)$m[1][1];
        if (preg_match('/[\x00-\x1f]/', $raw, $m, PREG_OFFSET_CAPTURE)) return (int)$m[0][1];
        return 0;
    }

    /** Telegram (če je nastavljen in naprava ni utišana) – AI sporočila gredo mimo čakalne vrste, ker so že v cronu */
    private static function tg(string $html, ?int $deviceId = null): void
    {
        if (setting('ai_tg', '1') !== '1') return;
        if ($deviceId) { $q = db()->prepare('SELECT mute_until FROM devices WHERE id=?'); $q->execute([$deviceId]); $mu = $q->fetchColumn(); if ($mu && strtotime($mu) > time()) return; }
        Notify::telegram($html);   // besedila so skrajšana že pred ubežanjem (rez v entiteti bi Telegram zavrnil)
    }
}
