<?php
declare(strict_types=1);

/**
 * Telegram bot: ukazi in gumbi pri alarmih. Telegram vsako sporočilo pošlje na webhook
 * /api/telegram/<skrivnost>; upoštevamo samo sporočila iz nastavljenega chat ID-ja.
 */
final class TgBot
{
    /** Skrivni del URL-ja in žeton v glavi – izpeljan iz app_secret, zato ga ni treba hraniti */
    public static function secret(): string { return substr(hash_hmac('sha256', 'telegram-webhook', (string)cfg('app_secret')), 0, 32); }
    public static function url(): string { return rtrim((string)cfg('base_url'), '/') . '/api/telegram/' . self::secret(); }

    public static function enable(): array
    {
        $tok = dec(setting('tg_token_enc', ''));
        if ($tok === '') return ['ok' => false, 'description' => 'token'];
        $r = Notify::tgApi($tok, 'setWebhook', ['url' => self::url(), 'secret_token' => self::secret(), 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
        if (!empty($r['ok'])) {
            Notify::tgApi($tok, 'setMyCommands', ['commands' => [
                ['command' => 'stanje', 'description' => A('Povzetek vseh naprav')],
                ['command' => 'alarmi', 'description' => A('Odprti alarmi')],
                ['command' => 'naprava', 'description' => A('Stanje naprave: /naprava ime')],
                ['command' => 'promet', 'description' => A('Promet naprave: /promet ime')],
                ['command' => 'utisaj', 'description' => A('Utišaj obvestila: /utisaj ime 2h')],
                ['command' => 'porocilo', 'description' => A('Mesečno poročilo: /porocilo ime')],
                ['command' => 'pomoc', 'description' => A('Seznam ukazov')],
            ]]);
            setting_set('tg_webhook', '1');
        }
        return $r;
    }

    public static function disable(): array
    {
        $tok = dec(setting('tg_token_enc', ''));
        $r = $tok !== '' ? Notify::tgApi($tok, 'deleteWebhook') : ['ok' => true];
        setting_set('tg_webhook', '0');
        return $r;
    }

    /** Vstopna točka webhooka */
    public static function webhook(string $pathSecret, string $headerSecret, string $raw): void
    {
        $sec = self::secret();
        if (!hash_equals($sec, $pathSecret) || !hash_equals($sec, $headerSecret)) { error_log('noc-api-badkey ip=' . client_ip() . ' path=/api/telegram'); http_response_code(403); exit; }
        $u = json_decode($raw, true);
        if (!is_array($u)) return;
        $chat = setting('tg_chat_id', '');
        $GLOBALS['LANG'] = 'sl';
        if (isset($u['callback_query'])) {
            $cq = $u['callback_query'];
            if ((string)($cq['message']['chat']['id'] ?? '') !== $chat) return;
            self::callback($cq);
            return;
        }
        $msg = $u['message'] ?? null;
        if (!$msg || (string)($msg['chat']['id'] ?? '') !== $chat) return;   // tuji pogovori: tiho zavrženo
        $text = trim((string)($msg['text'] ?? ''));
        if ($text === '' || $text[0] !== '/') { self::reply(A('Ukazi: /pomoc')); return; }
        [$cmd, $arg] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
        $cmd = strtolower(preg_replace('/@.*$/', '', substr($cmd, 1)));
        try {
            match ($cmd) {
                'start', 'pomoc', 'help' => self::help(),
                'stanje', 'status' => self::status(),
                'alarmi', 'alerts' => self::alerts(),
                'naprava', 'n' => self::device($arg),
                'promet' => self::traffic($arg),
                'utisaj', 'mute' => self::mute($arg),
                'porocilo' => self::report($arg),
                default => self::reply(A('Neznan ukaz. Seznam: /pomoc')),
            };
        } catch (\Throwable $e) {
            error_log('noc telegram: ' . $e->getMessage());
            self::reply('⚠️ ' . A('Napaka pri obdelavi ukaza.'));
        }
    }

    // ------------------------------------------------------------------ ukazi
    private static function help(): void
    {
        self::reply('<b>NOC – ' . A('ukazi') . '</b>' . "\n"
            . "/stanje – " . A('povzetek vseh naprav') . "\n"
            . "/alarmi – " . A('odprti alarmi') . "\n"
            . "/naprava <i>" . A('ime') . "</i> – " . A('stanje naprave') . "\n"
            . "/promet <i>" . A('ime') . "</i> – " . A('promet in največji porabniki') . "\n"
            . "/utisaj <i>" . A('ime') . "</i> 2h – " . A('utišaj obvestila (0 = vklopi)') . "\n"
            . "/porocilo <i>" . A('ime') . "</i> – " . A('povezava do mesečnega poročila') . "\n\n"
            . A('Ime je lahko del imena naprave ali naročnika, npr. /naprava donat'));
    }

    private static function status(): void
    {
        $devs = self::devices(); $c = ['up' => 0, 'warn' => 0, 'down' => 0, 'new' => 0];
        foreach ($devs as $d) $c[$d['state']]++;
        $open = db()->query("SELECT severity, COUNT(*) n FROM alerts WHERE ended_at IS NULL AND severity<>'info' GROUP BY severity")->fetchAll(PDO::FETCH_KEY_PAIR);
        $t = '<b>NOC</b> · ' . date('H:i') . "\n"
           . '🟢 ' . $c['up'] . ' ' . A('deluje') . '   🟠 ' . $c['warn'] . ' ' . A('opozorilo') . '   🔴 ' . $c['down'] . ' ' . A('ne deluje') . ($c['new'] ? '   ⚪ ' . $c['new'] : '') . "\n"
           . A('Odprti alarmi') . ': ' . ($nc = (int)($open['critical'] ?? 0)) . ' ' . plural($nc, A('kritičen'), A('kritična'), A('kritični'), A('kritičnih')) . ', ' . ($nw = (int)($open['warning'] ?? 0)) . ' ' . plural($nw, A('opozorilo'), A('opozorili'), A('opozorila'), A('opozoril'));
        $bad = array_filter($devs, fn($d) => in_array($d['state'], ['down', 'warn'], true));
        if ($bad) { $t .= "\n"; foreach ($bad as $d) $t .= "\n" . ($d['state'] === 'down' ? '🔴 ' : '🟠 ') . self::e($d['name']); }
        self::reply($t);
    }

    private static function alerts(): void
    {
        $rows = db()->query("SELECT a.*, d.name dn FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.ended_at IS NULL ORDER BY FIELD(a.severity,'critical','warning','info'), a.started_at DESC LIMIT 25")->fetchAll();
        if (!$rows) { self::reply('✅ ' . A('Ni odprtih alarmov.')); return; }
        $t = '<b>' . A('Odprti alarmi') . '</b>';
        foreach ($rows as $a) $t .= "\n" . ['critical' => '🔴', 'warning' => '🟠', 'info' => 'ℹ️'][$a['severity']] . ' <b>' . self::e($a['dn']) . '</b>: ' . self::e($a['message']) . ' <i>(' . fmt_ago($a['started_at']) . ')</i>';
        self::reply($t);
    }

    private static function device(string $q): void
    {
        $d = self::find($q); if (!$d) return;
        $h = $d['status']['h'] ?? [];
        $st = db()->prepare('SELECT rx_bps, tx_bps FROM device_ifaces WHERE device_id=? AND name=?'); $st->execute([$d['id'], $d['wan_iface']]); $w = $st->fetch();
        $sla = Sla::period($d, time() - 30 * 86400, time());
        $al = Alerts::openList((int)$d['id']);
        $t = ['up' => '🟢', 'warn' => '🟠', 'down' => '🔴', 'new' => '⚪'][$d['state']] . ' <b>' . self::e($d['name']) . '</b>' . ($d['tenant_name'] ? ' · ' . self::e($d['tenant_name']) : '') . "\n"
           . self::e(($d['model'] ?: 'MikroTik') . ' · RouterOS ' . preg_replace('/\s.*$/', '', (string)$d['os_version'])) . "\n"
           . A('Zadnji push') . ': ' . fmt_ago($d['last_seen_at']) . ' · uptime ' . fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null) . "\n"
           . 'CPU ' . ($h['cpu'] ?? '–') . ' % · ' . A('pomnilnik') . ' ' . ($h['mem_pct'] ?? '–') . ' %' . (isset($h['temp']) ? ' · ' . $h['temp'] . ' °C' : '') . "\n"
           . 'WAN ↓ ' . ($w ? fmt_bps($w['rx_bps']) : '–') . ' · ↑ ' . ($w ? fmt_bps($w['tx_bps']) : '–') . "\n"
           . 'Ping ' . A('prehod') . ' ' . (isset($h['gw_ms']) && $h['gw_ms'] !== null ? number_format((float)$h['gw_ms'], 1, ',', '') . ' ms' : '–')
           . ' · internet ' . (isset($h['ext_ms']) && $h['ext_ms'] !== null ? number_format((float)$h['ext_ms'], 1, ',', '') . ' ms' : '–') . "\n"
           . A('Razpoložljivost 30 d') . ': ' . Sla::fmt($sla['pct']) . ($d['muted'] ? "\n🔕 " . A('utišano do {t}', ['t' => fmt_dt($d['mute_until'])]) : '');
        if ($al) { $t .= "\n"; foreach (array_slice($al, 0, 5) as $a) $t .= "\n" . ['critical' => '🔴', 'warning' => '🟠', 'info' => 'ℹ️'][$a['severity']] . ' ' . self::e($a['message']); }
        $t .= "\n\n<a href=\"" . rtrim((string)cfg('base_url'), '/') . '/devices/' . $d['id'] . '">' . A('Odpri v NOC') . '</a>';
        self::reply($t, [[['text' => '🔕 ' . A('Utišaj 1 h'), 'callback_data' => 'mute:' . $d['id'] . ':1'], ['text' => '🔕 4 h', 'callback_data' => 'mute:' . $d['id'] . ':4']]]);
    }

    private static function traffic(string $q): void
    {
        $d = self::find($q); if (!$d) return;
        $st = db()->prepare('SELECT rx_bps, tx_bps FROM device_ifaces WHERE device_id=? AND name=?'); $st->execute([$d['id'], $d['wan_iface']]); $w = $st->fetch();
        $vol = $d['wan_iface'] ? Metrics::volume((int)$d['id'], $d['wan_iface']) : null;
        $t = '<b>' . self::e($d['name']) . '</b> · ' . A('promet') . "\n"
           . A('Zdaj') . ': ↓ ' . ($w ? fmt_bps($w['rx_bps']) : '–') . ' · ↑ ' . ($w ? fmt_bps($w['tx_bps']) : '–') . "\n"
           . ($vol ? A('Danes') . ': ' . fmt_bytes($vol['today']['rx'] + $vol['today']['tx']) . ' · ' . A('ta mesec') . ': ' . fmt_bytes($vol['month']['rx'] + $vol['month']['tx']) : '');
        $top = Flows::top((int)$d['id'], '1h', 5);
        if ($top) { $t .= "\n\n<b>" . A('Največ prometa (1 h)') . '</b>'; foreach ($top as $r) $t .= "\n" . self::e(($r['label'] ?: $r['hostname']) ?: $r['ip']) . ' – ' . fmt_bytes($r['up'] + $r['down']); }
        self::reply($t);
    }

    private static function mute(string $arg): void
    {
        if (!preg_match('/^(.*?)\s+(\d+)\s*([hd]?)$/iu', trim($arg), $m)) { self::reply(A('Uporaba: /utisaj ime 2h (ali 1d, 0 = vklopi obvestila)')); return; }
        $d = self::find($m[1]); if (!$d) return;
        $hours = (int)$m[2] * (strtolower($m[3]) === 'd' ? 24 : 1);
        self::doMute($d, min($hours, 24 * 30));
    }

    private static function doMute(array $d, int $hours): void
    {
        db()->prepare('UPDATE devices SET mute_until=? WHERE id=?')->execute([$hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : null, $d['id']]);
        db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (NULL, ?, ?, ?)')->execute(['tg.mute', $d['name'] . " $hours h", 'telegram']);
        self::reply($hours > 0 ? '🔕 ' . A('{n}: obvestila utišana za {h} h.', ['n' => self::e($d['name']), 'h' => $hours]) : '🔔 ' . A('{n}: obvestila spet vklopljena.', ['n' => self::e($d['name'])]));
    }

    private static function report(string $q): void
    {
        $d = self::find($q); if (!$d) return;
        if (!$d['tenant_id']) { self::reply(A('Naprava nima naročnika.')); return; }
        $base = rtrim((string)cfg('base_url'), '/');
        $prev = date('Y-m', strtotime('first day of last month'));
        self::reply('<b>' . A('Mesečno poročilo') . '</b> · ' . self::e($d['tenant_name']) . "\n"
            . '<a href="' . $base . '/reports/view?t=' . $d['tenant_id'] . '&amp;m=' . $prev . '">' . Report::monthName($prev) . '</a>' . "\n"
            . '<a href="' . $base . '/reports/view?t=' . $d['tenant_id'] . '&amp;m=' . date('Y-m') . '">' . Report::monthName(date('Y-m')) . ' (' . A('tekoči') . ')</a>' . "\n"
            . '<i>' . A('Odpre se v NOC – potrebna je prijava.') . '</i>');
    }

    // ------------------------------------------------------------------ gumbi pri alarmih
    /** Gumba pod obvestilom o alarmu */
    public static function alertButtons(int $alertId, int $deviceId): array
    {
        return [[['text' => '✅ ' . A('Potrdi'), 'callback_data' => 'ack:' . $alertId], ['text' => '🔕 ' . A('Utišaj 1 h'), 'callback_data' => 'mute:' . $deviceId . ':1']]];
    }

    private static function callback(array $cq): void
    {
        $tok = dec(setting('tg_token_enc', '')); $data = (string)($cq['data'] ?? ''); $note = '';
        if (preg_match('/^ack:(\d+)$/', $data, $m)) {
            db()->prepare('UPDATE alerts SET acked_at=NOW() WHERE id=? AND acked_at IS NULL')->execute([(int)$m[1]]);
            db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (NULL, ?, ?, ?)')->execute(['tg.ack', '#' . $m[1], 'telegram']);
            $note = A('Alarm potrjen');
        } elseif (preg_match('/^mute:(\d+):(\d+)$/', $data, $m)) {
            $st = db()->prepare('SELECT * FROM devices WHERE id=?'); $st->execute([(int)$m[1]]); $d = $st->fetch();
            if ($d) { self::doMute(Devices::decorate($d), (int)$m[2]); $note = A('Utišano za {h} h', ['h' => (int)$m[2]]); }
        }
        Notify::tgApi($tok, 'answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $note]);
        if (isset($cq['message']['message_id']) && str_starts_with($data, 'ack:')) {
            Notify::tgApi($tok, 'editMessageReplyMarkup', ['chat_id' => $cq['message']['chat']['id'], 'message_id' => $cq['message']['message_id'], 'reply_markup' => ['inline_keyboard' => [[['text' => '✅ ' . $note, 'callback_data' => 'noop']]]]]);
        }
    }

    // ------------------------------------------------------------------ pomožno
    private static function devices(): array
    {
        $rows = db()->query("SELECT d.*, t.name tenant_name, (SELECT MAX(FIELD(a.severity,'info','warning','critical')) FROM alerts a WHERE a.device_id=d.id AND a.ended_at IS NULL) alert_level
                             FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1 ORDER BY d.name")->fetchAll();
        return array_map([Devices::class, 'decorate'], $rows);
    }

    /** Naprava po delu imena ali naročnika; ob več zadetkih pošlje seznam in vrne null */
    private static function find(string $q): ?array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') { self::reply(A('Dodaj ime naprave, npr. /naprava donat')); return null; }
        $all = self::devices();
        $hit = array_values(array_filter($all, fn($d) => mb_strtolower($d['name']) === $q));
        if (!$hit) $hit = array_values(array_filter($all, fn($d) => str_contains(mb_strtolower($d['name'] . ' ' . $d['tenant_name'] . ' ' . $d['site']), $q)));
        if (count($hit) === 1) return $hit[0];
        if (!$hit) { self::reply(A('Ni naprave, ki bi ustrezala "{q}".', ['q' => self::e($q)])); return null; }
        $t = A('Več zadetkov – natančneje:'); foreach (array_slice($hit, 0, 10) as $d) $t .= "\n• " . self::e($d['name']);
        self::reply($t);
        return null;
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function reply(string $html, ?array $keyboard = null): void
    {
        $p = ['chat_id' => setting('tg_chat_id', ''), 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($keyboard) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
        Notify::tgApi(dec(setting('tg_token_enc', '')), 'sendMessage', $p);
    }
}
