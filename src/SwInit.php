<?php
declare(strict_types=1);

/**
 * Prva konfiguracija praznega stikala iz enega obrazca: uplink kot trunk s tagiranimi VLAN-i, razponi portov kot access VLAN-i.
 * Upravljanje stikala ostane netagirano (VLAN 1) – stikalo ves čas ostane v stiku z NOC. VLAN filtering se vklopi zadnji,
 * varovalka (kot SwPort) ga ob povrnitvi najprej izklopi.
 */
final class SwInit
{
    /** Prazno = VLAN filtering izklopljen in nobenega ročno dodanega vnosa VLAN */
    public static function isBlank(array $d): bool
    {
        if (!Devices::isSwitch($d) || SwitchIngest::vlanFiltering($d)) return false;
        $st = db()->prepare('SELECT COUNT(*) FROM sw_vlans WHERE device_id=? AND dynamic=0'); $st->execute([(int)$d['id']]);
        $st2 = db()->prepare('SELECT COUNT(*) FROM sw_ports WHERE device_id=?'); $st2->execute([(int)$d['id']]);
        $st3 = db()->prepare("SELECT COUNT(*) FROM device_ifaces WHERE device_id=? AND type='vlan'"); $st3->execute([(int)$d['id']]);   // upravljanje v tagiranem VLAN-u: ne-cilj
        return (int)$st->fetchColumn() === 0 && (int)$st2->fetchColumn() > 0 && (int)$st3->fetchColumn() === 0;
    }

    /** ['1','2','3','5','S1','S2'] -> '1-3, 5, S1-S2' (zaporedne oznake z isto predpono združi) */
    public static function ranges(array $labels): string
    {
        $out = []; $run = null;
        $flush = function () use (&$run, &$out) { if ($run) $out[] = $run[1] === $run[2] ? $run[0] . $run[1] : $run[0] . $run[1] . '-' . $run[0] . $run[2]; $run = null; };
        foreach ($labels as $l) {
            if (!preg_match('/^([A-Za-z]*)(\d+)$/', (string)$l, $m)) { $flush(); $out[] = (string)$l; continue; }
            if ($run && $run[0] === $m[1] && (int)$m[2] === $run[2] + 1) { $run[2] = (int)$m[2]; continue; }
            $flush(); $run = [$m[1], (int)$m[2], (int)$m[2]];
        }
        $flush();
        return implode(', ', $out);
    }

    /** Oznaka na plošči ali ime -> ime porta ('5' -> ether5, 'S1' -> sfp-sfpplus1, 'Q1' -> qsfp…) */
    private static function resolve(string $tok, array $ports): ?string
    {
        if (in_array($tok, $ports, true)) return $tok;
        foreach ($ports as $p) if (strcasecmp(SwLayout::label($p), $tok) === 0) return $p;
        return null;
    }

    /**
     * "1-5", "1,3,7-9", "S1-S4", "ether12" -> imena portov v vrstnem redu.
     * @return array{0: string[], 1: string} [porti, napaka]
     */
    public static function parsePorts(string $spec, array $ports): array
    {
        $out = [];
        $parts = array_values(array_filter(array_map('trim', explode(',', $spec)), fn($x) => $x !== ''));
        if (!$parts) return [[], A('Vnesi porte, npr. 1-5 ali 1,3,7-9.')];
        foreach ($parts as $p) {
            if (preg_match('/^([A-Za-z]*)(\d+)\s*-\s*([A-Za-z]*)(\d+)$/', $p, $m)) {
                $pre = strtoupper($m[1] !== '' ? $m[1] : $m[3]);
                if ($m[3] !== '' && strtoupper($m[1]) !== strtoupper($m[3])) return [[], A('Razpon {r} ni veljaven.', ['r' => $p])];
                if ((int)$m[2] > (int)$m[4] || (int)$m[4] - (int)$m[2] > 128) return [[], A('Razpon {r} ni veljaven.', ['r' => $p])];
                for ($i = (int)$m[2]; $i <= (int)$m[4]; $i++) {
                    $n = self::resolve($pre . $i, $ports);
                    if ($n === null) return [[], A('Port {p} na tem stikalu ne obstaja.', ['p' => $pre . $i])];
                    $out[] = $n;
                }
            } else {
                $n = self::resolve($p, $ports);
                if ($n === null) return [[], A('Port {p} na tem stikalu ne obstaja.', ['p' => $p])];
                $out[] = $n;
            }
        }
        return [array_values(array_unique($out)), ''];
    }

    /**
     * Preverjanje obrazca in povzetek ukazov.
     * @param array $rows [['ports' => '1-5', 'vlan' => 10, 'name' => 'pisarna'], …]
     * @return array{err: string, warn: string[], cmds: string[], want: array}
     */
    public static function plan(array $d, string $uplink, array $rows, string $extra): array
    {
        $out = ['err' => '', 'warn' => [], 'cmds' => [], 'want' => []];
        $err = function (string $m) use (&$out) { $out['err'] = $m; return $out; };
        if (!self::isBlank($d)) return $err(A('Stikalo ni prazno (VLAN filtering, VLAN-i ali VLAN vmesniki že obstajajo) – porte uredi posamično.'));
        $st = db()->prepare('SELECT port, bridge FROM sw_ports WHERE device_id=?'); $st->execute([(int)$d['id']]);
        $br = []; foreach ($st as $r) $br[$r['port']] = (string)$r['bridge'];
        $ports = array_keys($br);
        if ($uplink === '' || !isset($br[$uplink])) return $err(A('Izberi uplink port (port proti routerju).'));
        $bridge = $br[$uplink]; $det = SwitchIngest::uplink($d);
        $vlans = []; $used = [];
        foreach ($rows as $i => $r) {
            if (trim((string)($r['ports'] ?? '')) === '' && !(int)($r['vlan'] ?? 0)) continue;   // prazna vrstica obrazca
            $v = (int)($r['vlan'] ?? 0);
            if ($v < 2 || $v > 4094) return $err(A('VLAN mora biti med 2 in 4094 (VLAN 1 je upravljanje).'));
            [$ps, $e] = self::parsePorts((string)($r['ports'] ?? ''), $ports);
            if ($e !== '') return $err($e);
            foreach ($ps as $p) {
                if ($p === $uplink) return $err(A('Uplink ({p}) ne more biti v vrstici portov – nosi vse VLAN-e tagirano.', ['p' => $p]));
                if ($p === $det) return $err(A('Port {p} je zaznan kot povezava proti routerju – v access VLAN-u bi stikalo izgubilo stik z NOC.', ['p' => SwLayout::label($p)]));
                if (isset($used[$p])) return $err(A('Port {p} je v dveh vrsticah.', ['p' => SwLayout::label($p)]));
                if ($br[$p] !== $bridge) return $err(A('Port {p} ni v istem bridgu kot uplink.', ['p' => SwLayout::label($p)]));
                $used[$p] = $v;
            }
            $name = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub((string)($r['name'] ?? ''), 'UTF-8'))), 0, 60);
            if (!isset($vlans[$v])) $vlans[$v] = ['id' => $v, 'name' => $name, 'ports' => []];
            if ($vlans[$v]['name'] === '') $vlans[$v]['name'] = $name;
            elseif ($name !== '' && $name !== $vlans[$v]['name']) $out['warn'][] = A('VLAN {v} ima v vrsticah dve imeni – uporabljeno bo »{n}«.', ['v' => $v, 'n' => $vlans[$v]['name']]);
            $vlans[$v]['ports'] = array_merge($vlans[$v]['ports'], $ps);
        }
        if (!$vlans) return $err(A('Dodaj vsaj eno vrstico portov z VLAN-om.'));
        $tagged = array_keys($vlans);
        foreach (preg_split('/[\s,;]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) as $x) {
            if (!ctype_digit($x) || (int)$x < 2 || (int)$x > 4094) return $err(A('Dodatni VLAN {v} ni veljaven.', ['v' => $x]));
            $tagged[] = (int)$x;
        }
        $tagged = array_values(array_unique($tagged)); sort($tagged); ksort($vlans);
        foreach ($tagged as $t) if (!isset($vlans[$t])) $vlans[$t] = ['id' => $t, 'name' => '', 'ports' => []];   // samo na uplinku
        ksort($vlans);
        $lbl = fn(array $ps) => self::ranges(array_map([SwLayout::class, 'label'], $ps));
        foreach ($vlans as $v) $out['cmds'][] = "/interface bridge vlan add bridge=$bridge vlan-ids={$v['id']} tagged=$uplink" . ($v['ports'] ? ' untagged=' . implode(',', $v['ports']) : '') . ($v['name'] !== '' ? ' comment="' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v['name']) . '"' : '');
        foreach ($vlans as $v) if ($v['ports']) $out['cmds'][] = "/interface bridge port set [find interface=…] pvid={$v['id']} frame-types=admit-only-untagged-and-priority-tagged   # " . A('porti {p}', ['p' => $lbl($v['ports'])]);
        $out['cmds'][] = "/interface bridge port set [find interface=$uplink] pvid=1 frame-types=admit-all   # uplink: " . A('upravljanje netagirano');
        $out['cmds'][] = "/interface bridge set $bridge vlan-filtering=yes   # " . A('zadnje');
        $rest = array_values(array_diff($ports, array_keys($used), [$uplink]));
        usort($rest, fn($x, $y) => strnatcmp(SwLayout::label($x), SwLayout::label($y)));
        if ($rest) $out['warn'][] = A('Porti {p} ostanejo v VLAN 1 (upravljanje, kot zdaj).', ['p' => $lbl($rest)]);
        $out['warn'][] = A('Na routerju po tem nastavi tagirane VLAN-e {v} na vmesniku proti stikalu – do takrat naprave v teh VLAN-ih nimajo povezave.', ['v' => implode(', ', $tagged)]);
        $out['want'] = ['uplink' => $uplink, 'bridge' => $bridge, 'vlans' => array_values($vlans), 'tagged' => $tagged];
        return $out;
    }

    /** Ukazi za stikalo (/api/action): preverjanje praznosti, varovalka, konfiguracija, vklop VLAN filteringa, potrditev */
    public static function rsc(array $d, array $a, string $base, string $key, string $chk): string
    {
        $w = (array)((json_decode((string)$a['data'], true) ?: [])['want'] ?? []); $id = (int)$a['id'];
        $q = fn(string $s) => '"' . str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $s) . '"';
        $up = (string)($w['uplink'] ?? ''); $B = (string)($w['bridge'] ?? 'bridge'); $vlans = (array)($w['vlans'] ?? []);
        $rb = "noc-sw-rb-$id"; $pol = MikrotikScript::POLICY;
        $done = "/tool fetch url=\"$base/api/action-done/$id\"$chk http-header-field=\"X-Api-Key: $key\" output=none";
        $fail = ":do { /tool fetch url=\"$base/api/action-done/$id?fail=1\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}";
        $ports = [$up]; foreach ($vlans as $v) foreach ((array)$v['ports'] as $p) $ports[] = (string)$p;
        $ports = array_values(array_unique($ports));
        $s = "# --- noc: prva konfiguracija stikala (#$id)\n:do {\n"
           . ":global nocSwRb \"\"\n:global nocSwPre \"\"\n:global nocSwOk$id false\n:local B " . $q($B) . "\n:local stop false\n"
           . "# 0) stikalo mora biti se prazno\n"
           . ":if ([/interface bridge get [find name=\$B] vlan-filtering] or ([:len [/interface bridge vlan find where bridge=\$B and dynamic=no]] > 0) or ([:len [/interface vlan find where interface=\$B]] > 0)) do={ :error \"stikalo ni prazno\" }\n"
           . "# 1) povrnitev: najprej izklop VLAN filteringa, nato odstranitev novih vnosov, nato pvid portov\n"
           . ":set nocSwPre " . $q(":do { /interface bridge set [find name=" . $q($B) . "] vlan-filtering=no } on-error={}\n") . "\n";
        foreach ($ports as $p)
            $s .= ":do { :local x ([/interface bridge port find where interface=" . $q($p) . "]->0); :set nocSwRb (\$nocSwRb . " . $q(":do { /interface bridge port set [find interface=" . $q($p) . "] pvid=") . " . [/interface bridge port get \$x pvid] . \" frame-types=\" . [/interface bridge port get \$x frame-types] . \" } on-error={}\\n\") } on-error={}\n";
        $clean = "/system script remove [find name=\\\"$rb\\\"]; /system scheduler remove [find name=\\\"$rb\\\"]";
        $s .= "# 2) varovalka: brez potrditve NOC povrnitev cez 5 minut ali ob ponovnem zagonu\n"
            . "/system script remove [find name=\"$rb\"]\n/system scheduler remove [find name=\"$rb\"]\n"
            . "/system script add name=\"$rb\" policy=$pol comment=\"noc.dezman.net\" source=(\$nocSwPre . \$nocSwRb . \":log warning \\\"noc: prva konfiguracija stikala povrnjena (#$id)\\\"\\n\")\n"
            . "/system scheduler add name=\"$rb\" start-time=startup policy=$pol comment=\"noc.dezman.net\" on-event=\"/system script run $rb; $clean\"\n"
            . ":execute script=\":delay 300s; :global nocSwOk$id; :if (\\\$nocSwOk$id != true) do={ /system script run $rb }; $clean\"\n"
            . "# 3) konfiguracija\n:onerror err in={\n";
        foreach ($vlans as $v) {
            $s .= "    :do { :local n [/interface bridge vlan add bridge=\$B vlan-ids=" . (int)$v['id'] . " tagged=" . $q($up)
                . ($v['ports'] ? " untagged=" . $q(implode(',', array_map('strval', (array)$v['ports']))) : '')
                . " comment=" . $q((string)($v['name'] ?? '')) . "]"
                . "; :set nocSwPre (\$nocSwPre . \":do { /interface bridge vlan remove \" . \$n . \" } on-error={}\\n\") }\n";
        }
        foreach ($vlans as $v) foreach ((array)$v['ports'] as $p)
            $s .= "    /interface bridge port set [find interface=" . $q((string)$p) . "] pvid=" . (int)$v['id'] . " frame-types=admit-only-untagged-and-priority-tagged\n";
        $s .= "    /interface bridge port set [find interface=" . $q($up) . "] pvid=1 frame-types=admit-all\n"
            . "    /system script set [find name=\"$rb\"] source=(\$nocSwPre . \$nocSwRb . \":log warning \\\"noc: prva konfiguracija stikala povrnjena (#$id)\\\"\\n\")\n"
            . "    /interface bridge set [find name=\$B] vlan-filtering=yes\n"
            . "    :log info \"noc: prva konfiguracija stikala izvedena (#$id)\"\n"
            . "} do={\n"
            . "    :log error (\"noc: prva konfiguracija stikala ni uspela (#$id) - \" . \$err)\n"
            . "    :do { /system script set [find name=\"$rb\"] source=(\$nocSwPre . \$nocSwRb) } on-error={}\n"
            . "    :do { /system script run \"$rb\" } on-error={}\n"
            . "    :set nocSwOk$id true\n"
            . "    :do { /system script remove [find name=\"$rb\"]; /system scheduler remove [find name=\"$rb\"] } on-error={}\n"
            . "    $fail\n    :set stop true\n}\n"
            . "# 4) potrditev: po vklopu filteringa se porti bridga ponastavijo (STP), zato do 8 poskusov v ~2 minutah\n:if (!\$stop) do={\n"
            . ":for i from=1 to=8 do={ :if (\$nocSwOk$id != true) do={ :delay 15s; :do { $done; :set nocSwOk$id true } on-error={ :log warning (\"noc: potrditev prve konfiguracije - poskus \" . \$i) } } }\n"
            . ":if (\$nocSwOk$id = true) do={ /system script remove [find name=\"$rb\"]; /system scheduler remove [find name=\"$rb\"]; :log info \"noc: prva konfiguracija stikala potrjena (#$id)\" } else={ :log warning \"noc: prva konfiguracija ni potrjena - povrnitev cez 5 min\" }\n"
            . "}\n"
            . "} on-error={ :log error \"noc: prva konfiguracija stikala ni izvedena (#$id)\"; $fail }\n";
        return $s;
    }

    /**
     * Predloga ukazov za router (NOC routerja ne spreminja): VLAN vmesniki na vmesniku proti stikalu.
     * @param array $vlans want['vlans'] ali [['id'=>10,'name'=>'pisarna'], …]
     */
    public static function routerHint(array $d, array $vlans): string
    {
        $q = fn(string $s) => '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"';
        $ids = []; foreach ($vlans as $v) $ids[(int)$v['id']] = (string)($v['name'] ?? '');
        ksort($ids);
        $s = "# " . A('Router: VLAN-i proti stikalu {n}. Zamenjaj IFACE z vmesnikom, na katerem je stikalo (npr. ether2).', ['n' => preg_replace('/[\x00-\x1F\x7F]/', '', (string)$d['name'])]) . "\n"
           . "# " . A('Če je ta vmesnik v bridgu, ga dodaj kot tagiran v /interface bridge vlan in na bridgu vklopi vlan-filtering – previdno, lahko te odklopi.') . "\n";
        foreach ($ids as $id => $name)
            $s .= "/interface vlan add interface=IFACE name=vlan$id vlan-id=$id comment=" . $q($name !== '' ? $name : "VLAN $id") . "\n";
        $s .= "# " . A('nato za vsak VLAN še IP naslov, DHCP strežnik in pravila požarnega zidu, npr.:') . "\n";
        foreach (array_slice(array_keys($ids), 0, 1) as $id) $s .= "# /ip address add interface=vlan$id address=192.168.X.1/24\n";
        return $s;
    }
}
