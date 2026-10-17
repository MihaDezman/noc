<?php
declare(strict_types=1);

/**
 * Incidenti: povezani alarmi ene naprave združeni v en dogodek s časovnico.
 *  - kritičen alarm (izpad, WAN, ...) odpre incident ali se pridruži odprtemu
 *  - 3+ opozorila v 10 minutah na isti napravi odprejo incident (več težav hkrati)
 *  - opozorila in dogodki (ponovni zagon, sprememba) med odprtim incidentom se mu pridružijo
 *  - incident se zapre, ko so vsi njegovi alarmi zaprti vsaj 5 minut; AI nato napiše povzetek (Ai::incidents)
 * Teče v cron/minute.php, deluje tudi brez AI.
 */
final class Incidents
{
    public static function sync(): string
    {
        $pdo = db(); $new = 0; $closed = 0;
        // 1. kritični alarmi brez incidenta
        foreach ($pdo->query("SELECT * FROM alerts WHERE incident_id IS NULL AND ended_at IS NULL AND severity='critical' ORDER BY started_at")->fetchAll() as $a) {
            $iid = self::openFor((int)$a['device_id']);
            if (!$iid) { $iid = self::create((int)$a['device_id'], $a['started_at'], 'critical', $a['message']); $new++; }
            else $pdo->prepare("UPDATE incidents SET title=IF(severity='critical', title, ?), severity='critical' WHERE id=?")->execute([mb_substr($a['message'], 0, 200), $iid]);
            $pdo->prepare('UPDATE alerts SET incident_id=? WHERE id=?')->execute([$iid, $a['id']]);
        }
        // 2. več opozoril hkrati na isti napravi
        $q = $pdo->query("SELECT device_id, MIN(started_at) s, COUNT(*) n, GROUP_CONCAT(id) ids FROM alerts
                          WHERE incident_id IS NULL AND ended_at IS NULL AND severity='warning' AND akey NOT IN ('update','backup','hdd','mem','pool','newdev','config','cfgrisk')
                          AND started_at > NOW() - INTERVAL 10 MINUTE GROUP BY device_id HAVING n >= 3");
        foreach ($q->fetchAll() as $g) {
            $iid = self::openFor((int)$g['device_id']);
            if (!$iid) {
                $st = $pdo->prepare('SELECT message FROM alerts WHERE id=?'); $st->execute([(int)explode(',', $g['ids'])[0]]);
                $iid = self::create((int)$g['device_id'], $g['s'], 'warning', A('Hkratna opozorila: {n}', ['n' => $g['n']]) . ': ' . $st->fetchColumn()); $new++;
            }
            $pdo->exec('UPDATE alerts SET incident_id=' . (int)$iid . ' WHERE id IN (' . implode(',', array_map('intval', explode(',', $g['ids']))) . ')');
        }
        // 3. vse ostalo na napravi z odprtim incidentom se pridruži (opozorila, dogodki med incidentom)
        foreach ($pdo->query('SELECT id, device_id, started_at FROM incidents WHERE ended_at IS NULL')->fetchAll() as $i)
            // samo opozorila/kritični in dogodki ob incidentu (ponovni zagon, SFP); ne dolgotrajna stanja (nova verzija, varnostna kopija, disk ...)
            $pdo->prepare("UPDATE alerts SET incident_id=? WHERE device_id=? AND incident_id IS NULL AND started_at >= ? - INTERVAL 5 MINUTE AND (ended_at IS NULL OR ended_at >= ?)
                           AND (severity IN ('warning','critical') OR ended_at=started_at) AND akey NOT IN ('update','backup','hdd','mem','pool','newdev','config','cfgrisk')")
                ->execute([$i['id'], $i['device_id'], $i['started_at'], $i['started_at']]);
        // 4. zapiranje: vsi alarmi incidenta zaprti vsaj 5 min
        foreach ($pdo->query("SELECT i.id, MAX(a.ended_at) last_end, SUM(a.ended_at IS NULL AND a.severity<>'info') still_open FROM incidents i JOIN alerts a ON a.incident_id=i.id WHERE i.ended_at IS NULL GROUP BY i.id")->fetchAll() as $r) {
            if ((int)$r['still_open'] === 0 && $r['last_end'] && strtotime($r['last_end']) < time() - 300) {
                $pdo->prepare('UPDATE incidents SET ended_at=? WHERE id=?')->execute([$r['last_end'], $r['id']]); $closed++;
            }
        }
        return ($new || $closed) ? "incidenti: +$new, zaprtih $closed" : '';
    }

    private static function openFor(int $deviceId): ?int
    {
        $st = db()->prepare('SELECT id FROM incidents WHERE device_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1'); $st->execute([$deviceId]);
        $id = $st->fetchColumn(); return $id ? (int)$id : null;
    }

    private static function create(int $deviceId, string $start, string $sev, string $title): int
    {
        db()->prepare('INSERT INTO incidents (device_id, started_at, severity, title) VALUES (?,?,?,?)')->execute([$deviceId, $start, $sev, mb_substr($title, 0, 200)]);
        return (int)db()->lastInsertId();
    }

    /** Seznam incidentov (z imenom naprave), po pravicah uporabnika */
    public static function list(?int $deviceId = null, int $days = 30, int $limit = 50): array
    {
        [$w, $p] = Auth::deviceScope('d');
        $sql = "SELECT i.*, d.name device_name, (SELECT COUNT(*) FROM alerts a WHERE a.incident_id=i.id) n_alerts FROM incidents i JOIN devices d ON d.id=i.device_id
                WHERE i.started_at > NOW() - INTERVAL ? DAY AND $w" . ($deviceId ? ' AND i.device_id=?' : '') . ' ORDER BY i.started_at DESC LIMIT ' . (int)$limit;
        $st = db()->prepare($sql); $st->execute(array_merge([$days], $p, $deviceId ? [$deviceId] : []));
        return $st->fetchAll();
    }

    /** Časovnica incidenta: alarmi (začetek in konec) in log routerja v oknu incidenta */
    public static function timeline(array $i): array
    {
        $ev = [];
        $st = db()->prepare('SELECT * FROM alerts WHERE incident_id=? ORDER BY started_at'); $st->execute([$i['id']]);
        foreach ($st->fetchAll() as $a) {
            $ev[] = ['t' => $a['started_at'], 'kind' => $a['ended_at'] === $a['started_at'] ? 'event' : 'start', 'sev' => $a['severity'], 'text' => $a['message']];
            if ($a['ended_at'] && $a['ended_at'] !== $a['started_at']) $ev[] = ['t' => $a['ended_at'], 'kind' => 'end', 'sev' => $a['severity'], 'text' => $a['message']];
        }
        $end = $i['ended_at'] ?: date('Y-m-d H:i:s');
        $st = db()->prepare("SELECT ts, topics, message FROM logs WHERE device_id=? AND ts BETWEEN ? - INTERVAL 10 MINUTE AND ? + INTERVAL 5 MINUTE AND severity IN ('warning','error','critical') ORDER BY ts LIMIT 40");
        $st->execute([$i['device_id'], $i['started_at'], $end]);
        foreach ($st->fetchAll() as $l) $ev[] = ['t' => $l['ts'], 'kind' => 'log', 'sev' => 'info', 'text' => $l['topics'] . ': ' . $l['message']];
        usort($ev, fn($a, $b) => strcmp($a['t'], $b['t']));
        return $ev;
    }

    public static function duration(array $i): string
    {
        $s = max(0, ($i['ended_at'] ? strtotime($i['ended_at']) : time()) - strtotime($i['started_at']));
        return $s < 60 ? '<1 min' : fmt_uptime($s);
    }
}
