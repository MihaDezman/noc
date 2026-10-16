<?php
declare(strict_types=1);

/**
 * Ukrepi z enim klikom: blokada zunanjega IP-ja na routerju (raw drop prek seznama noc-block, s časovno omejitvijo).
 * Ukrep vedno potrdi skrbnik (gumb v Telegramu ali v NOC). Router ga prevzame ob naslednjem pushu (odgovor "act":1
 * -> prenese /api/action in uvozi), nato potrdi izvedbo. Blokirati ni mogoče zasebnih, upravljalnih in NOC naslovov.
 */
final class Actions
{
    /** Ali se IP sme blokirati na tej napravi; vrne razlog zavrnitve ali '' */
    public static function refuse(array $d, string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return A('ni veljaven IPv4 naslov');
        // samo javni naslovi (brez zasebnih, CGNAT 100.64/10, dokumentacijskih, multicast ...)
        $global = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!filter_var($ip, FILTER_VALIDATE_IP, $global) || ip_in_cidr($ip, '224.0.0.0/3') || ip_in_cidr($ip, '100.64.0.0/10')) return A('zasebnega ali rezerviranega naslova ni mogoče blokirati');
        if (in_array($ip, [(string)cfg('noc_ip'), $d['wan_gateway'] ?? '', $d['ping_target'] ?? ''], true)) return A('naslov NOC, routerja ali prehoda');
        // javni naslovi vseh naprav v NOC (VPN med lokacijami strank ne sme izpasti)
        $q = db()->prepare('SELECT COUNT(*) FROM devices WHERE public_ip=? OR last_seen_ip=?'); $q->execute([$ip, $ip]);
        if ((int)$q->fetchColumn()) return A('naslov NOC, routerja ali prehoda');
        foreach (Firewall::mgmtIps() as $m) if (str_contains($m, '/') ? ip_in_cidr($ip, $m) : $m === $ip) return A('upravljalni naslov');
        return '';
    }

    /** Nov ukrep; vrne id ali sporočilo napake (string) */
    public static function create(array $d, string $kind, string $ip, int $hours, string $status, string $reason, string $source, ?int $alertId = null, string $by = '', array $data = []): int|string
    {
        $ip = trim($ip);
        if ($kind === 'upgrade' && !preg_match('/^7\.\d+(\.\d+)?$/', $ip)) return A('neveljavna verzija');
        if ($kind === 'upgrade') {   // ena načrtovana posodobitev naenkrat
            $st = db()->prepare("SELECT id FROM noc_actions WHERE device_id=? AND kind='upgrade' AND (status IN ('approved','sent') OR (status='done' AND verified_at IS NULL AND done_at > NOW() - INTERVAL 26 HOUR)) LIMIT 1"); $st->execute([$d['id']]);
            if ($x = $st->fetchColumn()) return (int)$x;
        }
        if ($kind === 'block' && ($why = self::refuse($d, $ip)) !== '') return $why;
        if ($kind === 'unblock' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return A('ni veljaven IPv4 naslov');
        $hours = max(1, min(720, $hours)); $by = mb_substr($by, 0, 60);
        // isti predlog za isti IP na isti napravi, ki še čaka, se ne podvaja; potrditev obstoječi predlog potrdi
        $st = db()->prepare("SELECT id, status FROM noc_actions WHERE device_id=? AND kind=? AND ip=? AND status IN ('proposed','approved','sent') LIMIT 1"); $st->execute([$d['id'], $kind, $ip]);
        if ($old = $st->fetch()) { if ($old['status'] === 'proposed' && $status === 'approved') self::decide((int)$old['id'], true, $by); return (int)$old['id']; }
        // odblokiranje prekliče blokade istega naslova, ki jih router še ni izvedel
        if ($kind === 'unblock') db()->prepare("UPDATE noc_actions SET status='cancelled' WHERE device_id=? AND kind='block' AND ip=? AND status IN ('proposed','approved')")->execute([$d['id'], $ip]);
        db()->prepare('INSERT INTO noc_actions (device_id, kind, ip, hours, status, reason, source, alert_id, decided_at, decided_by, data) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$d['id'], $kind, $ip, $hours, $status, mb_substr($reason, 0, 300), $source, $alertId, $status === 'approved' ? date('Y-m-d H:i:s') : null, $status === 'approved' ? $by : '', $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
        $id = (int)db()->lastInsertId();
        if ($status === 'approved') db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')->execute([Auth::$user['id'] ?? null, 'action.approve', mb_substr("#$id {$d['name']} $kind $ip {$hours}h ($by)", 0, 500), client_ip() ?: 'telegram']);
        return $id;
    }

    /** Potrditev ali zavrnitev predloga (Telegram ali splet) */
    public static function decide(int $id, bool $ok, string $by): ?array
    {
        $st = db()->prepare("SELECT a.*, d.name device_name FROM noc_actions a JOIN devices d ON d.id=a.device_id WHERE a.id=?"); $st->execute([$id]);
        $a = $st->fetch(); if (!$a) return null;
        $a['changed'] = false;
        if ($a['status'] !== 'proposed') return $a;   // že odločeno
        db()->prepare("UPDATE noc_actions SET status=?, decided_at=NOW(), decided_by=? WHERE id=? AND status='proposed'")->execute([$ok ? 'approved' : 'rejected', mb_substr($by, 0, 60), $id]);
        db()->prepare('INSERT INTO audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')->execute([Auth::$user['id'] ?? null, $ok ? 'action.approve' : 'action.reject', mb_substr("#$id {$a['device_name']} {$a['kind']} {$a['ip']} ($by)", 0, 500), client_ip() ?: 'telegram']);
        $a['status'] = $ok ? 'approved' : 'rejected'; $a['changed'] = true;
        return $a;
    }

    /** Ali ima router potrjene ukrepe, ki jih še ni prevzel (za odgovor na push) */
    public static function pending(int $deviceId): bool
    {
        // meritev hitrosti brez rezultata v 10 min ne ponavljamo (obremeni linijo) – neuspešna
        db()->prepare("UPDATE noc_actions SET status='failed' WHERE device_id=? AND kind='speed' AND status='sent' AND sent_at < NOW() - INTERVAL 10 MINUTE")->execute([$deviceId]);
        // "sent" brez potrditve 10 min = ponovi (uvoz je idempotenten), po 3 poskusih neuspešno
        db()->prepare("UPDATE noc_actions SET status='failed' WHERE device_id=? AND status='sent' AND tries >= 3 AND sent_at < NOW() - INTERVAL 10 MINUTE")->execute([$deviceId]);
        $st = db()->prepare("SELECT 1 FROM noc_actions WHERE device_id=? AND (status='approved' OR (status='sent' AND sent_at < NOW() - INTERVAL 10 MINUTE)) LIMIT 1"); $st->execute([$deviceId]);
        return (bool)$st->fetchColumn();
    }

    /** .rsc s potrjenimi ukrepi za router (/api/action) */
    public static function rsc(array $d): string
    {
        $st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND (status='approved' OR (status='sent' AND sent_at < NOW() - INTERVAL 10 MINUTE)) ORDER BY id"); $st->execute([$d['id']]);
        $rows = $st->fetchAll();
        $base = rtrim((string)cfg('base_url'), '/'); $key = dec($d['api_key_enc'] ?? null);
        $chk = (int)($d['tls_check'] ?? 0) ? ' check-certificate=yes-without-crl' : '';
        $s = "# NOC ukrepi za " . ros_cmt((string)$d['name']) . "\n";
        if ($rows && array_filter($rows, fn($r) => $r['kind'] === 'block')) {
            // pravilo raw na vrh: zavrže promet z naslovov na seznamu noc-block (pred vsem ostalim, tudi pred conntrack)
            $add = '/ip firewall raw add chain=prerouting src-address-list=noc-block action=drop comment="noc-block: blokirani naslovi (NOC)"';
            $s .= ":if ([:len [/ip firewall raw find where comment~\"^noc-block\"]] = 0) do={ :do { $add place-before=([/ip firewall raw find where dynamic=no]->0) } on-error={ $add } }\n";
        }
        foreach ($rows as $r) {
            if ($r['kind'] === 'upgrade') { $s .= Ros::upgradeRsc((int)$r['id'], $base, $key, $chk); continue; }
            if ($r['kind'] === 'speed') { $s .= Speed::rsc($d, (int)$r['id'], (string)$r['ip'], $base, $key, $chk); continue; }
            if (in_array($r['kind'], ['term', 'wgsrv', 'wgdel'], true)) {   // nastavitve: terminal NOC, WireGuard strežnik
                $s .= ($r['kind'] === 'term' ? Term::rsc($d) : WgServer::rsc($d, $r))
                    . ":do { /tool fetch url=\"$base/api/action-done/{$r['id']}\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}\n";
                continue;
            }
            if (!filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $ip = $r['ip']; $id = (int)$r['id'];
            $s .= ":do { /ip firewall address-list remove [find list=noc-block address=$ip] } on-error={}\n";
            if ($r['kind'] === 'block') {
                // ponovno pošiljanje: samo preostanek časa od potrditve (blokada se ne podaljšuje)
                $left = max(60, (int)$r['hours'] * 3600 - max(0, time() - strtotime((string)($r['decided_at'] ?: $r['created_at']))));
                $tmo = $left . 's';   // sekunde – RouterOS jih sprejme kot časovni interval
                $s .= "/ip firewall address-list add list=noc-block address=$ip timeout=$tmo comment=\"noc: ukrep #$id\"\n"
                    . ":log info \"noc: ukrep #$id - $ip blokiran za {$r['hours']} h\"\n";
            } else {
                $s .= ":log info \"noc: ukrep #$id - $ip odblokiran\"\n";
            }
            $s .= ":do { /tool fetch url=\"$base/api/action-done/$id\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}\n";
        }
        if ($rows) db()->prepare('UPDATE noc_actions SET status=\'sent\', sent_at=NOW(), tries=tries+1 WHERE id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')')->execute();
        return $s;
    }

    /** Router potrdi izvedbo */
    public static function done(array $d, int $id, bool $fail = false): void
    {
        $st = db()->prepare("UPDATE noc_actions SET status=?, done_at=NOW() WHERE id=? AND device_id=? AND status='sent'"); $st->execute([$fail ? 'failed' : 'done', $id, $d['id']]);
        if (!$st->rowCount()) return;
        $q = db()->prepare('SELECT kind, ip, hours, data FROM noc_actions WHERE id=?'); $q->execute([$id]); $a = $q->fetch();
        if ($a && $a['kind'] === 'term') return;   // stanje se pokaže pri napravi (uporabnik noc-term iz pusha)
        if ($a && in_array($a['kind'], ['wgsrv', 'wgdel'], true)) {
            if ($fail) return;
            $p = json_decode((string)$a['data'], true) ?: [];
            if ($a['kind'] === 'wgdel') db()->prepare('DELETE FROM wg_peers WHERE device_id=? AND iface=?')->execute([$d['id'], $a['ip']]);   // peerji so na routerju odstranjeni skupaj z vmesnikom
            Notify::telegram(($a['kind'] === 'wgsrv' ? '🔐' : '🗑') . ' <b>' . htmlspecialchars($d['name']) . '</b> – ' . ($a['kind'] === 'wgsrv'
                ? A('WireGuard strežnik {n} nastavljen ({a}, port {p}) – zdaj lahko dodajaš uporabnike', ['n' => $a['ip'], 'a' => $p['addr'] ?? '', 'p' => $p['port'] ?? ''])
                : A('WireGuard strežnik {n} odstranjen', ['n' => $a['ip']])));
            return;
        }
        if ($a && $a['kind'] === 'upgrade') {
            Notify::telegram($fail
                ? '⚠️ <b>' . htmlspecialchars($d['name']) . '</b> – ' . A('router ni dovolil načrtovane posodobitve iz skripte NOC (pravica reboot). Posodobi ročno: v terminalu /system package update install ali Winbox → System → Packages → Check For Updates.')
                : '📅 <b>' . htmlspecialchars($d['name']) . '</b> – ' . A('posodobitev na RouterOS {v} je načrtovana ob 03:30', ['v' => $a['ip']]));
            return;
        }
        if ($fail) return;
        if ($a) Notify::telegram('✅ <b>' . htmlspecialchars($d['name']) . '</b> – ' . ($a['kind'] === 'block' ? A('{ip} blokiran za {h} h', ['ip' => $a['ip'], 'h' => $a['hours']]) : A('{ip} odblokiran', ['ip' => $a['ip']])));
    }

    /** Po pushu: ali je načrtovana posodobitev uspela (nova verzija) ali ne (po 26 h) */
    public static function verifyUpgrade(array $d): void
    {
        $st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND kind='upgrade' AND status IN ('done','sent','failed') AND verified_at IS NULL AND created_at > NOW() - INTERVAL 3 DAY ORDER BY id DESC LIMIT 1"); $st->execute([$d['id']]);
        if (!($a = $st->fetch())) return;
        $q = db()->prepare('SELECT os_version FROM devices WHERE id=?'); $q->execute([$d['id']]);
        $now = Ros::ver((string)$q->fetchColumn());
        if ($now !== '' && version_compare($now, $a['ip'], '>=')) {
            db()->prepare('UPDATE noc_actions SET verified_at=NOW() WHERE id=?')->execute([$a['id']]);
            Notify::telegram('✅ <b>' . htmlspecialchars($d['name']) . '</b> – ' . A('posodobljen na RouterOS {v}', ['v' => $now]));
        } elseif ($a['status'] === 'done' && strtotime((string)$a['done_at']) < time() - 26 * 3600) {
            db()->prepare("UPDATE noc_actions SET status='failed', verified_at=NOW() WHERE id=?")->execute([$a['id']]);
            Notify::telegram('⚠️ <b>' . htmlspecialchars($d['name']) . '</b> – ' . A('načrtovana posodobitev na {v} se ni izvedla (verzija je še {n})', ['v' => $a['ip'], 'n' => $now ?: '?']));
        }
    }

    /** Gumbi za predlog v Telegramu */
    public static function buttons(int $id, string $ip = ''): array
    {
        return [[['text' => '✅ ' . A('Izvedi') . ($ip !== '' ? ' ' . $ip : ''), 'callback_data' => 'act:' . $id . ':1'], ['text' => '✖ ' . A('Zavrni'), 'callback_data' => 'act:' . $id . ':0']]];
    }

    /** Ukrepi naprave za prikaz (zavihek Varnost) */
    public static function forDevice(int $deviceId, int $limit = 30): array
    {
        $st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND kind IN ('block','unblock','upgrade') ORDER BY id DESC LIMIT " . (int)$limit); $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    /** Ali je blokada še aktivna (izvedena in čas še ni potekel, ni bila odblokirana) */
    public static function active(array $a): bool
    {
        return $a['kind'] === 'block' && $a['status'] === 'done' && $a['done_at'] && strtotime($a['done_at']) + (int)$a['hours'] * 3600 > time();
    }

    public static function statusLabel(string $s): array
    {
        return ['proposed' => ['warn', A('čaka na potrditev')], 'approved' => ['info', A('potrjen – čaka na router')], 'sent' => ['info', A('poslan routerju')],
                'done' => ['up', A('izveden')], 'rejected' => ['', A('zavrnjen')], 'failed' => ['down', A('ni uspel')], 'cancelled' => ['', A('preklican')]][$s] ?? ['', $s];
    }
}
