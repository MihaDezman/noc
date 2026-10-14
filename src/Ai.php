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
        // pogovor v Telegramu je ena vrstica, a več klicev API (ref_id)
        return (int)db()->query("SELECT COALESCE(SUM(IF(kind='chat', GREATEST(1, COALESCE(ref_id, 1)), 1)), 0) FROM ai_log WHERE at >= CURDATE()")->fetchColumn();
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
        // modeli 5.5 imajo razmišljanje vklopljeno privzeto; žetoni razmišljanja štejejo v max_tokens, zato dodamo prostor
        // in nastavimo nižji napor (krajše razmišljanje = hitreje in ceneje; za kratke diagnoze zadošča)
        $body = ['model' => self::model(), 'max_tokens' => $maxTokens + 4000, 'output_config' => ['effort' => $kind === 'test' ? 'low' : 'medium'],
                 'system' => $system, 'messages' => [['role' => 'user', 'content' => self::redact($user)]]];
        [$code, $res] = self::post($key, $body);
        $j = json_decode((string)$res, true);
        $text = '';
        foreach ((array)($j['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
        $ok = $code === 200 && $text !== '';
        $err = $ok ? '' : mb_substr('HTTP ' . $code . ' ' . (string)($j['error']['message'] ?? ($code === 200 ? 'odgovor brez besedila (stop_reason: ' . ($j['stop_reason'] ?? '?') . ')' : ($res ?? 'ni odgovora'))), 0, 300);
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
            if (str_starts_with($mock, '[')) {   // zaporedje odgovorov (preizkus orodij): vsak klic vzame naslednjega
                $seq = json_decode($mock, true); $n = (int)@file_get_contents($f . '.n'); file_put_contents($f . '.n', (string)($n + 1));
                $c = $seq[min($n, count($seq) - 1)];
                return [200, json_encode(['content' => $c, 'stop_reason' => array_filter($c, fn($x) => $x['type'] === 'tool_use') ? 'tool_use' : 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]])];
            }
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
            // napad z zunanjega IP-ja: AI sme predlagati blokado (izvede se šele, ko jo skrbnik potrdi)
            $attIp = preg_match('/^(?:attack|fwscan):(\d{1,3}(?:\.\d{1,3}){3})$/', $a['akey'], $mm) ? $mm[1] : null;
            $sys = self::SYSTEM . ($attIp ? " Če je smiselno blokirati napadalca $attIp na routerju, dodaj v zadnjo vrstico natanko: UKREP: blokiraj $attIp <ure> (1–720). Blokado potrdi serviser z gumbom." : '');
            $txt = self::ask('alert', (int)$a['id'], $sys, self::alertContext($d, $a));
            $n++;
            $prop = null;
            if ($txt && $attIp && preg_match('/^\s*UKREP:\s*blokiraj\s+(\S+)\s+(\d+)/mi', $txt, $um)) {
                $txt = trim(preg_replace('/^\s*UKREP:.*$/mi', '', $txt));
                if ($um[1] === $attIp) { $r = Actions::create($d, 'block', $attIp, (int)$um[2], 'proposed', mb_substr($a['message'], 0, 250), 'ai', (int)$a['id']); if (is_int($r)) $prop = [$r, (int)$um[2]]; }
            }
            db()->prepare('UPDATE alerts SET ai_note=? WHERE id=?')->execute([$txt ?? '', $a['id']]);   // '' = poskus ni uspel, ne ponavljaj
            if ($txt) self::tg('🤖 <b>' . htmlspecialchars($d['name']) . '</b> – ' . htmlspecialchars($a['message']) . "\n\n" . htmlspecialchars(mb_substr($txt, 0, 2500))
                . ($prop ? "\n\n🚫 " . A('Predlog: blokiraj {ip} za {h} h', ['ip' => $attIp, 'h' => $prop[1]]) : ''), (int)$d['id'], $prop ? Actions::buttons($prop[0], $attIp) : null);
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
            // samo spremembe stranke: skripte, urnike in pravila NOC (samodejna posodobitev) izpustimo
            foreach (Diff::ownChanges(Diff::normalize((string)$prev), Diff::normalize($cur)) as [$op, $line]) $diff .= $op . ' ' . $line . "\n";
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

    // ================================================================== 4. jutranji povzetek
    /** Enkrat na dan ob nastavljeni uri: kaj se je dogajalo, kaj se počasi slabša, kaj pogledati */
    public static function digest(bool $force = false): string
    {
        if (!self::on('digest') && !$force) return '';
        $hour = (int)setting('ai_digest_hour', '7');
        if (!$force && ((int)date('G') !== $hour || setting('ai_digest_last') === date('Y-m-d'))) return '';
        if (!$force) setting_set('ai_digest_last', date('Y-m-d'));   // tudi ob napaki: največ en poskus na dan (ročni /povzetek ne šteje)
        $sys = self::SYSTEM . ' Tokrat pišeš jutranji povzetek za vse naprave. Brez omejitve 6 vrstic, a največ 18 kratkih vrstic. Oblika (navadno besedilo, vrstice z "• "):'
            . ' "Ponoči:" (pomembni dogodki zadnjih 24 h – izpadi, ponovni zagoni, spremembe, napadi; samo bistveno, združi podobno),'
            . ' "Slabša se:" (počasni trendi: disk, pomnilnik, temperatura, latenca, izgube, SFP signal, naraščanje napadov – samo kjer podatki res kažejo trend),'
            . ' "Ta teden poglej:" (največ 3 konkretna opravila, npr. posodobitev RouterOS, čiščenje diska).'
            . ' Če je vse mirno, to povej v eni vrstici. Naprave imenuj z imenom.';
        $txt = self::ask('digest', null, $sys, self::digestContext(), 1200);
        if ($txt) {
            $html = ($force ? '📋 <b>' . A('Povzetek NOC') : '🌅 <b>' . A('Jutranji povzetek NOC')) . '</b> · ' . date('d.m.Y H:i') . "\n\n" . htmlspecialchars(mb_substr($txt, 0, 3000));
            $force ? Notify::telegram($html) : self::tg($html);   // ročno zahtevan gre vedno na Telegram
            setting_set('ai_digest_text', date('d.m.Y H:i') . "\n" . mb_substr($txt, 0, 4000));
        }
        return $txt ? 'povzetek' : 'povzetek: napaka';
    }

    /** Podatki za povzetek: po napravi stanje, dogodki 24 h in primerjava zadnjega dne s tednom */
    public static function digestContext(): string
    {
        $pdo = db(); $o = 'Čas: ' . date('d.m.Y H:i') . "\n";
        foreach (array_map([Devices::class, 'decorate'], $pdo->query('SELECT ' . Devices::listCols('d') . ', t.name tenant_name FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1 ORDER BY d.name')->fetchAll()) as $d) {
            $id = (int)$d['id']; $st = $d['status'];
            $o .= "\n## {$d['name']}" . ($d['tenant_name'] ? " ({$d['tenant_name']})" : '') . " · {$d['model']} · RouterOS " . preg_replace('/\s.*$/', '', (string)$d['os_version']) . ' · stanje ' . $d['state'] . ' · uptime ' . fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null) . "\n";
            if (!empty($st['upd']['latest']) && version_compare((string)$st['upd']['latest'], (string)($st['upd']['inst'] ?? ''), '>')) $o .= "Na voljo RouterOS {$st['upd']['latest']}\n";
            $q = $pdo->prepare("SELECT severity, message, started_at, ended_at FROM alerts WHERE device_id=? AND (started_at > NOW() - INTERVAL 24 HOUR OR ended_at IS NULL) AND severity<>'info' ORDER BY started_at DESC LIMIT 12"); $q->execute([$id]);
            foreach ($q->fetchAll() as $a) $o .= '- [' . $a['severity'] . '] ' . $a['message'] . ' (' . substr($a['started_at'], 5, 11) . ($a['ended_at'] ? ', zaprt ' . substr($a['ended_at'], 11, 5) : ', ODPRT') . ")\n";
            $q = $pdo->prepare("SELECT akey, COUNT(*) n FROM alerts WHERE device_id=? AND started_at > NOW() - INTERVAL 24 HOUR AND severity='info' GROUP BY SUBSTRING_INDEX(akey, ':', 1) ORDER BY n DESC LIMIT 6"); $q->execute([$id]);
            $info = $q->fetchAll(); if ($info) $o .= 'Info dogodki 24 h: ' . implode(', ', array_map(fn($r) => explode(':', $r['akey'])[0] . ' ' . $r['n'] . 'x', $info)) . "\n";
            // trendi: zadnji dan proti prejšnjim 6 dnem (5-min povprečja)
            $q = $pdo->prepare('SELECT ROUND(AVG(IF(ts > NOW() - INTERVAL 1 DAY, cpu_avg, NULL))) cpu_d, ROUND(AVG(IF(ts <= NOW() - INTERVAL 1 DAY, cpu_avg, NULL))) cpu_t,
                                       ROUND(AVG(IF(ts > NOW() - INTERVAL 1 DAY, mem_pct, NULL))) mem_d, ROUND(AVG(IF(ts <= NOW() - INTERVAL 1 DAY, mem_pct, NULL))) mem_t,
                                       MAX(IF(ts > NOW() - INTERVAL 1 DAY, temp_max, NULL)) temp_d, MAX(IF(ts <= NOW() - INTERVAL 1 DAY, temp_max, NULL)) temp_t,
                                       ROUND(AVG(IF(ts > NOW() - INTERVAL 1 DAY, gw_ms, NULL)),1) gw_d, ROUND(AVG(IF(ts <= NOW() - INTERVAL 1 DAY, gw_ms, NULL)),1) gw_t,
                                       ROUND(AVG(IF(ts > NOW() - INTERVAL 1 DAY, ext_loss, NULL)),1) loss_d, ROUND(AVG(IF(ts <= NOW() - INTERVAL 1 DAY, ext_loss, NULL)),1) loss_t
                                FROM health_5m WHERE device_id=? AND ts > NOW() - INTERVAL 7 DAY'); $q->execute([$id]);
            $t = $q->fetch();
            if ($t) {   // samo meritve, ki obstajajo (temperatura -99 = router nima senzorja)
                $parts = [];
                foreach (['CPU' => ['cpu_d', 'cpu_t', '%'], 'pomnilnik' => ['mem_d', 'mem_t', '%'], 'temp max' => ['temp_d', 'temp_t', '°C'], 'latenca prehod' => ['gw_d', 'gw_t', 'ms'], 'izguba internet' => ['loss_d', 'loss_t', '%']] as $lbl => [$a1, $a2, $u]) {
                    $v1 = $t[$a1]; $v2 = $t[$a2];
                    if ($lbl === 'temp max') { $v1 = $v1 !== null && (float)$v1 > -90 ? $v1 : null; $v2 = $v2 !== null && (float)$v2 > -90 ? $v2 : null; }
                    if ($v1 !== null) $parts[] = "$lbl $v1" . ($v2 !== null ? "/$v2" : '/–') . " $u";
                }
                if ($parts) $o .= 'Zadnji dan / prejšnji teden: ' . implode(', ', $parts) . "\n";
            }
            $q = $pdo->prepare('SELECT MAX(IF(ts > NOW() - INTERVAL 10 MINUTE, hdd_pct, NULL)) now_, MAX(IF(ts < NOW() - INTERVAL 40 HOUR, hdd_pct, NULL)) before_ FROM health_1m WHERE device_id=? AND ts > NOW() - INTERVAL 48 HOUR'); $q->execute([$id]);
            $hd = $q->fetch(); if ($hd && $hd['now_'] !== null) $o .= "Disk zaseden {$hd['now_']} % (pred 2 dnevoma " . ($hd['before_'] ?? '?') . " %)" . (Alerts::isOff($d, 'hdd') ? ' – alarm za disk je pri tej napravi namenoma izklopljen' : '') . "\n";
            $q = $pdo->prepare('SELECT iface, ROUND(AVG(IF(ts > NOW() - INTERVAL 1 DAY, rx, NULL)),2) d, ROUND(AVG(IF(ts <= NOW() - INTERVAL 1 DAY, rx, NULL)),2) t FROM sfp_5m WHERE device_id=? AND ts > NOW() - INTERVAL 7 DAY AND rx IS NOT NULL GROUP BY iface'); $q->execute([$id]);
            foreach ($q->fetchAll() as $r) if ($r['d'] !== null && $r['t'] !== null) $o .= "SFP {$r['iface']} RX zadnji dan {$r['d']} dBm, prejšnji teden {$r['t']} dBm\n";
            $q = $pdo->prepare("SELECT SUM(IF(day = CURDATE() - INTERVAL 1 DAY, hits, 0)) y, ROUND(AVG(IF(day < CURDATE() - INTERVAL 1 DAY, hits, NULL))) w FROM fw_daily WHERE device_id=? AND kind='in' AND day > CURDATE() - INTERVAL 8 DAY"); $q->execute([$id]);
            $fw = $q->fetch(); if ($fw && $fw['y'] !== null && (int)$fw['y'] > 0) $o .= "Zavrnjeni poskusi z interneta včeraj {$fw['y']} (povprečje tedna " . ($fw['w'] ?? '?') . ")\n";
            $q = $pdo->prepare('SELECT MAX(created_at) FROM config_backups WHERE device_id=?'); $q->execute([$id]); $bk = $q->fetchColumn();
            $o .= 'Zadnji backup: ' . ($bk ?: 'nikoli') . "\n";
        }
        $q = $pdo->query("SELECT name, info, last_at FROM cron_runs"); $o .= "\nNOC strežnik – cron: " . implode('; ', array_map(fn($r) => "{$r['name']} {$r['last_at']}", $q->fetchAll())) . "\n";
        return mb_substr($o, 0, 30000);
    }

    // ================================================================== 5. asistent v Telegramu
    /** Orodja (samo branje + predlog blokade), ki jih model lahko pokliče */
    private static function tools(): array
    {
        $dev = ['type' => 'string', 'description' => 'Ime naprave ali del imena (ali naročnika)'];
        return [
            ['name' => 'naprave', 'description' => 'Seznam vseh naprav s trenutnim stanjem (stanje, CPU, pomnilnik, disk, WAN promet zdaj, odprti alarmi).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'naprava', 'description' => 'Podrobnosti naprave: stanje, odprti alarmi, metrike zadnjih 24 h po urah, WAN promet danes/ta mesec, vmesniki z napakami, SFP.', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev], 'required' => ['naprava']]],
            ['name' => 'alarmi', 'description' => 'Zgodovina alarmov in dogodkov (lahko za eno napravo).', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev, 'ure' => ['type' => 'integer', 'description' => 'Koliko ur nazaj (privzeto 24, največ 720)']]]],
            ['name' => 'najvec_prometa', 'description' => 'Naprave v LAN-u z največ prometa (traffic-flow), z imeni iz DHCP.', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev, 'obdobje' => ['type' => 'string', 'enum' => ['1h', '24h', '7d', '30d']]], 'required' => ['naprava']]],
            ['name' => 'log', 'description' => 'Log routerja (zadnje vrstice, po želji filtrirane).', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev, 'ure' => ['type' => 'integer'], 'filter' => ['type' => 'string', 'description' => 'Besedilo, ki mora biti v vrstici (npr. "login failure", "ether1")']], 'required' => ['naprava']]],
            ['name' => 'varnost', 'description' => 'Varnostna analiza: zavrnjeni poskusi z interneta po dnevih, najpogostejši napadalci in porti, sumljiv odhodni promet, blokade.', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev], 'required' => ['naprava']]],
            ['name' => 'predlagaj_blokado', 'description' => 'Predlagaj blokado zunanjega IP-ja na routerju. NE izvede se: serviser dobi gumb Izvedi/Zavrni. Uporabi samo, ko serviser to želi ali je napad očiten.', 'input_schema' => ['type' => 'object', 'properties' => ['naprava' => $dev, 'ip' => ['type' => 'string'], 'ure' => ['type' => 'integer'], 'razlog' => ['type' => 'string']], 'required' => ['naprava', 'ip', 'ure', 'razlog']]],
        ];
    }

    /** Naprava po delu imena; vrne [naprava|null, napaka] */
    private static function findDevice(string $q): array
    {
        $q = mb_strtolower(trim($q));
        $all = array_map([Devices::class, 'decorate'], db()->query('SELECT ' . Devices::listCols('d') . ', t.name tenant_name FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1')->fetchAll());
        $hit = array_values(array_filter($all, fn($d) => mb_strtolower($d['name']) === $q));
        if (!$hit && $q !== '') $hit = array_values(array_filter($all, fn($d) => str_contains(mb_strtolower($d['name'] . ' ' . ($d['tenant_name'] ?? '') . ' ' . $d['site']), $q)));
        if (count($hit) === 1) return [$hit[0], ''];
        return [null, $hit ? 'Več zadetkov: ' . implode(', ', array_column($hit, 'name')) : 'Ni naprave "' . $q . '". Naprave: ' . implode(', ', array_column($all, 'name'))];
    }

    /** Izvede orodje in vrne rezultat kot besedilo (JSON) */
    private static function runTool(string $name, array $in, array &$proposals): string
    {
        $pdo = db();
        if ($name === 'naprave') {
            $out = [];
            foreach (array_map([Devices::class, 'decorate'], $pdo->query('SELECT ' . Devices::listCols('d') . ', t.name tenant_name, (SELECT COUNT(*) FROM alerts a WHERE a.device_id=d.id AND a.ended_at IS NULL) open_alerts FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1 ORDER BY d.name')->fetchAll()) as $d) {
                $h = $d['status']['h'] ?? [];
                $w = $pdo->prepare('SELECT rx_bps, tx_bps FROM device_ifaces WHERE device_id=? AND name=?'); $w->execute([$d['id'], $d['wan_iface']]); $w = $w->fetch();
                $out[] = ['ime' => $d['name'], 'narocnik' => $d['tenant_name'], 'stanje' => $d['state'], 'zadnji_push' => $d['last_seen_at'], 'cpu' => $h['cpu'] ?? null, 'pomnilnik' => $h['mem_pct'] ?? null, 'disk' => $h['hdd_pct'] ?? null,
                          'wan_dol' => $w ? fmt_bps($w['rx_bps']) : null, 'wan_gor' => $w ? fmt_bps($w['tx_bps']) : null, 'odprti_alarmi' => (int)$d['open_alerts'], 'model' => $d['model'], 'routeros' => $d['os_version']];
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        [$d, $err] = self::findDevice((string)($in['naprava'] ?? ''));
        if ($name === 'alarmi' && trim((string)($in['naprava'] ?? '')) === '') $d = null; elseif (!$d) return $err;
        $id = $d ? (int)$d['id'] : 0;
        switch ($name) {
            case 'naprava':
                $full = Devices::full($id);
                $ctx = self::alertContext($full, ['severity' => '-', 'message' => '(splošno vprašanje, ni alarma)', 'akey' => '-', 'started_at' => date('Y-m-d H:i:s'), 'id' => 0]);
                $q = $pdo->prepare('SELECT DATE_FORMAT(MIN(ts),"%d.%m %H:00") ura, ROUND(AVG(cpu_avg)) cpu, MAX(mem_pct) mem, ROUND(AVG(gw_ms),1) gw_ms, MAX(gw_loss) gw_izg, ROUND(AVG(ext_ms),1) ext_ms, MAX(ext_loss) ext_izg FROM health_5m WHERE device_id=? AND ts > NOW() - INTERVAL 24 HOUR GROUP BY DATE_FORMAT(ts,"%Y%m%d%H") ORDER BY MIN(ts)'); $q->execute([$id]);
                $vol = $d['wan_iface'] ? Metrics::volume($id, $d['wan_iface']) : null;
                $q2 = $pdo->prepare('SELECT name, type, running, rate, rx_error, tx_error, link_downs, last_down FROM device_ifaces WHERE device_id=? AND (rx_error>0 OR tx_error>0 OR link_downs>0)'); $q2->execute([$id]);
                return mb_substr($ctx, 0, 8000) . "\nPO URAH: " . json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE)
                    . "\nWAN PROMET: " . ($vol ? 'danes ' . fmt_bytes($vol['today']['rx'] + $vol['today']['tx']) . ', ta mesec ' . fmt_bytes($vol['month']['rx'] + $vol['month']['tx']) : '-')
                    . "\nVMESNIKI Z NAPAKAMI: " . json_encode($q2->fetchAll(), JSON_UNESCAPED_UNICODE);
            case 'alarmi':
                $h = max(1, min(720, (int)($in['ure'] ?? 24)));
                $q = $pdo->prepare('SELECT d.name naprava, a.severity, a.message, a.started_at, a.ended_at FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.started_at > NOW() - INTERVAL ? HOUR' . ($d ? ' AND a.device_id=?' : '') . ' ORDER BY a.started_at DESC LIMIT 80');
                $q->execute($d ? [$h, $id] : [$h]);
                return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
            case 'najvec_prometa':
                $r = in_array($in['obdobje'] ?? '', ['1h', '24h', '7d', '30d'], true) ? $in['obdobje'] : '24h';
                return json_encode(array_map(fn($x) => ['naprava' => ($x['label'] ?? '') ?: (($x['hostname'] ?? '') ?: $x['ip']), 'ip' => $x['ip'], 'mac' => $x['mac'] ?? '', 'gor' => fmt_bytes($x['up']), 'dol' => fmt_bytes($x['down'])], Flows::top($id, $r, 15)), JSON_UNESCAPED_UNICODE);
            case 'log':
                $h = max(1, min(168, (int)($in['ure'] ?? 24))); $f = trim((string)($in['filter'] ?? ''));
                $q = $pdo->prepare("SELECT DATE_FORMAT(ts,'%d.%m %H:%i:%s') t, topics, message FROM logs WHERE device_id=? AND ts > NOW() - INTERVAL ? HOUR" . ($f !== '' ? ' AND message LIKE ?' : '') . ' ORDER BY ts DESC LIMIT 80');
                $q->execute($f !== '' ? [$id, $h, '%' . $f . '%'] : [$id, $h]);
                return implode("\n", array_map(fn($l) => "{$l['t']} {$l['topics']} " . mb_substr($l['message'], 0, 200), array_reverse($q->fetchAll()))) ?: 'ni vrstic';
            case 'varnost':
                $q = $pdo->prepare('SELECT day, kind, hits, sources, ports FROM fw_daily WHERE device_id=? AND day > CURDATE() - INTERVAL 8 DAY ORDER BY day'); $q->execute([$id]);
                $q2 = $pdo->prepare("SELECT src, SUM(hits) n, COUNT(DISTINCT port) porti FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 24 HOUR GROUP BY src ORDER BY n DESC LIMIT 10"); $q2->execute([$id]);
                $q3 = $pdo->prepare("SELECT port, proto, SUM(hits) n FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 24 HOUR GROUP BY port, proto ORDER BY n DESC LIMIT 10"); $q3->execute([$id]);
                $q4 = $pdo->prepare("SELECT message, started_at FROM alerts WHERE device_id=? AND (akey LIKE 'out%' OR akey LIKE 'attack:%' OR akey='fwspike') AND started_at > NOW() - INTERVAL 7 DAY ORDER BY started_at DESC LIMIT 15"); $q4->execute([$id]);
                return json_encode(['po_dnevih' => $q->fetchAll(), 'napadalci_24h' => $q2->fetchAll(), 'porti_24h' => $q3->fetchAll(), 'dogodki_7d' => $q4->fetchAll(),
                    'blokade' => array_map(fn($a) => ['ip' => $a['ip'], 'status' => $a['status'], 'ure' => $a['hours'], 'cas' => $a['created_at']], Actions::forDevice($id, 10)),
                    'analiza_vklopljena' => (bool)(int)$d['fwlog_enabled']], JSON_UNESCAPED_UNICODE);
            case 'predlagaj_blokado':
                $full = Devices::full($id);
                $r = Actions::create($full, 'block', (string)($in['ip'] ?? ''), (int)($in['ure'] ?? 24), 'proposed', (string)($in['razlog'] ?? ''), 'ai');
                if (!is_int($r)) return 'Zavrnjeno: ' . $r;
                $proposals[] = [$r, $d['name'], (string)$in['ip'], max(1, min(720, (int)($in['ure'] ?? 24)))];
                return "Predlog #$r ustvarjen. Serviser ga mora potrditi z gumbom Izvedi pod tvojim odgovorom.";
        }
        return 'Neznano orodje';
    }

    /** Odgovor na vprašanje iz Telegrama (z orodji); vrne [besedilo, predlogi ukrepov] */
    public static function chat(string $question): array
    {
        if (!self::on('chat')) return [A('AI asistent je izklopljen (Nastavitve → AI pomočnik).'), []];
        if (self::callsToday() >= max(1, (int)setting('ai_daily', '100'))) return [A('Dosežena je dnevna omejitev klicev AI.'), []];
        $sys = self::SYSTEM . ' Si asistent v Telegramu nadzornega sistema NOC. Na vprašanja odgovarjaš s pomočjo orodij (podatki NOC, samo branje). '
            . 'Vedno najprej poišči podatke z orodji, nato odgovori kratko s konkretnimi številkami. Brez omejitve 6 vrstic, a največ 15 kratkih vrstic. '
            . 'Na routerjih ne moreš ničesar spremeniti; edino, kar lahko narediš, je predlog blokade IP-ja, ki ga serviser potrdi z gumbom.';
        // kratek spomin: zadnja vprašanja in odgovori iz zadnjih 30 minut
        $msgs = [];
        foreach (array_reverse(db()->query("SELECT prompt, result FROM ai_log WHERE kind='chat' AND ok=1 AND at > NOW() - INTERVAL 30 MINUTE ORDER BY id DESC LIMIT 3")->fetchAll()) as $h) {
            $msgs[] = ['role' => 'user', 'content' => (string)$h['prompt']]; $msgs[] = ['role' => 'assistant', 'content' => (string)$h['result']];
        }
        $msgs[] = ['role' => 'user', 'content' => mb_substr($question, 0, 2000)];
        $proposals = []; $tin = 0; $tout = 0; $text = ''; $err = ''; $calls_n = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($i > 0 && self::callsToday() + $i >= max(1, (int)setting('ai_daily', '100'))) { $err = 'dnevna omejitev'; break; }
            [$code, $res] = self::post(self::key(), ['model' => self::model(), 'max_tokens' => 6000, 'output_config' => ['effort' => 'medium'], 'system' => $sys, 'tools' => self::tools(), 'messages' => $msgs]);
            $j = json_decode((string)$res, true);
            $tin += (int)($j['usage']['input_tokens'] ?? 0); $tout += (int)($j['usage']['output_tokens'] ?? 0);
            if ($code !== 200 || !is_array($j['content'] ?? null)) { $err = 'HTTP ' . $code . ' ' . (string)($j['error']['message'] ?? 'ni odgovora'); break; }
            // vrnemo nespremenjeno (tudi bloke razmišljanja); prazen vhod orodja mora ostati JSON objekt {}, ne []
            $msgs[] = ['role' => 'assistant', 'content' => array_map(fn($c) => ($c['type'] ?? '') === 'tool_use' && ($c['input'] ?? []) === [] ? ['input' => new \stdClass()] + $c : $c, $j['content'])];
            $calls_n = ($calls_n ?? 0) + 1;
            $calls = array_values(array_filter($j['content'], fn($c) => ($c['type'] ?? '') === 'tool_use'));
            $text = trim(implode('', array_map(fn($c) => ($c['type'] ?? '') === 'text' ? $c['text'] : '', $j['content'])));
            if (!$calls || ($j['stop_reason'] ?? '') !== 'tool_use') break;
            $results = [];
            foreach ($calls as $c) {
                try { $r = self::runTool((string)$c['name'], (array)($c['input'] ?? []), $proposals); } catch (\Throwable $e) { $r = 'Napaka orodja: ' . $e->getMessage(); }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $c['id'], 'content' => self::redact(mb_substr($r, 0, 12000))];
            }
            $msgs[] = ['role' => 'user', 'content' => $results];
        }
        $ok = $text !== '' && $err === '';
        db()->prepare('INSERT INTO ai_log (kind, ref_id, model, in_tok, out_tok, ok, err, result, prompt) VALUES (?,?,?,?,?,?,?,?,?)')   // ref_id = število klicev API
            ->execute(['chat', max(1, $calls_n ?? 1), self::model(), $tin, $tout, $ok ? 1 : 0, mb_substr($err, 0, 300), $ok ? mb_substr($text, 0, 4000) : null, mb_substr($question, 0, 2000)]);
        if (!$ok) error_log('noc ai chat: ' . $err);
        return [$ok ? $text : A('AI ni odgovoril ({e}).', ['e' => $err ?: A('prazen odgovor')]), $proposals];
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
    private static function tg(string $html, ?int $deviceId = null, ?array $keyboard = null): void
    {
        if (setting('ai_tg', '1') !== '1') return;
        if ($deviceId) { $q = db()->prepare('SELECT mute_until FROM devices WHERE id=?'); $q->execute([$deviceId]); $mu = $q->fetchColumn(); if ($mu && strtotime($mu) > time()) return; }
        Notify::telegram($html, null, null, $keyboard);   // besedila so skrajšana že pred ubežanjem (rez v entiteti bi Telegram zavrnil)
    }
}
