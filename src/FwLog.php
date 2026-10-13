<?php
declare(strict_types=1);

/**
 * Varnostna analiza (stikalo pri napravi):
 *  - router pošilja zapise požarnega zidu (zavrnjeni poskusi z interneta, zadetki varnostne liste) prek syslog (UDP)
 *    na strežnik; rsyslog jih piše v datoteko, ingest() jih vsako minuto povzame v fw_5m;
 *  - inbound(): skeniranje portov, vztrajno napadanje enega porta, nenaden porast glede na običajno raven;
 *  - outbound() (iz traffic-flow): naprava v LAN-u skenira internet, pošilja pošto mimo strežnika, kontaktira varnostno listo.
 * Pravila na routerju nosijo oznako "noc-log" (ne "noc-fw", ki jo pobriše varovalka požarnega zidu).
 */
final class FwLog
{
    public static function port(): int { return (int)cfg('fwlog_port', 5514); }
    public static function file(): string { return (string)cfg('fwlog_file', '/var/log/noc-fw/fw.log'); }

    private static function q(string $s): string { return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s); }

    /** Izbira WAN za pravilo: seznam WAN, sicer vmesnik WAN; '' = ni mogoče */
    public static function wanSel(array $d): string
    {
        try { $sel = (string)(Firewall::audit($d)['wanSel'] ?? ''); } catch (\Throwable $e) { $sel = ''; }
        if ($sel !== '' && !str_contains($sel, 'in-interface=""')) return $sel;
        return trim((string)$d['wan_iface']) !== '' ? 'in-interface="' . self::q((string)$d['wan_iface']) . '"' : '';
    }

    /** Odstrani vse nastavitve varnostne analize z routerja (vedno pred ponovno nastavitvijo) */
    public static function cleanupRsc(): string
    {
        return "# --- varnostna analiza (NOC): odstrani prejsnje nastavitve\n"
            . ":do { /ip firewall filter remove [find comment~\"^noc-log\"] } on-error={}\n"
            . ":do { /ip firewall raw remove [find comment~\"^noc-log\"] } on-error={}\n"
            . ":do { /system logging remove [find action=nocsyslog] } on-error={}\n"
            . ":do { /system logging action remove [find name=nocsyslog] } on-error={}\n"
            . ":foreach r in=[/system logging find] do={ :if ([:tostr [/system logging get \$r topics]] = \"info;!fetch;!firewall\") do={ /system logging set \$r topics=info,!fetch } }\n";
    }

    /** Del namestitvenega paketa */
    public static function rsc(array $d): string
    {
        $s = self::cleanupRsc();
        if (!(int)($d['fwlog_enabled'] ?? 0)) return $s . "\n";
        $noc = (string)cfg('noc_ip'); $port = self::port();
        if ($noc === '') return $s . "# varnostna analiza: noc_ip ni nastavljen v config.php\n\n";
        $sel = self::wanSel($d);
        $wan = self::q((string)$d['wan_iface']);
        $s .= "\n# --- varnostna analiza: zapisi pozarnega zidu -> NOC (syslog UDP $port), omejeni na 20/min na izvorni naslov\n"
            . "# (ime akcije za belezenje sme vsebovati samo crke in stevilke)\n"
            . ":do {\n"
            . "    :onerror e in={\n"
            . "        /system logging action add name=nocsyslog target=remote remote=$noc remote-port=$port\n"
            . "        /system logging add topics=firewall action=nocsyslog\n"
            . "    } do={ :log warning (\"noc: posiljanje logov pozarnega zidu ni nastavljeno - \" . \$e) }\n"
            . "    # zapisi pozarnega zidu ne polnijo lokalnega loga routerja\n"
            . "    :foreach r in=[/system logging find] do={ :if ([:tostr [/system logging get \$r topics]] = \"info;!fetch\") do={ /system logging set \$r topics=info,!fetch,!firewall } }\n";
        if ($sel !== '') {
            $add = "/ip firewall filter add chain=input $sel connection-state=new action=log log-prefix=\"noc-in\" dst-limit=20/1m,40,src-address/5m comment=\"noc-log: zavrnjeno z interneta\"";
            $s .= "    # belezenje tik pred prvim splosnim drop/reject v input (= poskusi z interneta, ki jih router zavrne)\n"
                . "    :local nocTgt \"\"\n"
                . "    :local nocFound false\n"
                . "    :foreach r in=[/ip firewall filter find where chain=input dynamic=no] do={\n"
                . "        :if (!\$nocFound) do={\n"
                . "            :local x [/ip firewall filter get \$r]\n"
                . "            :local a [:tostr (\$x->\"action\")]\n"
                . "            :local ii [:tostr (\$x->\"in-interface\")]\n"
                . "            :local il [:tostr (\$x->\"in-interface-list\")]\n"
                . "            # disabled preverimo tu: 'find where disabled=no' na nekaterih verzijah RouterOS ne najde nicesar\n"
                . "            :if ([:tostr (\$x->\"disabled\")] != \"true\" && (\$a = \"drop\" || \$a = \"reject\") && [:len [:tostr (\$x->\"protocol\")]] = 0 && [:len [:tostr (\$x->\"dst-port\")]] = 0 && [:len [:tostr (\$x->\"src-address\")]] = 0 && [:len [:tostr (\$x->\"src-address-list\")]] = 0 && [:len [:tostr (\$x->\"connection-state\")]] = 0 && ([:len \$ii] = 0 || \$ii = \"$wan\") && ([:len \$il] = 0 || \$il = \"WAN\" || \$il = \"!LAN\")) do={ :set nocTgt \$r; :set nocFound true }\n"
                . "        }\n"
                . "    }\n"
                . "    :onerror e in={\n"
                . "        :if (\$nocFound) do={ $add place-before=\$nocTgt } else={ $add }\n"
                . "    } do={ :log warning (\"noc: pravilo za belezenje pozarnega zidu ni dodano - \" . \$e) }\n";
        } else {
            $s .= "    # WAN vmesnik ni znan - belezenje poskusov z interneta ni nastavljeno\n";
        }
        $s .= "    # zadetki varnostne IP liste (samo ce je vklopljena zascita z varnostnimi listami)\n"
            . "    :local nocBl [/ip firewall raw find where comment~\"^noc-filter: varnostna\"]\n"
            . "    :if ([:len \$nocBl] > 0) do={\n"
            . "        :onerror e in={\n"
            . "            /ip firewall raw add chain=prerouting src-address-list=noc-filter-drop action=log log-prefix=\"noc-bl-in\" dst-limit=10/1m,20,src-address/5m comment=\"noc-log: varnostna lista (vhod)\" place-before=(\$nocBl->0)\n"
            . "            /ip firewall raw add chain=prerouting dst-address-list=noc-filter-drop action=log log-prefix=\"noc-bl-out\" dst-limit=10/1m,20,src-address/5m comment=\"noc-log: varnostna lista (izhod)\" place-before=(\$nocBl->0)\n"
            . "        } do={ :log warning (\"noc: belezenje varnostne liste ni dodano - \" . \$e) }\n"
            . "    }\n"
            . "} on-error={ :log warning \"noc: varnostna analiza ni nastavljena\" }\n\n";   // zunanji :do lovi vse ostalo
        return $s;
    }

    // ================================================================== sprejem (cron vsako minuto)
    /** Prebere nove vrstice iz datoteke rsyslog in jih povzame v fw_5m */
    public static function ingest(): string
    {
        $f = self::file();
        if (!is_readable($f)) return 'ni datoteke ' . $f;
        $byIp = [];
        foreach (db()->query('SELECT id, public_ip, last_seen_ip FROM devices WHERE active=1 AND fwlog_enabled=1') as $d)
            foreach ([$d['public_ip'], $d['last_seen_ip']] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP)) $byIp[$ip] = (int)$d['id'];
        // stanje "inode:pozicija:odtis začetka" – odtis zazna copytruncate (inode ostane isti, vsebina se zamenja)
        $saved = explode(':', (string)(db()->query("SELECT v FROM settings WHERE k='fwlog_pos'")->fetchColumn() ?: '0:0:'), 3);   // ne iz predpomnilnika setting()
        $ino = (int)($saved[0] ?? 0); $pos = (int)($saved[1] ?? 0); $head = (string)($saved[2] ?? '');
        clearstatcache(); $st = stat($f); $agg = []; $seen = []; $n = 0; $skip = 0;
        $old = $f . '.1';
        $rotated = ($ino && $st['ino'] !== $ino) || $st['size'] < $pos || ($pos > 0 && self::head($f, $pos) !== $head);
        if ($rotated) {   // dokončaj prejšnjo vsebino (fw.log.1), če je še tu in se ujema, nato nova datoteka od začetka
            if ($pos > 0 && is_readable($old) && filesize($old) >= $pos && self::head($old, $pos) === $head) self::read($old, $pos, $byIp, $agg, $seen, $n, $skip);
            $pos = 0;
        }
        $pos = self::read($f, $pos, $byIp, $agg, $seen, $n, $skip);
        $pdo = db();
        $ins = $pdo->prepare('INSERT INTO fw_5m (device_id, ts, kind, src, dst, proto, port, hits) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hits=hits+VALUES(hits)');
        foreach ($agg as $k => $hits) { [$id, $ts, $kind, $src, $dst, $proto, $port] = explode('|', $k); $ins->execute([(int)$id, $ts, $kind, $src, $dst, $proto, (int)$port, $hits]); }
        $up = $pdo->prepare('UPDATE devices SET fwlog_seen_at=FROM_UNIXTIME(?) WHERE id=?');
        foreach ($seen as $id => $t) $up->execute([$t, $id]);
        setting_set('fwlog_pos', $st['ino'] . ':' . $pos . ':' . self::head($f, $pos));
        return "vrstic $n" . ($skip ? ", neznanih $skip" : '');
    }

    /** Odtis prvih (do) 256 bajtov datoteke – za zaznavo zamenjane vsebine */
    private static function head(string $f, int $pos): string
    {
        $len = min(256, $pos); if ($len <= 0) return '';
        $h = @fopen($f, 'r'); if (!$h) return '';
        $x = (string)fread($h, $len); fclose($h);
        return strlen($x) === $len ? substr(md5($x), 0, 16) : 'x';
    }

    /** Bere od $pos do konca (največ 50 MB), vrne novo pozicijo; nedokončana zadnja vrstica ostane za naslednjič */
    private static function read(string $f, int $pos, array $byIp, array &$agg, array &$seen, int &$n, int &$skip): int
    {
        $h = fopen($f, 'r'); if (!$h) return $pos;
        fseek($h, $pos); $limit = $pos + 50 * 1024 * 1024;
        while ($pos < $limit && ($line = fgets($h)) !== false) {
            if (!str_ends_with($line, "\n")) break;
            $pos += strlen($line);
            $r = self::parse($line);
            if (!$r) { $skip++; continue; }
            $id = $byIp[$r['from']] ?? null; if (!$id) { $skip++; continue; }
            $slot = date('Y-m-d H:i:00', $r['t'] - $r['t'] % 300);
            $key = implode('|', [$id, $slot, $r['kind'], $r['src'], $r['dst'], $r['proto'], $r['port']]);
            $agg[$key] = ($agg[$key] ?? 0) + 1; $n++;
            $seen[$id] = max($seen[$id] ?? 0, $r['t']);
        }
        fclose($h);
        return $pos;
    }

    /** Vrstica "unixtime|ip routerja|syslog sporočilo" -> zapis ali null (tuja pravila, IPv6) */
    public static function parse(string $line): ?array
    {
        $p = explode('|', rtrim($line, "\r\n"), 3);
        if (count($p) < 3 || !preg_match('/\b(noc-(?:in|bl-in|bl-out))\s+\w+:/', $p[2], $m)) return null;
        // "proto TCP (SYN), a:p->b:p" | "proto UDP, a:p->b:p" | "proto ICMP (type 8, code 0), a->b"
        if (!preg_match('/proto (\w+)(?: \([^)]*\))?, (\d{1,3}(?:\.\d{1,3}){3})(?::(\d+))?->(\d{1,3}(?:\.\d{1,3}){3})(?::(\d+))?/', $p[2], $a)) return null;
        $kind = ['noc-in' => 'in', 'noc-bl-in' => 'bl_in', 'noc-bl-out' => 'bl_out'][$m[1]];
        return ['t' => (int)$p[0] ?: time(), 'from' => $p[1], 'kind' => $kind, 'proto' => strtoupper(substr($a[1], 0, 8)),
                'src' => $a[2], 'dst' => $kind === 'bl_out' ? $a[4] : '', 'port' => min(65535, (int)($a[5] ?? 0))];
    }

    // ================================================================== analiza dohodnega prometa (vsakih 5 min)
    public static function inbound(): string
    {
        $out = 0;
        foreach (db()->query('SELECT * FROM devices WHERE active=1 AND fwlog_enabled=1')->fetchAll() as $d) {
            $d = Devices::decorate($d); $th = Devices::thresholds($d); $id = (int)$d['id'];
            // skeniranje portov / vztrajno napadanje enega porta (zadnjih 15 min, po izvornem naslovu)
            $st = db()->prepare("SELECT src, COUNT(DISTINCT proto, port) ports, SUM(hits) hits, GROUP_CONCAT(DISTINCT port ORDER BY port SEPARATOR ',') pl
                                 FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 15 MINUTE GROUP BY src
                                 HAVING ports >= ? OR hits >= ? ORDER BY hits DESC LIMIT 10");
            $st->execute([$id, max(5, (int)$th['th_fw_ports']), max(20, (int)$th['th_fw_hits'])]);
            foreach ($st as $r) {
                $ports = array_slice(explode(',', (string)$r['pl']), 0, 8);
                $msg = (int)$r['ports'] >= (int)$th['th_fw_ports']
                    ? A('Skeniranje portov z {ip}: {n} portov v 15 min (npr. {p} …)', ['ip' => $r['src'], 'n' => (int)$r['ports'], 'p' => implode(', ', $ports)])
                    : A('Vztrajni poskusi z {ip}: {n} poskusov v 15 min na port {p}', ['ip' => $r['src'], 'n' => (int)$r['hits'], 'p' => implode(', ', $ports)]);
                $out += (int)self::once($d, 'fwscan:' . $r['src'], 'info', $msg);
            }
            // nenaden porast glede na običajno raven ob tej uri (prejšnji dnevi)
            $cur = (int)db()->query("SELECT COALESCE(SUM(hits),0) FROM fw_5m WHERE device_id=$id AND kind='in' AND ts > NOW() - INTERVAL 1 HOUR")->fetchColumn();
            // ista ura na prejšnjih 6 dneh; dan šteje, če je router takrat (± 12 h) pošiljal zapise
            $days = 0; $sum = 0;
            $hq = db()->prepare("SELECT COALESCE(SUM(hits),0) FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL ? HOUR AND ts <= NOW() - INTERVAL ? HOUR");
            $aq = db()->prepare("SELECT COUNT(*) FROM fw_5m WHERE device_id=? AND ts > NOW() - INTERVAL ? HOUR AND ts <= NOW() - INTERVAL ? HOUR");
            for ($k = 1; $k <= 6; $k++) {
                $h0 = 24 * $k;
                $aq->execute([$id, $h0 + 12, max(0, $h0 - 12)]); if (!(int)$aq->fetchColumn()) continue;
                $hq->execute([$id, $h0 + 1, $h0]); $sum += (int)$hq->fetchColumn(); $days++;
            }
            $min = max(50, (int)$th['th_fw_spike_min']); $factor = max(1.5, (float)$th['th_fw_spike']);
            $base = $days >= 2 ? $sum / $days : null;
            $limit = $base === null ? $min * 3 : max($min, $base * $factor);
            $open = db()->query("SELECT COUNT(*) FROM alerts WHERE device_id=$id AND akey='fwspike' AND ended_at IS NULL")->fetchColumn();
            if ($cur >= $limit) Alerts::raise($d, 'fwspike', 'warning', A('Nenaden porast poskusov z interneta: {n} v zadnji uri (običajno {b})', ['n' => $cur, 'b' => $base === null ? '–' : '~' . (int)round($base)]));
            elseif ($open && $cur < $limit / 2) Alerts::clear($d, 'fwspike');
            // naprava v LAN-u proti varnostni listi (zapisi routerja, zadnjih 15 min)
            $st = db()->prepare("SELECT src, SUM(hits) hits, GROUP_CONCAT(DISTINCT CONCAT(dst, ':', port) SEPARATOR ', ') dl FROM fw_5m
                                 WHERE device_id=? AND kind='bl_out' AND ts > NOW() - INTERVAL 15 MINUTE GROUP BY src HAVING hits >= ? LIMIT 10");
            $st->execute([$id, max(1, (int)$th['th_out_bl'])]);
            foreach ($st as $r) $out += (int)self::once($d, 'outbl:' . $r['src'], 'warning',
                A('Naprava {h} poskuša dostopati do omrežja na varnostni listi ({d}) – možna okužba', ['h' => self::host($id, $r['src']), 'd' => mb_strimwidth((string)$r['dl'], 0, 80, '…')]));
        }
        return "dogodkov $out";
    }

    // ================================================================== analiza odhodnega prometa (iz traffic-flow)
    /**
     * $hosts[ip] = ['f' => tokov, 'ips' => [dst=>1], 'pp' => [port => [dst=>1]], 'smtp' => [dst=>1], 'bl' => n, 'bls' => dst]
     * Kliče Flows::file za vsako 5-min datoteko in napravo z vklopljeno analizo.
     */
    public static function outbound(array $d, array $hosts, string $slot): int
    {
        $d = Devices::decorate($d); $th = Devices::thresholds($d); $id = (int)$d['id']; $n = 0;
        $ins = db()->prepare('INSERT INTO out_5m (device_id, ts, ip, flows, dst_ips, scan_port, scan_ips, smtp_ips, bl_hits, bl_sample) VALUES (?,?,?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE flows=flows+VALUES(flows), dst_ips=GREATEST(dst_ips, VALUES(dst_ips)), scan_port=IF(VALUES(scan_ips) > scan_ips, VALUES(scan_port), scan_port),
                              scan_ips=GREATEST(scan_ips, VALUES(scan_ips)), smtp_ips=GREATEST(smtp_ips, VALUES(smtp_ips)), bl_hits=bl_hits+VALUES(bl_hits), bl_sample=IF(VALUES(bl_sample)<>"", VALUES(bl_sample), bl_sample)');
        foreach ($hosts as $ip => $h) {
            $dst = count($h['ips'] ?? []); $smtp = count($h['smtp'] ?? []); $bl = (int)($h['bl'] ?? 0);
            $sp = 0; $si = 0;
            foreach ($h['pp'] ?? [] as $port => $set) if (count($set) > $si) { $si = count($set); $sp = (int)$port; }
            if ($dst < 50 && $si < 20 && !$smtp && !$bl) continue;   // shranimo samo izstopajoče naprave
            $ins->execute([$id, $slot, $ip, (int)($h['f'] ?? 0), $dst, $sp, $si, $smtp, $bl, (string)($h['bls'] ?? '')]);
            $who = self::host($id, $ip);
            if ($si >= max(10, (int)$th['th_out_ips']))
                $n += (int)self::once($d, 'outscan:' . $ip, 'warning', A('Naprava {h} skenira internet: TCP port {p} na {n} različnih naslovov v 5 min – možna okužba', ['h' => $who, 'p' => $sp, 'n' => $si]));
            if ($smtp >= max(1, (int)$th['th_out_smtp']))
                $n += (int)self::once($d, 'outsmtp:' . $ip, 'warning', A('Naprava {h} pošilja pošto neposredno na {n} strežnikov (port 25) – možen spam bot', ['h' => $who, 'n' => $smtp]));
            if ($bl >= max(1, (int)$th['th_out_bl']))
                $n += (int)self::once($d, 'outbl:' . $ip, 'warning', A('Naprava {h} poskuša dostopati do omrežja na varnostni listi ({d}) – možna okužba', ['h' => $who, 'd' => (string)($h['bls'] ?? '')]));
        }
        return $n;
    }

    // ================================================================== pomožno
    /** Dogodek enkrat na 6 h za isti ključ (napadalec ali naprava) – brez poplave obvestil */
    private static function once(array $d, string $key, string $sev, string $msg): bool
    {
        $st = db()->prepare('SELECT COUNT(*) FROM alerts WHERE device_id=? AND akey=? AND started_at > NOW() - INTERVAL 6 HOUR'); $st->execute([$d['id'], $key]);
        if ((int)$st->fetchColumn()) return false;
        Alerts::event($d, $key, $sev, $msg);
        return true;
    }

    /** IP naprave v LAN-u z imenom iz DHCP, če je znano */
    public static function host(int $id, string $ip): string
    {
        $st = db()->prepare('SELECT COALESCE(NULLIF(label,""), hostname) FROM lan_hosts WHERE device_id=? AND ip=? ORDER BY last_seen DESC LIMIT 1'); $st->execute([$id, $ip]);
        $n = (string)($st->fetchColumn() ?: '');
        return $ip . ($n !== '' ? " ($n)" : '');
    }

    private static ?array $drop = null;
    /** Ali je naslov v varnostni listi (Spamhaus DROP, ki jo strežnik prenese vsak dan) */
    public static function inDrop(string $ip): bool
    {
        if (self::$drop === null) {
            self::$drop = [[], []];
            $f = rtrim((string)cfg('data_dir', '/var/lib/noc'), '/') . '/spamhaus-drop.txt';
            $r = [];
            foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $n) {
                if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($n), $m)) continue;
                $start = ip2long($m[1]); $bits = (int)$m[2]; if ($start === false || $bits > 32) continue;
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF; $start &= $mask;
                $r[] = [$start, $start | (~$mask & 0xFFFFFFFF)];
            }
            usort($r, fn($a, $b) => $a[0] <=> $b[0]);
            self::$drop = [array_column($r, 0), array_column($r, 1)];
        }
        $x = ip2long($ip); if ($x === false || !self::$drop[0]) return false;
        [$s, $e] = self::$drop; $lo = 0; $hi = count($s) - 1; $hit = -1;
        while ($lo <= $hi) { $mid = ($lo + $hi) >> 1; if ($s[$mid] <= $x) { $hit = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
        return $hit >= 0 && $x <= $e[$hit];
    }

    // ================================================================== podatki za zavihek Varnost
    public const PORTS = [21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 123 => 'NTP', 135 => 'RPC', 139 => 'NetBIOS',
        143 => 'IMAP', 161 => 'SNMP', 443 => 'HTTPS', 445 => 'SMB', 500 => 'IPsec', 1433 => 'MSSQL', 1723 => 'PPTP', 1900 => 'UPnP', 2323 => 'Telnet', 3306 => 'MySQL',
        3389 => 'RDP', 4500 => 'IPsec', 5060 => 'SIP', 5432 => 'PostgreSQL', 5900 => 'VNC', 6379 => 'Redis', 7547 => 'TR-069', 8080 => 'HTTP', 8291 => 'Winbox',
        8443 => 'HTTPS', 8728 => 'RouterOS API', 8729 => 'RouterOS API', 9200 => 'Elasticsearch', 13231 => 'WireGuard', 51820 => 'WireGuard'];

    public static function portLabel(string $proto, int $port): string
    {
        if ($proto === 'ICMP') return 'ICMP';
        return $port . '/' . strtolower($proto) . (isset(self::PORTS[$port]) ? ' · ' . self::PORTS[$port] : '');
    }

    public static function summary(int $id): array
    {
        $pdo = db(); $r = [];
        $q = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st; };
        // po urah, zadnjih 7 dni (zavrnjeni poskusi z interneta)
        $hours = []; for ($i = 167; $i >= 0; $i--) $hours[date('Y-m-d H:00', time() - $i * 3600)] = 0;
        foreach ($q("SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00') h, SUM(hits) n FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 7 DAY GROUP BY h", [$id]) as $x)
            if (isset($hours[$x['h']])) $hours[$x['h']] = (int)$x['n'];
        $r['hours'] = $hours;
        $r['tot'] = $q("SELECT COALESCE(SUM(hits),0) hits, COUNT(DISTINCT src) src, COUNT(DISTINCT proto, port) ports FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 24 HOUR", [$id])->fetch();
        $r['bl_in'] = (int)$q("SELECT COALESCE(SUM(hits),0) FROM fw_5m WHERE device_id=? AND kind='bl_in' AND ts > NOW() - INTERVAL 24 HOUR", [$id])->fetchColumn();
        $r['src'] = $q("SELECT src, SUM(hits) hits, COUNT(DISTINCT proto, port) ports, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT port ORDER BY port SEPARATOR ', '), ', ', 6) pl, MAX(ts) last
                        FROM fw_5m WHERE device_id=? AND kind IN ('in','bl_in') AND ts > NOW() - INTERVAL 24 HOUR GROUP BY src ORDER BY hits DESC LIMIT 15", [$id])->fetchAll();
        $r['ports'] = $q("SELECT proto, port, SUM(hits) hits, COUNT(DISTINCT src) src FROM fw_5m WHERE device_id=? AND kind='in' AND ts > NOW() - INTERVAL 24 HOUR
                          GROUP BY proto, port ORDER BY hits DESC LIMIT 12", [$id])->fetchAll();
        // naprave v LAN-u: varnostna lista (zapisi routerja) + izstopajoč odhodni promet (traffic-flow)
        $r['bl_out'] = $q("SELECT src, SUM(hits) hits, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT CONCAT(dst, ':', port) SEPARATOR ', '), ', ', 4) dl, MAX(ts) last
                           FROM fw_5m WHERE device_id=? AND kind='bl_out' AND ts > NOW() - INTERVAL 7 DAY GROUP BY src ORDER BY hits DESC LIMIT 15", [$id])->fetchAll();
        $r['out'] = $q("SELECT ip, MAX(dst_ips) dst_ips, MAX(scan_ips) scan_ips, SUBSTRING_INDEX(GROUP_CONCAT(scan_port ORDER BY scan_ips DESC), ',', 1) scan_port,
                               MAX(smtp_ips) smtp_ips, SUM(bl_hits) bl_hits, MAX(bl_sample) bl_sample, MAX(ts) last FROM out_5m WHERE device_id=? AND ts > NOW() - INTERVAL 24 HOUR
                        GROUP BY ip ORDER BY (MAX(scan_ips) * 5 + MAX(smtp_ips) * 20 + SUM(bl_hits) * 50 + MAX(dst_ips)) DESC LIMIT 15", [$id])->fetchAll();
        foreach ($r['out'] as &$x) $x['who'] = self::host($id, $x['ip']); unset($x);
        foreach ($r['bl_out'] as &$x) $x['who'] = self::host($id, $x['src']); unset($x);
        $r['events'] = $q("SELECT * FROM alerts WHERE device_id=? AND (akey LIKE 'fwscan:%' OR akey='fwspike' OR akey LIKE 'out%:%') AND started_at > NOW() - INTERVAL 30 DAY
                           ORDER BY started_at DESC LIMIT 40", [$id])->fetchAll();
        return $r;
    }

    /** Stanje na napravi: [razred, besedilo] */
    public static function state(array $d): array
    {
        if (!(int)($d['fwlog_enabled'] ?? 0)) return ['', A('izklopljena')];
        if (!$d['fwlog_seen_at']) return ['warn', A('čaka na prve zapise z routerja')];
        $age = time() - strtotime((string)$d['fwlog_seen_at']);
        return $age < 6 * 3600 ? ['up', A('deluje · zadnji zapis {t}', ['t' => fmt_ago($d['fwlog_seen_at'])])] : ['warn', A('ni zapisov od {t}', ['t' => fmt_ago($d['fwlog_seen_at'])])];
    }
}
