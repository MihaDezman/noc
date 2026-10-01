<?php
declare(strict_types=1);

/** Prijava, vloge, CSRF, flash sporočila */
final class Auth
{
    public static ?array $user = null;

    public static function start(): void
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name($https ? '__Host-noc_sid' : 'noc_sid');   // __Host-: samo HTTPS, brez domene – ni ga mogoče podtakniti s poddomene
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id=? AND active=1');
            $st->execute([$_SESSION['uid']]);
            self::$user = $st->fetch() ?: null;
            if (!self::$user) unset($_SESSION['uid']);
            elseif (time() - ($_SESSION['seen'] ?? 0) > 12 * 3600) { self::logout(); }   // 12 h nedejavnosti
            else $_SESSION['seen'] = time();
        }
        $GLOBALS['LANG'] = self::$user['lang'] ?? ($_SESSION['lang'] ?? 'sl');
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = random_token(16);
    }

    public static function loginBlocked(string $email): bool
    {
        $st = db()->prepare('SELECT (SELECT COUNT(*) FROM login_attempts WHERE email=? AND at > NOW() - INTERVAL 15 MINUTE) a, (SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at > NOW() - INTERVAL 15 MINUTE) b');
        $st->execute([mb_strtolower($email), client_ip()]); $r = $st->fetch();
        return $r['a'] >= 5 || $r['b'] >= 15;
    }

    /** Prijava z geslom: 'ok' (prijavljen), 'totp' (čaka na kodo 2FA) ali false */
    public static function login(string $email, string $password): string|false
    {
        $email = mb_strtolower(trim($email));
        if (self::loginBlocked($email)) return false;
        $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1'); $st->execute([$email]); $u = $st->fetch();
        if (!$u || !password_verify($password, $u['pass_hash'])) { self::fail($email); return false; }
        if (!empty($u['totp_secret'])) {
            session_regenerate_id(true);
            $_SESSION['2fa_uid'] = (int)$u['id']; $_SESSION['2fa_at'] = time();
            return 'totp';
        }
        self::finish($u);
        return 'ok';
    }

    /** Drugi korak: koda iz aplikacije (velja 5 min po geslu) */
    public static function loginTotp(string $code): bool
    {
        $uid = (int)($_SESSION['2fa_uid'] ?? 0);
        if (!$uid || time() - (int)($_SESSION['2fa_at'] ?? 0) > 300) { unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']); return false; }
        $st = db()->prepare('SELECT * FROM users WHERE id=? AND active=1'); $st->execute([$uid]); $u = $st->fetch();
        if (!$u || self::loginBlocked($u['email'])) return false;
        $step = Totp::verify(dec($u['totp_secret']), $code, $u['totp_last'] !== null ? (int)$u['totp_last'] : null);
        if ($step === null) { self::fail($u['email']); return false; }
        db()->prepare('UPDATE users SET totp_last=? WHERE id=?')->execute([$step, $uid]);
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
        self::finish($u);
        return true;
    }

    public static function pendingTotp(): bool { return !empty($_SESSION['2fa_uid']) && time() - (int)($_SESSION['2fa_at'] ?? 0) <= 300; }

    private static function fail(string $email): void
    {
        db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, client_ip()]);
        error_log('noc-login-fail ip=' . client_ip() . ' email=' . $email);   // za fail2ban
    }

    private static function finish(array $u): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id']; $_SESSION['seen'] = time();
        db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$u['id']]);
        db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$u['email']]);
        self::$user = $u; audit('login', !empty($u['totp_secret']) ? '2FA' : '');
    }

    public static function logout(): void { $_SESSION = []; session_destroy(); self::$user = null; }

    public static function isSuper(): bool { return (self::$user['role'] ?? '') === 'superadmin'; }
    public static function requireSuper(): void { if (!self::isSuper()) { http_response_code(403); exit('403'); } }

    /** SQL pogoj za naprave, ki jih uporabnik sme videti */
    public static function deviceScope(string $alias = 'd'): array
    {
        if (self::isSuper()) return ['1=1', []];
        return ["$alias.tenant_id = ?", [(int)(self::$user['tenant_id'] ?? 0)]];
    }

    public static function csrf(): string { return '<input type="hidden" name="_csrf" value="' . h($_SESSION['csrf']) . '">'; }
    public static function checkCsrf(): void
    {
        $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) { http_response_code(400); exit('CSRF'); }
    }

    public static function flash(?string $msg = null, string $type = 'ok'): ?array
    {
        if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
        $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
    }
}
