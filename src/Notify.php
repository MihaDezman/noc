<?php
declare(strict_types=1);

/** Obveščanje: e-pošta (PHPMailer, SMTP iz config.php) in Telegram bot */
final class Notify
{
    private const EMOJI = ['critical' => '🔴', 'warning' => '🟠', 'info' => 'ℹ️'];

    /** Število obvestil v enem krogu, nad katerim gredo v en povzetek (npr. izpad več naprav naenkrat) */
    public const BATCH = 3;

    /**
     * Alarm v čakalno vrsto obvestil. Pošilja cron vsako minuto (Notify::flush) – push z routerja tako ne čaka na
     * SMTP/Telegram, ob množičnem izpadu pa pride en povzetek namesto desetin sporočil.
     */
    public static function alert(array $d, string $sev, string $msg, bool $resolved, ?int $alertId = null): void
    {
        if (!empty($d['mute_until']) && strtotime($d['mute_until']) > time()) return;
        $name = $d['name'] . (!empty($d['tenant_name']) ? ' (' . $d['tenant_name'] . ')' : '');
        db()->prepare('INSERT INTO notify_queue (device_id, dname, sev, resolved, msg, alert_id) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$d['id'], mb_substr($name, 0, 200), $sev, $resolved ? 1 : 0, mb_substr($msg, 0, 600), $alertId]);
    }

    /** Pošlje čakajoča obvestila: do BATCH posamično, več kot to v enem povzetku. Vrne kratko poročilo za cron. */
    public static function flush(): string
    {
        $pdo = db();
        $rows = $pdo->query('SELECT * FROM notify_queue WHERE sent_at IS NULL ORDER BY id LIMIT 300')->fetchAll();
        if (!$rows) return '';
        // najprej označi kot poslano: napaka pri pošiljanju ne sme povzročiti ponavljanja vsako minuto
        $pdo->exec('UPDATE notify_queue SET sent_at=NOW() WHERE id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')');
        $lvl = fn($r) => Alerts::LEVEL[$r['sev']] ?? 1;
        $tg = array_values(array_filter($rows, fn($r) => $lvl($r) >= (Alerts::LEVEL[setting('notify_tg_min', 'warning')] ?? 2)));
        $ml = array_values(array_filter($rows, fn($r) => $lvl($r) >= (Alerts::LEVEL[setting('notify_mail_min', 'warning')] ?? 2)));
        $base = rtrim((string)cfg('base_url'), '/');
        $icon = fn($r) => (int)$r['resolved'] ? '✅' : (self::EMOJI[$r['sev']] ?? '');
        $url = fn($r) => $base . '/devices/' . (int)$r['device_id'];

        if ($tg && count($tg) <= self::BATCH) {
            foreach ($tg as $r) {
                $kb = (!(int)$r['resolved'] && $r['alert_id'] && setting('tg_webhook') === '1') ? TgBot::alertButtons((int)$r['alert_id'], (int)$r['device_id']) : null;   // gumba samo z vklopljenimi ukazi
                // napad ali skeniranje z zunanjega IP-ja: gumb za blokado na routerju (ukaz potrdi skrbnik s klikom)
                if ($kb && ($ip = self::attackerIp((int)$r['alert_id'])) !== null) $kb[] = [['text' => '🚫 ' . A('Blokiraj {ip} za 24 h', ['ip' => $ip]), 'callback_data' => 'blk:' . (int)$r['device_id'] . ':' . $ip . ':24']];
                self::telegram($icon($r) . ' <b>' . htmlspecialchars($r['dname']) . "</b>\n" . htmlspecialchars($r['msg']) . "\n<a href=\"" . $url($r) . '">' . A('Odpri v NOC') . '</a>', null, null, $kb);
            }
        } elseif ($tg) {
            $txt = self::summaryHead($tg) . "\n"; $left = count($tg);
            foreach ($tg as $r) {
                $line = "\n" . $icon($r) . ' <b>' . htmlspecialchars($r['dname']) . '</b>: ' . htmlspecialchars($r['msg']);
                if (mb_strlen($txt . $line) > 3500) break;   // Telegram: največ 4096 znakov
                $txt .= $line; $left--;
            }
            if ($left > 0) $txt .= "\n" . A('… in še {n}', ['n' => $left]);
            self::telegram($txt . "\n\n<a href=\"$base/alerts\">" . A('Vsi alarmi v NOC') . '</a>');
        }

        if ($ml && ($to = self::recipients())) {
            $wrap = fn($inner) => '<div style="font-family:Arial,sans-serif;font-size:14px">' . $inner . '<p style="color:#666">' . date('d.m.Y H:i:s') . '</p></div>';
            if (count($ml) <= self::BATCH) {
                foreach ($ml as $r) {
                    $subj = ((int)$r['resolved'] ? '[OK] ' : '[' . strtoupper($r['sev']) . '] ') . $r['dname'] . ': ' . $r['msg'];
                    $html = $wrap('<p style="font-size:16px"><b>' . htmlspecialchars($r['dname']) . '</b></p><p>' . $icon($r) . ' ' . htmlspecialchars($r['msg']) . '</p><p><a href="' . $url($r) . '">' . A('Odpri v NOC') . '</a></p>');
                    foreach ($to as $e) self::mail($e, $subj, $html);
                }
            } else {
                $li = '';
                foreach ($ml as $r) $li .= '<li>' . $icon($r) . ' <b>' . htmlspecialchars($r['dname']) . '</b>: ' . htmlspecialchars($r['msg']) . ' – <a href="' . $url($r) . '">' . A('odpri') . '</a></li>';
                $html = $wrap('<p style="font-size:16px"><b>' . htmlspecialchars(strip_tags(self::summaryHead($ml))) . '</b></p><ul>' . $li . '</ul><p><a href="' . $base . '/alerts">' . A('Vsi alarmi v NOC') . '</a></p>');
                $crit = count(array_filter($ml, fn($r) => $r['sev'] === 'critical' && !(int)$r['resolved']));
                foreach ($to as $e) self::mail($e, '[NOC] ' . strip_tags(self::summaryHead($ml)) . ($crit ? ' – ' . A('kritičnih: {n}', ['n' => $crit]) : ''), $html);
            }
        }
        return count($rows) . ' (tg ' . count($tg) . ', mail ' . count($ml) . ')';
    }

    /** Zunanji IP napadalca iz ključa alarma (attack:IP, fwscan:IP) ali null */
    private static function attackerIp(int $alertId): ?string
    {
        $st = db()->prepare('SELECT akey FROM alerts WHERE id=?'); $st->execute([$alertId]); $k = (string)$st->fetchColumn();
        if (!preg_match('/^(?:attack|fwscan):(\d{1,3}(?:\.\d{1,3}){3})$/', $k, $m)) return null;
        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $m[1] : null;
    }

    /** "🔴 12 novih alarmov, 3 razrešeni" */
    private static function summaryHead(array $rows): string
    {
        $new = count(array_filter($rows, fn($r) => !(int)$r['resolved'])); $ok = count($rows) - $new;
        $crit = count(array_filter($rows, fn($r) => !(int)$r['resolved'] && $r['sev'] === 'critical'));
        $parts = [];
        if ($new) $parts[] = $new . ' ' . plural($new, A('novo obvestilo'), A('novi obvestili'), A('nova obvestila'), A('novih obvestil'));
        if ($ok) $parts[] = $ok . ' ' . plural($ok, A('razrešeno'), A('razrešeni'), A('razrešena'), A('razrešenih'));
        return ($crit ? '🔴' : ($new ? '🟠' : '✅')) . ' <b>NOC</b> – ' . implode(', ', $parts);
    }

    public static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', setting('notify_emails', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    public static function telegram(string $html, ?string $token = null, ?string $chat = null, ?array $keyboard = null): array
    {
        $token ??= dec(setting('tg_token_enc', '')); $chat ??= setting('tg_chat_id', '');
        if ($token === '' || $chat === '') return ['ok' => false, 'description' => 'not configured'];
        $p = ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($keyboard) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
        $r = self::tgApi($token, 'sendMessage', $p);
        if (empty($r['ok']) && ($r['description'] ?? '') !== 'not configured') error_log('noc telegram: ' . ($r['description'] ?? 'napaka'));   // obvestilo je iz čakalne vrste že odstranjeno
        return $r;
    }

    public static function tgApi(string $token, string $method, array $params = []): array
    {
        if ($f = (string)cfg('tg_debug_file', '')) { file_put_contents($f, json_encode([$method, $params], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); return ['ok' => true, 'result' => []]; }   // samo za testiranje
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($params), 'timeout' => 8, 'ignore_errors' => true]]);
        $r = @file_get_contents('https://api.telegram.org/bot' . $token . '/' . $method, false, $ctx);
        return json_decode((string)$r, true) ?: ['ok' => false, 'description' => 'no response'];
    }

    /** Chat ID iz zadnjih sporočil botu (gumb "Poišči chat ID") */
    public static function tgFindChats(string $token): array
    {
        $r = self::tgApi($token, 'getUpdates');
        $out = [];
        foreach ($r['result'] ?? [] as $u) {
            $c = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
            if ($c) $out[(string)$c['id']] = trim(($c['title'] ?? '') . ' ' . ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '') . (isset($c['username']) ? ' @' . $c['username'] : ''));
        }
        return $out;
    }

    public static function mail(string $to, string $subject, string $html): bool
    {
        $s = cfg('smtp', []);
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('noc: PHPMailer ni nameščen (composer install)');
            return false;
        }
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP(); $m->Host = $s['host']; $m->Port = (int)$s['port']; $m->SMTPAuth = true;
            $m->Username = $s['user']; $m->Password = $s['pass'];
            $m->SMTPSecure = ($s['secure'] ?? 'ssl') === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $m->CharSet = 'UTF-8'; $m->Timeout = 15;
            $m->setFrom($s['from'], $s['from_name'] ?? 'NOC');
            $m->addAddress($to); $m->Subject = $subject; $m->isHTML(true); $m->Body = $html;
            $m->AltBody = trim(strip_tags(str_replace(['</p>', '<br>'], "\n", $html)));
            return $m->send();
        } catch (\Throwable $e) { error_log('noc mail: ' . $e->getMessage()); return false; }
    }
}
