<?php
declare(strict_types=1);

/** Obveščanje: e-pošta (PHPMailer, SMTP iz config.php) in Telegram bot */
final class Notify
{
    private const EMOJI = ['critical' => '🔴', 'warning' => '🟠', 'info' => 'ℹ️'];

    public static function alert(array $d, string $sev, string $msg, bool $resolved): void
    {
        if (!empty($d['mute_until']) && strtotime($d['mute_until']) > time()) return;
        $lvl = Alerts::LEVEL[$sev] ?? 1;
        $name = $d['name'] . (!empty($d['tenant_name']) ? ' (' . $d['tenant_name'] . ')' : '');
        $icon = $resolved ? '✅' : (self::EMOJI[$sev] ?? '');
        $url = rtrim((string)cfg('base_url'), '/') . '/devices/' . $d['id'];

        if ($lvl >= (Alerts::LEVEL[setting('notify_tg_min', 'warning')] ?? 2)) {
            self::telegram("$icon <b>" . htmlspecialchars($name) . "</b>\n" . htmlspecialchars($msg) . "\n<a href=\"$url\">" . A('Odpri v NOC') . '</a>');
        }
        if ($lvl >= (Alerts::LEVEL[setting('notify_mail_min', 'warning')] ?? 2)) {
            $subj = ($resolved ? '[OK] ' : '[' . strtoupper($sev) . '] ') . $name . ': ' . $msg;
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px"><p style="font-size:16px"><b>' . htmlspecialchars($name) . '</b></p><p>' . $icon . ' ' . htmlspecialchars($msg) . '</p><p style="color:#666">' . date('d.m.Y H:i:s') . '</p><p><a href="' . $url . '">' . A('Odpri v NOC') . '</a></p></div>';
            foreach (self::recipients() as $to) self::mail($to, $subj, $html);
        }
    }

    public static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', setting('notify_emails', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    public static function telegram(string $html, ?string $token = null, ?string $chat = null): array
    {
        $token ??= dec(setting('tg_token_enc', '')); $chat ??= setting('tg_chat_id', '');
        if ($token === '' || $chat === '') return ['ok' => false, 'description' => 'not configured'];
        return self::tgApi($token, 'sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
    }

    public static function tgApi(string $token, string $method, array $params = []): array
    {
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
