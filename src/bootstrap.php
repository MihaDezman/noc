<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.4');

$config = require APP_ROOT . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Europe/Ljubljana');
if (!empty($config['debug'])) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

if (file_exists(APP_ROOT . '/vendor/autoload.php')) require APP_ROOT . '/vendor/autoload.php';
foreach (['Auth', 'Devices', 'Ingest', 'Metrics', 'Alerts', 'Notify', 'Flows', 'MikrotikExport', 'MikrotikScript', 'Diff', 'Forms', 'Sla', 'Report'] as $c) require __DIR__ . "/$c.php";

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function cfg(string $key, $default = null) { global $config; return $config[$key] ?? $default; }

/** Nastavitve iz tabele settings (predpomnjeno za en zahtevek) */
function setting(string $k, string $default = ''): string {
    static $all = null;
    if ($all === null) { $all = []; foreach (db()->query('SELECT k, v FROM settings') as $r) $all[$r['k']] = $r['v']; }
    return $all[$k] ?? $default;
}
function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url, true, 302); exit; }
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

/** AES-256-GCM z app_secret (API ključi, Telegram token) */
function enc(string $plain): string {
    $key = hash('sha256', cfg('app_secret'), true); $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function dec(?string $b64): string {
    if (!$b64) return '';
    $raw = base64_decode($b64); $key = hash('sha256', cfg('app_secret'), true);
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

function normalize_mac(string $mac): string {
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
}

function audit(string $action, string $detail = ''): void {
    db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (?,?,?,?)')
        ->execute([Auth::$user['id'] ?? null, $action, mb_substr($detail, 0, 500), client_ip()]);
}

function cron_mark(string $name, string $info = ''): void {
    db()->prepare('INSERT INTO cron_runs (name, last_at, info) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE last_at=NOW(), info=VALUES(info)')->execute([$name, $info]);
}

// ---------------------------------------------------------------- i18n (SL je osnova, EN prevod v lang/en.php)
$LANG = 'sl';
function A(string $sl, array $vars = []): string {
    global $LANG; static $dict = [];
    $s = $sl;
    if ($LANG !== 'sl') {
        if (!isset($dict[$LANG])) { $f = APP_ROOT . "/lang/$LANG.php"; $dict[$LANG] = file_exists($f) ? require $f : []; }
        $s = $dict[$LANG][$sl] ?? $sl;
    }
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

/** Slovenska množina: plural_sl(n, 'naprava', 'napravi', 'naprave', 'naprav'); v EN prvi in zadnji */
function plural(int $n, string $one, string $two, string $few, string $many): string {
    global $LANG;
    if ($LANG === 'en') return $n === 1 ? A($one) : A($many);
    $m = $n % 100;
    return $m === 1 ? $one : ($m === 2 ? $two : ($m === 3 || $m === 4 ? $few : $many));
}

// ---------------------------------------------------------------- ikone
function icon(string $name, int $size = 18, string $class = ''): string {
    static $set = null; $set ??= require __DIR__ . '/icons.php';
    $inner = $set[$name] ?? $set['info'];
    return '<svg class="ico ' . h($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

// ---------------------------------------------------------------- oblikovanje
function fmt_bps($bps): string {
    $bps = (float)$bps;
    if ($bps >= 1e9) return number_format($bps / 1e9, 2, ',', '.') . ' Gb/s';
    if ($bps >= 1e6) return number_format($bps / 1e6, 1, ',', '.') . ' Mb/s';
    if ($bps >= 1e3) return number_format($bps / 1e3, 0, ',', '.') . ' kb/s';
    return (int)$bps . ' b/s';
}
function fmt_bytes($b): string {
    $b = (float)$b;
    foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $i => $u) {
        if ($b < 1024 || $u === 'TB') return number_format($b, $i < 2 ? 0 : 1, ',', '.') . ' ' . $u;
        $b /= 1024;
    }
    return '';
}
function fmt_uptime(?int $s): string {
    if ($s === null) return '–';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    return $d > 0 ? "{$d} d {$h} h" : ($h > 0 ? "{$h} h {$m} min" : "{$m} min");
}
function fmt_ago(?string $dt): string {
    if (!$dt) return A('nikoli');
    $s = time() - strtotime($dt);
    if ($s < 60) return A('pravkar');
    if ($s < 3600) return A('pred {n} min', ['n' => intdiv($s, 60)]);
    if ($s < 86400) return A('pred {n} h', ['n' => intdiv($s, 3600)]);
    return A('pred {n} d', ['n' => intdiv($s, 86400)]);
}
function fmt_dt(?string $dt, bool $sec = false): string {
    if (!$dt) return '–';
    $t = strtotime($dt);
    return date(date('Y-m-d', $t) === date('Y-m-d') ? ($sec ? 'H:i:s' : 'H:i') : 'd.m.Y H:i', $t);
}

/** RouterOS čas "1w2d03:04:05" ali "3d4h5m6s" -> sekunde */
function ros_seconds(string $s): ?int {
    $s = trim($s); if ($s === '') return null;
    $t = 0;
    if (preg_match('/(\d+):(\d+):(\d+)$/', $s, $m)) { $t += $m[1] * 3600 + $m[2] * 60 + $m[3]; $s = substr($s, 0, -strlen($m[0])); }
    foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $u => $mul)
        if (preg_match('/(\d+)' . $u . '(?![a-z])/', $s, $m)) $t += (int)$m[1] * $mul;
    return $t;
}

function ip_in_cidr(string $ip, string $cidr): bool {
    [$net, $bits] = array_pad(explode('/', trim($cidr)), 2, '32');
    $i = ip2long($ip); $n = ip2long($net);
    if ($i === false || $n === false) return false;
    $mask = (int)$bits === 0 ? 0 : (-1 << (32 - (int)$bits)) & 0xFFFFFFFF;
    return ($i & $mask) === ($n & $mask);
}
