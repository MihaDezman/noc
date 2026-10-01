<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ================================================================ API naprav (API ključ, brez seje)
if (str_starts_with($path, '/api/')) {
    // --- dodajanje routerja z enkratno kodo (brez API ključa – koda je ključ, velja 30 min, enkratna)
    if (preg_match('#^/api/enroll/([a-z0-9]+)$#', $path, $m)) {
        $e = Enroll::byCode($m[1]);
        if (!$e) { error_log('noc-api-badkey ip=' . client_ip() . ' path=/api/enroll'); http_response_code(404); header('Content-Type: text/plain'); exit(":log warning \"noc: koda za dodajanje ni veljavna ali je potekla\"\n"); }
        if ($method === 'GET') { header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo Enroll::script($m[1]); exit; }
        if ($method === 'POST') json_out(Enroll::part($e, (string)($_SERVER['HTTP_X_UPLOAD'] ?? ''), (int)($_SERVER['HTTP_X_PART'] ?? -1), (int)($_SERVER['HTTP_X_SIZE'] ?? 0), (string)file_get_contents('php://input')));
        json_out(['error' => 'method'], 405);
    }
    // --- Telegram bot (webhook z lastnim skrivnim žetonom)
    if (preg_match('#^/api/telegram/([a-f0-9]{32})$#', $path, $m) && $method === 'POST') {
        TgBot::webhook($m[1], (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''), (string)file_get_contents('php://input'));
        json_out(['ok' => true]);
    }

    $dev = Devices::byKey((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if (!$dev) { error_log('noc-api-badkey ip=' . client_ip() . ' path=' . $path); json_out(['error' => 'unauthorized'], 401); }   // za fail2ban
    if ($path === '/api/push' && $method === 'POST') {
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) { error_log('noc push: neveljaven JSON od ' . $dev['name'] . ' (' . strlen($raw) . ' B)'); json_out(['error' => 'bad json'], 400); }
        $dir = rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/push';
        if (is_dir($dir) || @mkdir($dir, 0750, true)) @file_put_contents($dir . '/' . (int)$dev['id'] . '.json', $raw);
        json_out(Ingest::push($dev, $body));
    }
    if ($path === '/api/install' && $method === 'GET') {   // router sam prenese svoj namestitveni paket (en ukaz v terminalu)
        $st = db()->prepare('SELECT d.*, t.name tenant_name FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.id=?'); $st->execute([$dev['id']]);
        $full = $st->fetch();
        db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (NULL, ?, ?, ?)')->execute(['device.install-fetch', $full['name'], client_ip()]);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo MikrotikScript::ascii(MikrotikScript::package($full)['noc-install.rsc']);
        exit;
    }
    if ($path === '/api/filter/drop' && $method === 'GET') {   // varnostna IP lista za router (zaščita)
        header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
        echo Filter::dropRsc(); exit;
    }
    if ($path === '/api/backup' && $method === 'POST') {
        json_out(Ingest::backupPart($dev, (string)($_SERVER['HTTP_X_UPLOAD'] ?? ''), (int)($_SERVER['HTTP_X_PART'] ?? -1), (int)($_SERVER['HTTP_X_SIZE'] ?? 0), (string)file_get_contents('php://input')));
    }
    json_out(['error' => 'not found'], 404);
}

// ================================================================ spletni vmesnik
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
Auth::start();

function view(string $name, array $vars = []): never {
    extract($vars, EXTR_SKIP);
    $V = APP_ROOT . '/views';
    if ($name === 'login') { require "$V/login.php"; exit; }
    require "$V/_top.php"; require "$V/$name.php"; require "$V/_bottom.php"; exit;
}
function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function back(string $to, ?string $msg = null, string $type = 'ok'): never { if ($msg) Auth::flash($msg, $type); redirect($to); }
function q(string $k, string $d = ''): string { return trim((string)($_GET[$k] ?? $d)); }

// ---------------------------------------------------------------- prijava
if ($path === '/login') {
    if ($method === 'POST') {
        Auth::checkCsrf();
        $r = Auth::login(post('email'), post('password'));
        if ($r === 'ok') redirect('/');
        if ($r === 'totp') redirect('/login/2fa');
        view('login', ['error' => Auth::loginBlocked(post('email')) ? A('Preveč neuspelih poskusov – počakaj 15 minut.') : A('Napačen uporabnik ali geslo.')]);
    }
    if (Auth::$user) redirect('/');
    view('login', ['error' => '']);
}
if ($path === '/login/2fa') {
    if (Auth::$user) redirect('/');
    if (!Auth::pendingTotp()) redirect('/login');
    if ($method === 'POST') {
        Auth::checkCsrf();
        if (Auth::loginTotp(post('code'))) redirect('/');
        if (!Auth::pendingTotp()) redirect('/login');
        view('login', ['error' => A('Koda ni pravilna. Vpiši trenutno kodo iz aplikacije.'), 'step2' => true]);
    }
    view('login', ['error' => '', 'step2' => true]);
}
if (!Auth::$user) redirect('/login');
if ($method === 'POST') Auth::checkCsrf();
if ($path === '/logout') { if ($method === 'POST') { audit('logout'); Auth::logout(); redirect('/login'); } redirect('/'); }   // odjava samo s POST (CSRF)

$isSuper = Auth::isSuper();
$seg = explode('/', trim($path, '/'));

// ---------------------------------------------------------------- jezik
if ($path === '/lang' && $method === 'POST') {
    $l = in_array(post('lang'), ['sl', 'en'], true) ? post('lang') : 'sl';
    db()->prepare('UPDATE users SET lang=? WHERE id=?')->execute([$l, Auth::$user['id']]);
    redirect($_SERVER['HTTP_REFERER'] ?? '/');
}

// ---------------------------------------------------------------- JSON za grafe
if ($path === '/ui/chart') {
    $d = Devices::get((int)q('d'));
    $r = array_key_exists(q('r'), Metrics::RANGES) ? q('r') : '24h';
    $k = q('k');
    if ($k === 'traffic') json_out(['data' => Metrics::traffic((int)$d['id'], q('i'), $r)]);
    if (in_array($k, ['cpu', 'ping', 'temp', 'conns'], true)) json_out(['data' => Metrics::health((int)$d['id'], $k, $r)]);
    json_out(['error' => 'kind'], 400);
}

// ---------------------------------------------------------------- pregled
if ($path === '/') {
    $devs = Devices::all();
    $alerts = Alerts::openList();
    [$w, $p] = Auth::deviceScope('d');
    $st = db()->prepare("SELECT l.*, d.name device_name FROM logs l JOIN devices d ON d.id=l.device_id WHERE l.severity IN ('warning','error','critical') AND l.ts > NOW() - INTERVAL 24 HOUR AND $w ORDER BY l.ts DESC LIMIT 12");
    $st->execute($p); $logs = $st->fetchAll();
    $st = db()->prepare("SELECT a.*, d.name device_name FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.started_at > NOW() - INTERVAL 7 DAY AND $w ORDER BY a.started_at DESC LIMIT 10");
    $st->execute($p); $recent = $st->fetchAll();
    $ifaces = [];
    foreach ($devs as $d) $ifaces[$d['id']] = Devices::ifaces((int)$d['id']);
    view('dashboard', ['devs' => $devs, 'alerts' => $alerts, 'logs' => $logs, 'recent' => $recent, 'ifaces' => $ifaces, 'title' => A('Pregled'), 'nav' => 'dash', 'autorefresh' => 60]);
}

// ---------------------------------------------------------------- naprave
if ($path === '/devices') {
    $devs = Devices::all((int)q('t') ?: null);
    if (q('s') !== '') $devs = array_values(array_filter($devs, fn($d) => $d['state'] === q('s')));
    if (q('q') !== '') { $needle = mb_strtolower(q('q')); $devs = array_values(array_filter($devs, fn($d) => str_contains(mb_strtolower($d['name'] . ' ' . $d['site'] . ' ' . $d['public_ip'] . ' ' . $d['model'] . ' ' . $d['tenant_name']), $needle))); }
    view('devices', ['devs' => $devs, 'tenants' => Devices::tenants(), 'title' => A('Naprave'), 'nav' => 'devices', 'autorefresh' => 60]);
}

if ($path === '/devices/new') {
    Auth::requireSuper();
    if (q('enroll') !== '') {   // konfiguracija je prišla z routerja (enkratna koda)
        $e = Enroll::get((int)q('enroll'));
        if (!$e || $e['status'] !== 'received' || !$e['export_raw']) back('/devices/new', A('Konfiguracija z routerja ni na voljo – koda je potekla ali že porabljena.'), 'err');
        $_SESSION['pending_export'] = $e['export_raw']; $_SESSION['pending_enroll'] = (int)$e['id'];
        view('device_new', ['tenants' => Devices::tenants(), 'an' => MikrotikExport::analyze($e['export_raw']), 'enrolled' => $e, 'title' => A('Dodaj napravo'), 'nav' => 'devices']);
    }
    view('device_new', ['tenants' => Devices::tenants(), 'title' => A('Dodaj napravo'), 'nav' => 'devices']);
}
if ($path === '/devices/enroll' && $method === 'POST') {
    Auth::requireSuper();
    [$eid, $code] = Enroll::create();
    audit('enroll.create', "#$eid");
    view('device_enroll', ['eid' => $eid, 'cmd' => Enroll::oneLiner($code), 'title' => A('Dodaj napravo'), 'nav' => 'devices']);
}
if ($path === '/devices/enroll-status') {
    Auth::requireSuper();
    $e = Enroll::get((int)q('id'));
    json_out(['status' => $e['status'] ?? 'missing', 'expired' => $e ? strtotime($e['expires_at']) < time() : true, 'ip' => $e['router_ip'] ?? '']);
}
if ($path === '/devices/analyze' && $method === 'POST') {
    Auth::requireSuper();
    $rsc = post('export');
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) $rsc = (string)file_get_contents($_FILES['file']['tmp_name']);
    if (strlen($rsc) < 50 || !str_contains($rsc, '/')) back('/devices/new', A('Prilepi ali naloži izpis ukaza /export.'), 'err');
    $an = MikrotikExport::analyze($rsc);
    $_SESSION['pending_export'] = $rsc; unset($_SESSION['pending_enroll']);
    view('device_new', ['tenants' => Devices::tenants(), 'an' => $an, 'title' => A('Dodaj napravo'), 'nav' => 'devices']);
}
if ($path === '/devices/create' && $method === 'POST') {
    Auth::requireSuper();
    $rsc = (string)($_SESSION['pending_export'] ?? '');
    if ($rsc === '') back('/devices/new', A('Seja je potekla – ponovno naloži /export.'), 'err');
    $an = MikrotikExport::analyze($rsc);
    $tenantId = (int)post('tenant_id');
    if (post('new_tenant') !== '') { db()->prepare('INSERT INTO tenants (name) VALUES (?)')->execute([post('new_tenant')]); $tenantId = (int)db()->lastInsertId(); }
    $f = DeviceForm::read();
    db()->prepare('INSERT INTO devices (tenant_id, driver, kind, name, site, model, serial, os_version, public_ip, wan_iface, wan_gateway, ping_target, lan_networks, monitor_ifaces, down_ifaces, wan_down_mbps, wan_up_mbps, flow_enabled, export_raw, analysis_json, notes)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$tenantId ?: null, 'mikrotik', 'router', $f['name'], $f['site'], $an['model'], $an['serial'], $an['ros_version'], $f['public_ip'], $f['wan_iface'], $f['wan_gateway'], $f['ping_target'],
            $f['lan_networks'], $f['monitor_ifaces'], $f['down_ifaces'], $f['wan_down_mbps'], $f['wan_up_mbps'], $f['flow_enabled'], $rsc, json_encode($an, JSON_UNESCAPED_UNICODE), $f['notes']]);
    $id = (int)db()->lastInsertId();
    Devices::newKey($id);
    if (!empty($_SESSION['pending_enroll'])) Enroll::markUsed((int)$_SESSION['pending_enroll'], $id);
    unset($_SESSION['pending_export'], $_SESSION['pending_enroll']);
    Ufw::write();
    audit('device.create', $f['name']);
    back("/devices/$id/install", A('Naprava dodana – prenesi paket in ga naloži na router.'));
}

if (($seg[0] ?? '') === 'devices' && ctype_digit($seg[1] ?? '')) {
    $id = (int)$seg[1]; $sub = $seg[2] ?? '';
    $d = Devices::get($id);

    if ($sub === '') {
        $tab = q('tab', 'overview');
        if ($tab === 'wg') $tab = 'ifaces';   // WireGuard je zdaj na zavihku Vmesniki
        $allowed = ['overview', 'ifaces', 'clients', 'sla', 'security', 'logs', 'alerts'];
        if ($isSuper) array_push($allowed, 'backups', 'settings', 'protect');
        if (!in_array($tab, $allowed, true)) $tab = 'overview';
        $vars = ['d' => $d, 'tab' => $tab, 'ifaces' => Devices::ifaces($id), 'alerts' => Alerts::openList($id), 'title' => $d['name'], 'nav' => 'devices', 'tenants' => Devices::tenants()];
        if ($tab === 'overview') { $vars['autorefresh'] = 120; $vars['vol'] = $d['wan_iface'] ? Metrics::volume($id, $d['wan_iface']) : null; }
        if ($tab === 'clients') {
            $vars['range'] = in_array(q('r'), ['1h', '24h', '7d', '30d'], true) ? q('r') : '24h';
            $vars['top'] = Flows::top($id, $vars['range'], 40);
            $st = db()->prepare('SELECT * FROM lan_hosts WHERE device_id=? ORDER BY last_seen DESC, ip LIMIT 1000'); $st->execute([$id]); $vars['hosts'] = $st->fetchAll();
            $st = db()->prepare('SELECT COALESCE(SUM(up_bytes+down_bytes),0) FROM host_5m WHERE device_id=? AND ts > NOW() - INTERVAL 24 HOUR'); $st->execute([$id]); $vars['flowSum'] = (int)$st->fetchColumn();
            $st = db()->prepare('SELECT COALESCE(SUM(rx_bytes+tx_bytes),0) FROM iface_5m WHERE device_id=? AND iface=? AND ts > NOW() - INTERVAL 24 HOUR'); $st->execute([$id, $d['wan_iface']]); $vars['wanSum'] = (int)$st->fetchColumn();
        }
        if ($tab === 'logs') {
            $sql = 'SELECT * FROM logs WHERE device_id=?'; $p = [$id];
            if (q('sev') !== '') { $sql .= ' AND severity=?'; $p[] = q('sev'); }
            if (q('q') !== '') { $sql .= ' AND (message LIKE ? OR topics LIKE ?)'; $p[] = '%' . q('q') . '%'; $p[] = '%' . q('q') . '%'; }
            $st = db()->prepare($sql . ' ORDER BY ts DESC, id DESC LIMIT 500'); $st->execute($p); $vars['logs'] = $st->fetchAll();
        }
        if ($tab === 'overview') $vars['sla30'] = Sla::period($d, time() - 30 * 86400, time());
        if ($tab === 'sla') {
            $ym = preg_match('/^\d{4}-\d{2}$/', q('m')) ? q('m') : date('Y-m');
            [$f, $t] = Sla::monthRange($ym);
            $vars += ['ym' => $ym, 'slaMonth' => Sla::period($d, $f, $t), 'slaDays' => Sla::month($d, $ym), 'sla30' => Sla::period($d, time() - 30 * 86400, time()),
                      'sla365' => Sla::period($d, time() - 365 * 86400, time())];
        }
        if ($tab === 'security') {
            $st = db()->prepare("SELECT TRIM(SUBSTRING(REGEXP_SUBSTR(message, 'from [0-9a-fA-F:.]+'), 6)) src, REGEXP_SUBSTR(message, 'via [a-z-]+') via,
                                        GROUP_CONCAT(DISTINCT TRIM(SUBSTRING(REGEXP_SUBSTR(message, 'for user [^ ]+'), 10)) SEPARATOR ', ') users, COUNT(*) n, MIN(ts) first, MAX(ts) last
                                 FROM logs WHERE device_id=? AND message LIKE 'login failure%' AND ts > NOW() - INTERVAL 30 DAY GROUP BY src, via ORDER BY n DESC LIMIT 100");
            $st->execute([$id]); $vars['fails'] = $st->fetchAll();
            $st = db()->prepare("SELECT ts, message FROM logs WHERE device_id=? AND (message LIKE 'user % logged in%' OR message LIKE 'user % logged out%') ORDER BY ts DESC LIMIT 30");
            $st->execute([$id]); $vars['logins'] = $st->fetchAll();
            $st = db()->prepare("SELECT * FROM alerts WHERE device_id=? AND akey LIKE 'attack:%' ORDER BY started_at DESC LIMIT 30");
            $st->execute([$id]); $vars['attacks'] = $st->fetchAll();
        }
        if ($tab === 'alerts') { $st = db()->prepare('SELECT * FROM alerts WHERE device_id=? ORDER BY started_at DESC LIMIT 200'); $st->execute([$id]); $vars['history'] = $st->fetchAll(); }
        if ($tab === 'settings') {
            $f = rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . "/push/$id.json";
            $vars['rawPush'] = is_file($f) ? ['at' => filemtime($f), 'size' => filesize($f), 'json' => json_encode(json_decode((string)file_get_contents($f)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] : null;
        }
        if ($tab === 'backups') { $st = db()->prepare('SELECT id, created_at, sha1, bytes, added, removed FROM config_backups WHERE device_id=? ORDER BY created_at DESC'); $st->execute([$id]); $vars['backups'] = $st->fetchAll(); }
        view('device', $vars);
    }

    Auth::requireSuper();
    if ($sub === 'install') view('device_install', ['d' => $d, 'an' => json_decode((string)$d['analysis_json'], true) ?: [], 'title' => $d['name'], 'nav' => 'devices']);
    if ($sub === 'package') {
        $files = MikrotikScript::package($d);
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $d['name']) ?: 'naprava'));
        audit('device.package', $d['name']);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="noc-' . $slug . '.zip"');
        echo MikrotikScript::zip($files, 'noc-' . $slug); exit;
    }
    if ($sub === 'save' && $method === 'POST') {
        $f = DeviceForm::read();
        $th = [];
        foreach ($_POST['th'] ?? [] as $k => $v) if (preg_match('/^th_[a-z_]+$/', (string)$k) && is_numeric($v)) $th[$k] = (float)$v;
        db()->prepare('UPDATE devices SET tenant_id=?, name=?, site=?, public_ip=?, wan_iface=?, wan_gateway=?, ping_target=?, lan_networks=?, monitor_ifaces=?, down_ifaces=?, wan_down_mbps=?, wan_up_mbps=?, flow_enabled=?, log_include=?, log_exclude=?, thresholds=?, notes=? WHERE id=?')
            ->execute([(int)post('tenant_id') ?: null, $f['name'], $f['site'], $f['public_ip'], $f['wan_iface'], $f['wan_gateway'], $f['ping_target'], $f['lan_networks'], $f['monitor_ifaces'], $f['down_ifaces'],
                $f['wan_down_mbps'], $f['wan_up_mbps'], $f['flow_enabled'], $f['log_include'], $f['log_exclude'], $th ? json_encode($th) : null, $f['notes'], $id]);
        db()->prepare('UPDATE devices SET newdev_alert=?, newdev_ignore=? WHERE id=?')->execute([post('newdev_alert') ? 1 : 0, implode(',', Devices::list(post('newdev_ignore'))), $id]);
        Ufw::write();
        audit('device.save', $f['name']);
        back("/devices/$id?tab=settings", A('Shranjeno. Če si spremenil prehod, ping cilj, filtre logov ali traffic-flow, prenesi in naloži nov paket.'));
    }
    if ($sub === 'filter' && $method === 'POST') {
        $prof = in_array(post('filter_profile'), ['off', 'basic', 'family'], true) ? post('filter_profile') : 'off';
        $nets = array_values(array_intersect(Devices::list(post('filter_networks_csv')), Devices::list((string)$d['lan_networks'])));
        if (isset($_POST['nets']) && is_array($_POST['nets'])) $nets = array_values(array_intersect(array_map('strval', $_POST['nets']), Devices::list((string)$d['lan_networks'])));
        $doms = implode("\n", Filter::domains(['filter_domains' => post('filter_domains')]));
        // prvotni DNS shranimo ob prvem vklopu (iz zadnjega pusha ali iz analize /export)
        $orig = $d['dns_original'];
        if ($prof !== 'off' && $orig === null) {
            $an = json_decode((string)$d['analysis_json'], true) ?: [];
            $cur = $d['status']['flt']['dns'] ?? null;
            $orig = json_encode(['servers' => $cur !== null && !str_contains((string)$cur, '1.1.1.2') && !str_contains((string)$cur, '1.1.1.3') ? (string)$cur : ($an['dns']['servers'] ?? ''), 'remote' => $an['dns']['remote'] ?? 'yes']);
        }
        db()->prepare('UPDATE devices SET filter_profile=?, filter_force_dns=?, filter_block_doh=?, filter_ip_lists=?, filter_domains=?, filter_networks=?, dns_original=? WHERE id=?')
            ->execute([$prof, post('filter_force_dns') ? 1 : 0, post('filter_block_doh') ? 1 : 0, post('filter_ip_lists') ? 1 : 0, $doms, implode(',', $nets), $orig, $id]);
        if ($prof !== 'off' && post('filter_ip_lists') && !is_file(rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/spamhaus-drop.txt')) Filter::updateDropList();
        audit('device.filter', $d['name'] . ' ' . $prof);
        back("/devices/$id?tab=protect", A('Zaščita shranjena. Na routerju se uveljavi, ko poženeš namestitveni ukaz s strani Paket.'));
    }
    if ($sub === 'key' && $method === 'POST') { Devices::newKey($id); audit('device.key', $d['name']); back("/devices/$id/install", A('Nov ključ ustvarjen – stari ne deluje več. Naloži nov paket na router.')); }
    if ($sub === 'mute' && $method === 'POST') {
        $h = (int)post('hours');
        db()->prepare('UPDATE devices SET mute_until=? WHERE id=?')->execute([$h > 0 ? date('Y-m-d H:i:s', time() + $h * 3600) : null, $id]);
        audit('device.mute', $d['name'] . " $h h");
        back("/devices/$id", $h > 0 ? A('Obvestila utišana za {h} h.', ['h' => $h]) : A('Obvestila spet vklopljena.'));
    }
    if ($sub === 'reanalyze' && $method === 'POST') {
        $rsc = post('export');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) $rsc = (string)file_get_contents($_FILES['file']['tmp_name']);
        if (strlen($rsc) < 50) back("/devices/$id?tab=settings", A('Prilepi ali naloži izpis ukaza /export.'), 'err');
        $an = MikrotikExport::analyze($rsc);
        db()->prepare('UPDATE devices SET export_raw=?, analysis_json=?, model=IF(?<>"",?,model), os_version=IF(?<>"",?,os_version) WHERE id=?')->execute([$rsc, json_encode($an, JSON_UNESCAPED_UNICODE), $an['model'], $an['model'], $an['ros_version'], $an['ros_version'], $id]);
        back("/devices/$id/install", A('Konfiguracija ponovno analizirana.'));
    }
    if ($sub === 'delete' && $method === 'POST') {
        if (post('confirm') !== $d['name']) back("/devices/$id?tab=settings", A('Za brisanje vpiši točno ime naprave.'), 'err');
        db()->prepare('DELETE FROM devices WHERE id=?')->execute([$id]);
        Ufw::write(); audit('device.delete', $d['name']);
        back('/devices', A('Naprava izbrisana.'));
    }
    if ($sub === 'host' && $method === 'POST') {
        db()->prepare('UPDATE lan_hosts SET label=? WHERE device_id=? AND mac=?')->execute([mb_substr(post('label'), 0, 120), $id, normalize_mac(post('mac'))]);
        back("/devices/$id?tab=clients&r=" . urlencode(post('r', '24h')));
    }
    if ($sub === 'backups' && ctype_digit($seg[3] ?? '')) {
        $st = db()->prepare('SELECT * FROM config_backups WHERE id=? AND device_id=?'); $st->execute([(int)$seg[3], $id]);
        $b = $st->fetch() ?: back("/devices/$id?tab=backups", A('Backup ne obstaja.'), 'err');
        if (($seg[4] ?? '') === 'download') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', $d['name']) . '-' . date('Ymd-Hi', strtotime($b['created_at'])) . '.rsc"');
            echo $b['content']; exit;
        }
        $st = db()->prepare('SELECT * FROM config_backups WHERE device_id=? AND created_at < ? ORDER BY created_at DESC LIMIT 1'); $st->execute([$id, $b['created_at']]);
        $prev = $st->fetch() ?: null;
        $hunks = $prev ? Diff::hunks(Diff::ops(explode("\n", Diff::normalize($prev['content'])), explode("\n", Diff::normalize($b['content'])))) : [];
        view('backup', ['d' => $d, 'b' => $b, 'prev' => $prev, 'hunks' => $hunks, 'raw' => q('raw') === '1', 'title' => $d['name'], 'nav' => 'devices']);
    }
    http_response_code(404); exit('404');
}

// ---------------------------------------------------------------- alarmi
if ($path === '/alerts') {
    [$w, $p] = Auth::deviceScope('d');
    $sql = "SELECT a.*, d.name device_name, t.name tenant_name FROM alerts a JOIN devices d ON d.id=a.device_id LEFT JOIN tenants t ON t.id=d.tenant_id WHERE a.ended_at IS NOT NULL AND $w";
    if (q('sev') !== '') { $sql .= ' AND a.severity=?'; $p[] = q('sev'); }
    if (q('d') !== '') { $sql .= ' AND a.device_id=?'; $p[] = (int)q('d'); }
    $st = db()->prepare($sql . ' ORDER BY a.started_at DESC LIMIT 300'); $st->execute($p);
    view('alerts', ['open' => Alerts::openList(), 'history' => $st->fetchAll(), 'devs' => Devices::all(), 'title' => A('Alarmi'), 'nav' => 'alerts', 'autorefresh' => 60]);
}
if (($seg[0] ?? '') === 'alerts' && ctype_digit($seg[1] ?? '') && ($seg[2] ?? '') === 'ack' && $method === 'POST') {
    [$w, $p] = Auth::deviceScope('d');
    $st = db()->prepare("UPDATE alerts a JOIN devices d ON d.id=a.device_id SET a.acked_by=?, a.acked_at=NOW() WHERE a.id=? AND $w");
    $st->execute(array_merge([Auth::$user['id'], (int)$seg[1]], $p));
    back($_SERVER['HTTP_REFERER'] ?? '/alerts', A('Alarm potrjen.'));
}

// ---------------------------------------------------------------- logi
if ($path === '/logs') {
    [$w, $p] = Auth::deviceScope('d');
    $sql = "SELECT l.*, d.name device_name FROM logs l JOIN devices d ON d.id=l.device_id WHERE $w";
    if (q('sev') !== '') { $sql .= ' AND l.severity=?'; $p[] = q('sev'); }
    if (q('d') !== '') { $sql .= ' AND l.device_id=?'; $p[] = (int)q('d'); }
    if (q('q') !== '') { $sql .= ' AND (l.message LIKE ? OR l.topics LIKE ?)'; $p[] = '%' . q('q') . '%'; $p[] = '%' . q('q') . '%'; }
    if (q('from') !== '') { $sql .= ' AND l.ts >= ?'; $p[] = q('from') . ' 00:00:00'; }
    $st = db()->prepare($sql . ' ORDER BY l.ts DESC, l.id DESC LIMIT 1000'); $st->execute($p);
    view('logs', ['logs' => $st->fetchAll(), 'devs' => Devices::all(), 'title' => A('Logi'), 'nav' => 'logs']);
}

// ---------------------------------------------------------------- konfiguracije (vse naprave)
if ($path === '/backups') {
    Auth::requireSuper();
    $rows = db()->query('SELECT d.id, d.name, t.name tenant_name, (SELECT MAX(created_at) FROM config_backups b WHERE b.device_id=d.id) last_at,
                                (SELECT COUNT(*) FROM config_backups b WHERE b.device_id=d.id) n,
                                (SELECT id FROM config_backups b WHERE b.device_id=d.id ORDER BY created_at DESC LIMIT 1) last_id,
                                (SELECT CONCAT(added,"/",removed) FROM config_backups b WHERE b.device_id=d.id ORDER BY created_at DESC LIMIT 1) last_change
                         FROM devices d LEFT JOIN tenants t ON t.id=d.tenant_id WHERE d.active=1 ORDER BY t.name, d.name')->fetchAll();
    $changes = db()->query('SELECT b.id, b.device_id, b.created_at, b.added, b.removed, d.name FROM config_backups b JOIN devices d ON d.id=b.device_id WHERE b.removed + b.added > 0 ORDER BY b.created_at DESC LIMIT 20')->fetchAll();
    view('backups', ['rows' => $rows, 'changes' => $changes, 'title' => A('Konfiguracije'), 'nav' => 'backups']);
}

// ---------------------------------------------------------------- mesečna poročila
if ($path === '/reports') {
    $months = []; for ($i = 0; $i < 13; $i++) $months[] = date('Y-m', strtotime("first day of -$i month"));
    view('reports', ['tenants' => Devices::tenants(), 'months' => $months, 'title' => A('Poročila'), 'nav' => 'reports']);
}
if ($path === '/reports/view') {
    $tid = (int)q('t'); $ym = preg_match('/^\d{4}-\d{2}$/', q('m')) ? q('m') : date('Y-m', strtotime('first day of last month'));
    if (!$isSuper && $tid !== (int)(Auth::$user['tenant_id'] ?? 0)) { http_response_code(403); exit('403'); }
    echo Report::html(Report::build($tid, $ym)); exit;
}
if ($path === '/reports/send' && $method === 'POST') {
    Auth::requireSuper();
    $tid = (int)post('t'); $ym = preg_match('/^\d{4}-\d{2}$/', post('m')) ? post('m') : date('Y-m');
    $to = post('to') === 'me' ? [Auth::$user['email']] : null;
    $n = Report::send($tid, $ym, $to);
    audit('report.send', "$tid $ym → $n");
    back('/reports', $n ? A('Poročilo poslano na {n} naslov(ov).', ['n' => $n]) : A('Poročilo ni bilo poslano – preveri e-naslove naročnika in SMTP.'), $n ? 'ok' : 'err');
}

// ---------------------------------------------------------------- posodobitve
if ($path === '/updates') {
    Auth::requireSuper();
    view('updates', ['devs' => Devices::all(), 'title' => A('Posodobitve'), 'nav' => 'updates']);
}

// ---------------------------------------------------------------- naročniki
if ($path === '/tenants') {
    Auth::requireSuper();
    $edit = null;
    if (q('id') !== '') { $st = db()->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([(int)q('id')]); $edit = $st->fetch() ?: null; }
    view('tenants', ['tenants' => Devices::tenants(), 'edit' => $edit, 'title' => A('Naročniki'), 'nav' => 'tenants']);
}
if ($path === '/tenants/save' && $method === 'POST') {
    Auth::requireSuper();
    if (post('name') === '') back('/tenants', A('Ime je obvezno.'), 'err');
    $emails = implode(', ', array_filter(array_map('trim', preg_split('/[\s,;]+/', post('report_emails'))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $rep = post('report_enabled') && $emails !== '' ? 1 : 0;
    if ((int)post('id')) db()->prepare('UPDATE tenants SET name=?, contact=?, notes=?, report_enabled=?, report_emails=? WHERE id=?')->execute([post('name'), post('contact'), post('notes'), $rep, $emails, (int)post('id')]);
    else db()->prepare('INSERT INTO tenants (name, contact, notes, report_enabled, report_emails) VALUES (?,?,?,?,?)')->execute([post('name'), post('contact'), post('notes'), $rep, $emails]);
    audit('tenant.save', post('name'));
    back('/tenants', A('Shranjeno.'));
}
if ($path === '/tenants/delete' && $method === 'POST') {
    Auth::requireSuper();
    $st = db()->prepare('SELECT COUNT(*) FROM devices WHERE tenant_id=?'); $st->execute([(int)post('id')]);
    if ($st->fetchColumn() > 0) back('/tenants', A('Naročnik ima naprave – najprej jih premakni ali izbriši.'), 'err');
    db()->prepare('DELETE FROM tenants WHERE id=?')->execute([(int)post('id')]);
    audit('tenant.delete', post('id'));
    back('/tenants', A('Naročnik izbrisan.'));
}

// ---------------------------------------------------------------- uporabniki
if ($path === '/users') {
    Auth::requireSuper();
    $edit = null;
    if (q('id') !== '') { $st = db()->prepare('SELECT * FROM users WHERE id=?'); $st->execute([(int)q('id')]); $edit = $st->fetch() ?: null; }
    $users = db()->query('SELECT u.*, t.name tenant_name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id ORDER BY u.role DESC, u.name')->fetchAll();
    view('users', ['users' => $users, 'edit' => $edit, 'tenants' => Devices::tenants(), 'title' => A('Uporabniki'), 'nav' => 'users']);
}
if ($path === '/users/save' && $method === 'POST') {
    Auth::requireSuper();
    $uid = (int)post('id'); $email = mb_strtolower(post('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) back('/users' . ($uid ? "?id=$uid" : ''), A('Neveljaven e-naslov.'), 'err');
    $role = $uid === (int)Auth::$user['id'] ? 'superadmin' : (post('role') === 'superadmin' ? 'superadmin' : 'viewer');
    $tenant = $role === 'viewer' ? ((int)post('tenant_id') ?: null) : null;
    if ($role === 'viewer' && !$tenant) back('/users' . ($uid ? "?id=$uid" : ''), A('Bralni uporabnik mora imeti naročnika.'), 'err');
    $lang = post('lang') === 'en' ? 'en' : 'sl';
    $active = $uid === (int)Auth::$user['id'] ? 1 : (post('active') ? 1 : 0);
    try {
        if ($uid) {
            db()->prepare('UPDATE users SET email=?, name=?, role=?, tenant_id=?, lang=?, active=? WHERE id=?')->execute([$email, post('name'), $role, $tenant, $lang, $active, $uid]);
            if (post('password') !== '') db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), $uid]);
        } else {
            if (strlen(post('password')) < 10) back('/users', A('Geslo mora imeti vsaj 10 znakov.'), 'err');
            db()->prepare('INSERT INTO users (email, name, pass_hash, role, tenant_id, lang, active) VALUES (?,?,?,?,?,?,?)')->execute([$email, post('name'), password_hash(post('password'), PASSWORD_DEFAULT), $role, $tenant, $lang, $active]);
        }
    } catch (PDOException $e) { back('/users', A('E-naslov je že v uporabi.'), 'err'); }
    audit('user.save', $email);
    back('/users', A('Shranjeno.'));
}
if ($path === '/users/delete' && $method === 'POST') {
    Auth::requireSuper();
    if ((int)post('id') === (int)Auth::$user['id']) back('/users', A('Samega sebe ne moreš izbrisati.'), 'err');
    db()->prepare('DELETE FROM users WHERE id=?')->execute([(int)post('id')]);
    audit('user.delete', post('id'));
    back('/users', A('Uporabnik izbrisan.'));
}

// ---------------------------------------------------------------- nastavitve
if ($path === '/settings') {
    Auth::requireSuper();
    if ($method === 'POST') {
        foreach (['th_cpu', 'th_cpu_min', 'th_temp', 'th_mem', 'th_hdd', 'th_gw_loss', 'th_gw_ms', 'th_ext_loss', 'th_ext_ms', 'th_ping_min', 'th_offline_min', 'th_host_gb_h', 'th_host_mbps', 'th_host_min', 'th_attack', 'th_attack_min', 'th_pool', 'th_backup_days'] as $k)
            if (isset($_POST[$k]) && is_numeric($_POST[$k])) setting_set($k, (string)(float)$_POST[$k]);
        setting_set('notify_emails', implode(', ', array_filter(array_map('trim', preg_split('/[\s,;]+/', post('notify_emails'))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
        foreach (['notify_mail_min', 'notify_tg_min'] as $k) if (in_array(post($k), ['info', 'warning', 'critical', 'off'], true)) setting_set($k, post($k));
        setting_set('notify_resolved', post('notify_resolved') ? '1' : '0');
        if (post('tg_token') !== '') setting_set('tg_token_enc', enc(post('tg_token')));
        if (post('tg_token_clear')) setting_set('tg_token_enc', '');
        setting_set('tg_chat_id', preg_replace('/[^0-9\-]/', '', post('tg_chat_id')));
        audit('settings.save');
        back('/settings', A('Nastavitve shranjene.'));
    }
    view('settings', ['title' => A('Nastavitve'), 'nav' => 'settings', 'chats' => $_SESSION['tg_chats'] ?? null]);
}
if ($path === '/settings/test' && $method === 'POST') {
    Auth::requireSuper();
    if (post('what') === 'tg') {
        $r = Notify::telegram('✅ <b>NOC</b> – ' . A('testno sporočilo') . ' (' . date('H:i:s') . ')');
        back('/settings', !empty($r['ok']) ? A('Telegram sporočilo poslano.') : A('Telegram napaka: {e}', ['e' => $r['description'] ?? '?']), !empty($r['ok']) ? 'ok' : 'err');
    }
    if (post('what') === 'tg_hook_on') {
        $r = TgBot::enable(); audit('telegram.webhook', 'on');
        back('/settings#telegram', !empty($r['ok']) ? A('Ukazi so vklopljeni – v Telegramu pošlji botu /pomoc.') : A('Telegram napaka: {e}', ['e' => $r['description'] ?? '?']), !empty($r['ok']) ? 'ok' : 'err');
    }
    if (post('what') === 'tg_hook_off') { TgBot::disable(); audit('telegram.webhook', 'off'); back('/settings#telegram', A('Ukazi so izklopljeni.')); }
    if (post('what') === 'tg_find') {
        if (setting('tg_webhook') === '1') back('/settings#telegram', A('Iskanje chat ID ne deluje, ko so ukazi vklopljeni – najprej jih izklopi.'), 'err');
        $tok = dec(setting('tg_token_enc', ''));
        if ($tok === '') back('/settings', A('Najprej shrani token bota.'), 'err');
        $_SESSION['tg_chats'] = Notify::tgFindChats($tok);
        back('/settings#telegram', $_SESSION['tg_chats'] ? A('Najdeni pogovori so spodaj – izberi svojega.') : A('Ni sporočil – v Telegramu pošlji botu poljubno sporočilo in poskusi znova.'), $_SESSION['tg_chats'] ? 'ok' : 'err');
    }
    if (post('what') === 'mail') {
        $to = Notify::recipients();
        if (!$to) back('/settings', A('Vpiši vsaj en e-naslov za obvestila.'), 'err');
        $ok = Notify::mail($to[0], 'NOC – ' . A('testno sporočilo'), '<p>' . A('Obvestila po e-pošti delujejo.') . '</p>');
        back('/settings', $ok ? A('Testni e-mail poslan na {e}.', ['e' => $to[0]]) : A('Pošiljanje ni uspelo – preveri SMTP v config.php in error log.'), $ok ? 'ok' : 'err');
    }
    redirect('/settings');
}

// ---------------------------------------------------------------- sistem
if ($path === '/system') {
    Auth::requireSuper();
    $crons = []; foreach (db()->query('SELECT * FROM cron_runs') as $r) $crons[$r['name']] = $r;
    $dbSize = db()->query('SELECT table_name t, ROUND((data_length+index_length)/1048576,1) mb, table_rows r FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY (data_length+index_length) DESC')->fetchAll();
    $flowDir = (string)cfg('flow_dir');
    $flowFiles = is_dir($flowDir) ? (glob($flowDir . '/nfcapd.*') ?: []) : [];
    $newest = $flowFiles ? max(array_map('filemtime', $flowFiles)) : null;
    $nfcapd = trim((string)@shell_exec('pgrep -x nfcapd'));
    $disk = ['free' => @disk_free_space('/'), 'total' => @disk_total_space('/')];
    $audit = db()->query('SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.at DESC LIMIT 40')->fetchAll();
    $bk = @glob('/var/backups/noc/db-*.sql.gz') ?: [];
    $srvBackup = $bk ? max(array_map('filemtime', $bk)) : null;
    view('system', compact('crons', 'dbSize', 'flowFiles', 'newest', 'nfcapd', 'disk', 'audit', 'srvBackup') + ['title' => A('Sistem'), 'nav' => 'system', 'flowIps' => Ufw::ips()]);
}

// ---------------------------------------------------------------- moj račun
if ($path === '/account/2fa' && $method === 'POST') {
    $act = post('act');
    if ($act === 'start') { $_SESSION['2fa_new'] = Totp::secret(); redirect('/account#tfa'); }
    if ($act === 'confirm') {
        $sec = (string)($_SESSION['2fa_new'] ?? '');
        $step = $sec ? Totp::verify($sec, post('code')) : null;
        if ($step === null) back('/account#tfa', A('Koda ni pravilna – preveri uro na telefonu in poskusi znova.'), 'err');
        db()->prepare('UPDATE users SET totp_secret=?, totp_last=? WHERE id=?')->execute([enc($sec), $step, Auth::$user['id']]);
        unset($_SESSION['2fa_new']); audit('2fa.on');
        back('/account', A('Dvostopenjska prijava je vklopljena.'));
    }
    if ($act === 'cancel') { unset($_SESSION['2fa_new']); redirect('/account'); }
    if ($act === 'off') {
        $u = Auth::$user;
        if (!password_verify(post('current'), $u['pass_hash']) || Totp::verify(dec($u['totp_secret']), post('code')) === null) back('/account#tfa', A('Geslo ali koda ni pravilna.'), 'err');
        db()->prepare('UPDATE users SET totp_secret=NULL, totp_last=NULL WHERE id=?')->execute([$u['id']]);
        audit('2fa.off'); back('/account', A('Dvostopenjska prijava je izklopljena.'));
    }
    redirect('/account');
}
if ($path === '/users/2fa-reset' && $method === 'POST') {
    Auth::requireSuper();
    if ((int)post('id') === (int)Auth::$user['id']) back('/users', A('Svojo 2FA izklopiš v Moj račun.'), 'err');
    db()->prepare('UPDATE users SET totp_secret=NULL, totp_last=NULL WHERE id=?')->execute([(int)post('id')]);
    audit('2fa.reset', post('id'));
    back('/users?id=' . (int)post('id'), A('2FA za uporabnika je ponastavljena – ob naslednji prijavi jo lahko vklopi znova.'));
}
if ($path === '/account') {
    if ($method === 'POST') {
        if (!password_verify(post('current'), Auth::$user['pass_hash'])) back('/account', A('Trenutno geslo ni pravilno.'), 'err');
        if (strlen(post('password')) < 10) back('/account', A('Geslo mora imeti vsaj 10 znakov.'), 'err');
        db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), Auth::$user['id']]);
        audit('account.password');
        back('/account', A('Geslo spremenjeno.'));
    }
    view('account', ['title' => A('Moj račun'), 'nav' => 'account', 'newSecret' => $_SESSION['2fa_new'] ?? null]);
}

http_response_code(404);
view('message', ['title' => '404', 'text' => A('Strani ni mogoče najti.'), 'nav' => '']);
