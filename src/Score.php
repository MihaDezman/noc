<?php
declare(strict_types=1);

/**
 * Varnostna ocena routerja (0–100) z razlogi in potjo do popravka.
 * Sestavljena iz: požarni zid in storitve (Firewall::audit), posodobitve RouterOS (varnostni popravki),
 * backup, privzeti uporabnik admin, neaktivni VPN uporabniki, odprte AI ugotovitve, stik z zlonamernimi strežniki, preverjanje certifikata.
 */
final class Score
{
    /** ['score' => int|null, 'items' => [[sev, besedilo, povezava, točke]]] */
    public static function compute(array $d): array
    {
        $id = (int)$d['id']; $items = []; $score = 100.0;
        $add = function (string $sev, string $txt, string $link, float $pts) use (&$items, &$score) { $items[] = [$sev, $txt, $link, $pts]; $score -= $pts; };

        // 1. požarni zid in storitve: do 40 točk
        $fw = Firewall::audit($d);
        if ($fw['score'] === null) $add('info', A('Ni izvoza konfiguracije – požarnega zidu ni mogoče oceniti'), "/devices/$id?tab=backups", 10);
        else {
            $crit = count(array_filter($fw['findings'], fn($f) => $f['sev'] === 'critical')); $warn = count(array_filter($fw['findings'], fn($f) => $f['sev'] === 'warning'));
            if ($crit || $warn) $add($crit ? 'critical' : 'warning', A('Požarni zid in storitve: {c} kritičnih, {w} opozoril', ['c' => $crit, 'w' => $warn]), "/devices/$id?tab=protect", min(40, $crit * 12 + $warn * 3));
        }
        // 2. RouterOS
        $ver = Ros::ver((string)$d['os_version']); $latest = Ros::latest();
        if ($ver !== '' && $latest !== '' && version_compare($ver, $latest, '<')) {
            $sec = Ros::securityFixes($ver);
            if ($sec) $add('critical', A('RouterOS {v}: novejše verzije vsebujejo varnostne popravke ({n}), zadnja je {l}', ['v' => $ver, 'n' => count($sec), 'l' => $latest]), "/devices/$id#ros", 20);
            else $add('info', A('Na voljo je RouterOS {l} (nameščen {v})', ['l' => $latest, 'v' => $ver]), "/devices/$id#ros", 3);
        }
        // 3. backup
        $st = db()->prepare('SELECT MAX(created_at) FROM config_backups WHERE device_id=?'); $st->execute([$id]); $bk = $st->fetchColumn();
        $maxDays = max(1, (int)(Devices::thresholds($d)['th_backup_days'] ?? 2));
        if (!$bk || strtotime((string)$bk) < time() - ($maxDays + 1) * 86400) $add('warning', $bk ? A('Zadnji backup konfiguracije je star {t}', ['t' => fmt_ago((string)$bk)]) : A('Ni backupa konfiguracije'), "/devices/$id?tab=backups", 10);
        // 4. uporabniki routerja
        $s = json_decode((string)($d['status_json'] ?? ''), true) ?: [];
        if (is_array($s['usr'] ?? null)) {
            foreach ($s['usr'] as $u) if (is_array($u) && ($u['n'] ?? '') === 'admin' && empty($u['dis'])) { $add('warning', A('Vklopljen privzeti uporabnik "admin" – napadalci ga poskušajo prvega. Ustvari svojega uporabnika (skupina full) in admina izklopi: /user disable admin'), '', 8); break; }
            $full = array_filter($s['usr'], fn($u) => is_array($u) && ($u['g'] ?? '') === 'full' && empty($u['dis']) && ($u['n'] ?? '') !== Term::USER);   // noc-term (terminal NOC) ne šteje
            if (count($full) > 3) $add('info', A('{n} uporabnikov s polnimi pravicami', ['n' => count($full)]), '', 2);
        }
        // 5. WireGuard
        $in = count(Wg::inactive(90, $id));
        if ($in) $add('warning', A('{n} VPN uporabnikov se ni povezalo več kot 90 dni, a so še vklopljeni', ['n' => $in]), "/devices/$id?tab=wg#wg-inactive", min(10, 2 * $in));
        // 6. odprte AI ugotovitve
        $st = db()->prepare("SELECT findings FROM ai_reviews WHERE device_id=? AND status='done' ORDER BY id DESC LIMIT 1"); $st->execute([$id]);
        $ai = array_filter((array)json_decode((string)$st->fetchColumn(), true), fn($f) => is_array($f) && ($f['state'] ?? 'open') === 'open' && ($f['severity'] ?? '') === 'critical');
        if ($ai) $add('warning', A('AI pregled: {n} odprtih kritičnih ugotovitev', ['n' => count($ai)]), "/devices/$id?tab=protect#ai-review", min(15, 5 * count($ai)));
        // 7. stik z zlonamernimi strežniki (7 dni)
        $st = db()->prepare("SELECT COUNT(*) FROM alerts WHERE device_id=? AND (akey LIKE 'threat:%' OR akey LIKE 'outbl:%') AND started_at > NOW() - INTERVAL 7 DAY"); $st->execute([$id]);
        if ($n = (int)$st->fetchColumn()) $add('critical', A('Naprave v LAN-u so v zadnjih 7 dneh {n}× komunicirale z zlonamernimi strežniki', ['n' => $n]), "/devices/$id?tab=alerts", 15);
        // 8. preverjanje certifikata NOC
        if (!(int)($d['tls_check'] ?? 0)) $add('info', A('Router ne preverja certifikata NOC'), "/devices/$id?tab=protect#tls", 2);

        usort($items, fn($a, $b) => $b[3] <=> $a[3]);
        return ['score' => (int)max(0, min(100, round($score))), 'items' => $items];
    }

    /** Izračun za vse naprave (cron in ob ogledu nadzorne plošče, če je star) */
    public static function refreshAll(): string
    {
        $n = 0;
        foreach (db()->query('SELECT id FROM devices WHERE active=1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $d = Devices::full((int)$id); if (!$d) continue;
            $r = self::compute(Devices::decorate($d));
            db()->prepare('UPDATE devices SET sec_score=?, sec_at=NOW() WHERE id=?')->execute([$r['score'], $id]); $n++;
        }
        return "ocene: $n";
    }

    public static function cls(?int $s): string { return $s === null ? '' : ($s >= 80 ? 'up' : ($s >= 60 ? 'warn' : 'down')); }
}
