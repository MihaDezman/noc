<?php
declare(strict_types=1);

/**
 * Sprememba porta stikala iz NOC (ime, vklop, VLAN access/trunk, PoE) z varovalko.
 * Stikalo pred spremembo samo prebere trenutno stanje in iz njega sestavi skripto za povrnitev (noc-sw-rb-<id>);
 * če po spremembi ne doseže NOC (action-done), se čez 5 minut samo povrne. Seznami VLAN-ov se urejajo na stikalu
 * (dodaj/odstrani port), nikoli ne prepišejo s kopijo iz NOC; bridge v tagged ostane.
 */
final class SwPort
{
    /** Upravljalni VLAN stikala (VLAN vmesnik z upravljalnim naslovom) ali null */
    public static function mgmtVlan(array $d): ?int
    {
        $an = json_decode((string)($d['analysis_json'] ?? ''), true) ?: [];
        $ip = (string)($d['mgmt_ip'] ?? '');
        foreach ((array)($an['addresses'] ?? []) as $a) {
            if (explode('/', (string)($a['address'] ?? ''))[0] !== $ip) continue;
            $if = (string)($a['iface'] ?? '');
            if (isset($an['vlans'][$if]['id']) && ctype_digit((string)$an['vlans'][$if]['id'])) return (int)$an['vlans'][$if]['id'];
            return null;   // naslov na bridgu: upravljanje gre netagirano (pvid bridga)
        }
        return null;
    }

    /**
     * Preveri želeno stanje in pripravi povzetek. $want: comment, disabled (bool), mode (access|trunk), vlan, native (0 = brez), tagged (int[]), poe (auto-on|off), poe_reset (bool).
     * @return array{cmds: string[], warn: string[], err: string, want: array, summary: string}
     */
    public static function plan(array $d, string $port, array $want): array
    {
        $out = ['cmds' => [], 'warn' => [], 'err' => '', 'want' => [], 'summary' => ''];
        $pdo = db(); $id = (int)$d['id'];
        $st = $pdo->prepare('SELECT * FROM device_ifaces WHERE device_id=? AND name=?'); $st->execute([$id, $port]); $if = $st->fetch();
        if (!$if || !SwitchIngest::isPort($port)) { $out['err'] = A('Port ne obstaja.'); return $out; }
        $st = $pdo->prepare('SELECT * FROM sw_ports WHERE device_id=? AND port=?'); $st->execute([$id, $port]); $sp = $st->fetch() ?: null;
        $uplink = SwitchIngest::uplink($d); $isUp = $port === $uplink;
        $q = fn(string $s) => '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"';
        $w = []; $sum = [];

        // ime in vklop
        if (isset($want['comment'])) {
            $c = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$want['comment'])), 0, 80);
            if ($c !== (string)$if['comment']) { $w['comment'] = $c; $out['cmds'][] = "/interface ethernet set [find name=" . $q($port) . "] comment=" . $q($c); $sum[] = A('ime') . ' "' . $c . '"'; }
        }
        if (isset($want['disabled']) && (bool)$want['disabled'] !== (bool)(int)$if['disabled']) {
            $w['disabled'] = (bool)$want['disabled'];
            $out['cmds'][] = "/interface ethernet set [find name=" . $q($port) . "] disabled=" . ($w['disabled'] ? 'yes' : 'no');
            $sum[] = $w['disabled'] ? A('izklop') : A('vklop');
            if ($w['disabled'] && $isUp) $out['warn'][] = A('To je uplink stikala – z izklopom stikalo izgubi povezavo z NOC. Varovalka bo port čez 5 minut znova vklopila.');
        }

        // VLAN
        if (!empty($want['mode'])) {
            if (!$sp) { $out['err'] = A('Port ni v bridgu – VLAN-a ni mogoče nastaviti.'); return $out; }
            if (!SwitchIngest::vlanFiltering($d)) { $out['err'] = A('Stikalo nima vklopljenega bridge VLAN filteringa – urejanje VLAN-ov ni mogoče.'); return $out; }
            $ok = fn($v) => is_int($v) && $v >= 1 && $v <= 4094;
            $curUt = array_map('intval', array_filter(explode(',', (string)$sp['untagged']))); $curTg = array_map('intval', array_filter(explode(',', (string)$sp['tagged'])));
            if ($want['mode'] === 'access') {
                $v = (int)($want['vlan'] ?? 0);
                if (!$ok($v)) { $out['err'] = A('VLAN mora biti med 1 in 4094.'); return $out; }
                if ($sp['mode'] !== 'access' || $curUt !== [$v] || (int)$sp['pvid'] !== $v || $curTg) {
                    $w['mode'] = 'access'; $w['vlan'] = $v;
                    $out['cmds'][] = "/interface bridge port set [find interface=" . $q($port) . "] pvid=$v frame-types=admit-only-untagged-and-priority-tagged";
                    $out['cmds'][] = "/interface bridge vlan: $port " . A('netagiran v VLAN {v}', ['v' => $v]) . (($curUt || $curTg) ? ' (' . A('odstranjen iz {l}', ['l' => implode(', ', array_unique(array_merge($curUt, $curTg)))]) . ')' : '');
                    $sum[] = "access VLAN $v";
                    if ($uplink && !$isUp) {   // VLAN, ki ga uplink ne nosi, nima poti do routerja
                        $st = $pdo->prepare('SELECT tagged FROM sw_ports WHERE device_id=? AND port=?'); $st->execute([$id, $uplink]);
                        $ut = array_map('intval', array_filter(explode(',', (string)$st->fetchColumn())));
                        $st = $pdo->prepare('SELECT untagged FROM sw_ports WHERE device_id=? AND port=?'); $st->execute([$id, $uplink]);
                        $uu = array_map('intval', array_filter(explode(',', (string)$st->fetchColumn())));
                        if (!in_array($v, $ut, true) && !in_array($v, $uu, true)) $out['warn'][] = A('VLAN {v} ni na uplinku ({u}) – naprava na tem portu ne bo imela povezave do routerja, dokler VLAN-a ne dodaš na uplink.', ['v' => $v, 'u' => $uplink]);
                    }
                }
            } elseif ($want['mode'] === 'trunk') {
                $tg = array_values(array_unique(array_filter(array_map('intval', (array)($want['tagged'] ?? [])), fn($x) => $x !== 0)));
                foreach ($tg as $t) if (!$ok($t)) { $out['err'] = A('VLAN mora biti med 1 in 4094.'); return $out; }
                $n = (int)($want['native'] ?? 0);
                if ($n !== 0 && !$ok($n)) { $out['err'] = A('VLAN mora biti med 1 in 4094.'); return $out; }
                $tg = array_values(array_diff($tg, [$n])); sort($tg);
                if (!$tg && !$n) { $out['err'] = A('Trunk potrebuje vsaj en VLAN.'); return $out; }
                if ($sp['mode'] !== 'trunk' || $curTg !== $tg || $curUt !== ($n ? [$n] : [])) {
                    $w['mode'] = 'trunk'; $w['tagged'] = $tg; $w['native'] = $n;
                    $out['cmds'][] = "/interface bridge port set [find interface=" . $q($port) . "] " . ($n ? "pvid=$n frame-types=admit-all" : 'pvid=1 frame-types=admit-only-vlan-tagged');
                    $out['cmds'][] = "/interface bridge vlan: $port " . A('tagiran v {l}', ['l' => implode(', ', $tg) ?: '–']) . ($n ? ', ' . A('netagiran v VLAN {v}', ['v' => $n]) : '');
                    $sum[] = 'trunk ' . implode(',', $tg) . ($n ? " native $n" : '');
                    if ($isUp) {
                        $mv = self::mgmtVlan($d);
                        $lost = $mv !== null ? !in_array($mv, $tg, true) && $mv !== $n : !$n;
                        if ($lost) $out['warn'][] = A('To je uplink: brez upravljalnega VLAN-a ({v}) stikalo izgubi povezavo z NOC. Varovalka bo spremembo čez 5 minut povrnila.', ['v' => $mv ?? 'native']);
                    }
                }
            }
            if ($isUp && isset($w['mode']) && !$out['warn']) $out['warn'][] = A('To je uplink stikala – napačna nastavitev prekine povezavo z NOC. Varovalka bo spremembo čez 5 minut povrnila.');
        }

        // PoE
        if (!empty($want['poe']) || !empty($want['poe_reset'])) {
            if (!$sp || (string)$sp['poe_mode'] === '') { $out['err'] = A('Port nima PoE.'); return $out; }
            if (!empty($want['poe']) && in_array($want['poe'], ['auto-on', 'off'], true) && $want['poe'] !== $sp['poe_mode']) {
                $w['poe'] = $want['poe']; $out['cmds'][] = "/interface ethernet poe set [find name=" . $q($port) . "] poe-out=" . $want['poe']; $sum[] = 'PoE ' . $want['poe'];
            }
            if (!empty($want['poe_reset'])) { $w['poe_reset'] = true; $out['cmds'][] = "/interface ethernet poe power-cycle [find name=" . $q($port) . "] duration=5s"; $sum[] = A('ponastavitev PoE'); }
        }
        $out['want'] = $w; $out['summary'] = implode(', ', $sum);
        return $out;
    }

    /** Ukazi za stikalo (/api/action): varovalka, sprememba, potrditev */
    public static function rsc(array $d, array $a, string $base, string $key, string $chk): string
    {
        $data = json_decode((string)$a['data'], true) ?: [];
        $w = (array)($data['want'] ?? []); $id = (int)$a['id']; $port = (string)$a['ip'];
        $q = fn(string $s) => '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"';
        $L = preg_replace('/[^A-Za-z0-9._+\/-]/', '_', $port);   // ime porta v logih in komentarjih (brez narekovajev in $)
        $P = $q($port); $rb = "noc-sw-rb-$id"; $pol = MikrotikScript::POLICY;
        $done = "/tool fetch url=\"$base/api/action-done/$id\"$chk http-header-field=\"X-Api-Key: $key\" output=none";
        $fail = ":do { /tool fetch url=\"$base/api/action-done/$id?fail=1\"$chk http-header-field=\"X-Api-Key: $key\" output=none } on-error={}";
        $vl = isset($w['mode']);
        // vrstica povrnitve: vsaka posebej v :do (ena neuspela ne ustavi ostalih)
        $u = fn(string $expr) => ":set nocSwRb (\$nocSwRb . \":do { \" . $expr . \" } on-error={}\\n\")\n";
        // celoten ukrep je en blok (pri /import lokalne spremenljivke ne živijo čez vrstice)
        $s = "# --- noc: sprememba porta $L (#$id)\n:do {\n"
           . ":global nocSwRb \"\"\n:global nocSwPre \"\"\n:global nocSwOk$id false\n"
           . ":local P $P\n:local B \"\"\n:local stop false\n"
           . ":do { :set B [/interface bridge port get ([/interface bridge port find where interface=\$P]->0) bridge] } on-error={}\n"
           . ":local join do={ :local r \"\"; :foreach x in=\$1 do={ :if ([:len \$r] > 0) do={ :set r (\$r . \",\") }; :set r (\$r . [:tostr \$x]) }; :return \$r }\n"
           . ":local esc do={ :local r \"\"; :local s [:tostr \$1]; :for i from=0 to=([:len \$s] - 1) do={ :local c [:pick \$s \$i]; :if (\$c = \"\\\"\" or \$c = \"\\\\\" or \$c = \"\\\$\") do={ :set r (\$r . \"\\\\\") }; :set r (\$r . \$c) }; :return \$r }\n"
           . ":local without do={ :local r [:toarray \"\"]; :foreach x in=\$1 do={ :if ([:tostr \$x] != \$2) do={ :set (\$r->[:len \$r]) [:tostr \$x] } }; :return \$r }\n"
           . ":local has do={ :foreach x in=\$1 do={ :if ([:tostr \$x] = \$2) do={ :return true } }; :return false }\n"
           . ":local inIds do={ :foreach x in=\$1 do={ :local t [:tostr \$x]; :local k [:find \$t \"-\"]; :if ([:typeof \$k] = \"num\") do={ :if (\$2 >= [:tonum [:pick \$t 0 \$k]] and \$2 <= [:tonum [:pick \$t (\$k + 1) [:len \$t]]]) do={ :return true } } else={ :if ([:tonum \$t] = \$2) do={ :return true } } }; :return false }\n";
        // 1) prejšnje stanje (prebere ga stikalo samo, tik pred spremembo)
        $s .= "# 1) trenutno stanje za povrnitev\n:local e [/interface ethernet find where name=\$P]\n";
        if (array_key_exists('comment', $w) || array_key_exists('disabled', $w))
            $s .= $u("\"/interface ethernet set [find name=\\\"\" . [\$esc \$P] . \"\\\"] disabled=\" . [/interface ethernet get \$e disabled] . \" comment=\\\"\" . [\$esc [/interface ethernet get \$e comment]] . \"\\\"\"");
        if (isset($w['poe']))
            $s .= ":do { " . trim($u("\"/interface ethernet poe set [find name=\\\"\" . [\$esc \$P] . \"\\\"] poe-out=\" . [/interface ethernet poe get [find name=\$P] poe-out]")) . " } on-error={}\n";
        if ($vl) {
            $s .= ":local bp ([/interface bridge port find where interface=\$P]->0)\n"
                . $u("\"/interface bridge port set [find interface=\\\"\" . [\$esc \$P] . \"\\\"] pvid=\" . [/interface bridge port get \$bp pvid] . \" frame-types=\" . [/interface bridge port get \$bp frame-types]")
                . ":foreach v in=[/interface bridge vlan find where bridge=\$B and dynamic=no] do={ "
                . trim($u("\"/interface bridge vlan set \" . \$v . \" vlan-ids=\" . [\$join [/interface bridge vlan get \$v vlan-ids]] . \" tagged=\\\"\" . [\$esc [\$join [/interface bridge vlan get \$v tagged]]] . \"\\\" untagged=\\\"\" . [\$esc [\$join [/interface bridge vlan get \$v untagged]]] . \"\\\"\"")) . " }\n";
        }
        // 2) varovalka: skripta povrnitve + :execute (5 min) + scheduler ob zagonu (če se stikalo vmes ponovno zažene)
        $clean = "/system script remove [find name=\\\"$rb\\\"]; /system scheduler remove [find name=\\\"$rb\\\"]";
        $s .= "# 2) varovalka: brez potrditve NOC povrnitev cez 5 minut ali ob ponovnem zagonu\n"
            . "/system script remove [find name=\"$rb\"]\n/system scheduler remove [find name=\"$rb\"]\n"
            . "/system script add name=\"$rb\" policy=$pol comment=\"noc.dezman.net\" source=(\$nocSwPre . \$nocSwRb . \":log warning \\\"noc: sprememba porta $L povrnjena (#$id)\\\"\\n\")\n"
            . "/system scheduler add name=\"$rb\" start-time=startup policy=$pol comment=\"noc.dezman.net\" on-event=\"/system script run $rb; $clean\"\n"
            . ":execute script=\":delay 300s; :global nocSwOk$id; :if (\\\$nocSwOk$id != true) do={ /system script run $rb }; $clean\"\n";
        // 3) sprememba
        $s .= "# 3) sprememba\n:onerror err in={\n";
        if (array_key_exists('comment', $w)) $s .= "    /interface ethernet set \$e comment=" . $q((string)$w['comment']) . "\n";
        if ($vl) {
            // odstrani port iz vseh nedinamičnih vnosov
            $s .= "    :foreach v in=[/interface bridge vlan find where bridge=\$B and dynamic=no] do={\n"
                . "        :local t [:toarray [/interface bridge vlan get \$v tagged]]; :local u [:toarray [/interface bridge vlan get \$v untagged]]\n"
                . "        :if ([\$has \$t \$P] or [\$has \$u \$P]) do={ /interface bridge vlan set \$v tagged=[\$without \$t \$P] untagged=[\$without \$u \$P] }\n"
                . "    }\n";
            // dodaj port v VLAN: vnos z natančno to številko (seznam se razdeli), razpon = napaka, sicer nov vnos
            $add = function (int $vid, string $how) {
                return "    :local f\n    :local fo false\n"
                    . "    :foreach v in=[/interface bridge vlan find where bridge=\$B and dynamic=no] do={ :if (!\$fo) do={ :if ([\$inIds [:toarray [/interface bridge vlan get \$v vlan-ids]] $vid]) do={ :set f \$v; :set fo true } } }\n"
                    . "    :if (\$fo) do={\n"
                    . "        :local all [:toarray [/interface bridge vlan get \$f vlan-ids]]\n"
                    . "        :local ex false; :foreach x in=\$all do={ :if ([:tostr \$x] = \"$vid\") do={ :set ex true } }\n"
                    . "        :if (!\$ex) do={ :error \"VLAN $vid je v razponu - razdeli rocno\" }\n"
                    . "        :if ([:len \$all] > 1) do={\n"
                    . "            :local ids [:toarray \"\"]; :foreach x in=\$all do={ :if ([:tostr \$x] != \"$vid\") do={ :set (\$ids->[:len \$ids]) [:tostr \$x] } }\n"
                    . "            :local nt [:toarray [/interface bridge vlan get \$f tagged]]; :local nu [:toarray [/interface bridge vlan get \$f untagged]]\n"
                    . "            /interface bridge vlan set \$f vlan-ids=\$ids\n"
                    . "            :local n [/interface bridge vlan add bridge=\$B vlan-ids=$vid tagged=\$nt untagged=\$nu comment=\"noc\"]\n"
                    . "            :set nocSwPre (\$nocSwPre . \":do { /interface bridge vlan remove \" . \$n . \" } on-error={}\\n\")\n"
                    . "            :set f \$n\n"
                    . "        }\n"
                    . "    } else={\n"
                    . "        :set f [/interface bridge vlan add bridge=\$B vlan-ids=$vid comment=\"noc\"]\n"
                    . "        :set nocSwPre (\$nocSwPre . \":do { /interface bridge vlan remove \" . \$f . \" } on-error={}\\n\")\n"
                    . "    }\n"
                    . "    :local cur [:toarray [/interface bridge vlan get \$f $how]]; :set (\$cur->[:len \$cur]) \$P\n"
                    . "    /interface bridge vlan set \$f $how=\$cur\n";
            };
            if ($w['mode'] === 'access') {
                $v = (int)$w['vlan'];
                $s .= "    :do {\n" . $add($v, 'untagged') . "    }\n"
                    . "    /interface bridge port set [find interface=\$P] pvid=$v frame-types=admit-only-untagged-and-priority-tagged\n";
            } else {
                foreach ((array)$w['tagged'] as $t) $s .= "    :do {\n" . $add((int)$t, 'tagged') . "    }\n";
                $n = (int)($w['native'] ?? 0);
                if ($n) $s .= "    :do {\n" . $add($n, 'untagged') . "    }\n    /interface bridge port set [find interface=\$P] pvid=$n frame-types=admit-all\n";
                else $s .= "    /interface bridge port set [find interface=\$P] pvid=1 frame-types=admit-only-vlan-tagged\n";
            }
        }
        if (isset($w['poe'])) $s .= "    /interface ethernet poe set [find name=\$P] poe-out=" . ($w['poe'] === 'off' ? 'off' : 'auto-on') . "\n";
        if (!empty($w['poe_reset'])) $s .= "    /interface ethernet poe power-cycle [find name=\$P] duration=5s\n";
        if (array_key_exists('disabled', $w)) $s .= "    /interface ethernet set \$e disabled=" . ($w['disabled'] ? 'yes' : 'no') . "\n";
        // nocSwPre (novi vnosi) se lahko spremeni med spremembo – posodobi izvor povrnitve
        $s .= "    /system script set [find name=\"$rb\"] source=(\$nocSwPre . \$nocSwRb . \":log warning \\\"noc: sprememba porta $L povrnjena (#$id)\\\"\\n\")\n"
            . "    :log info \"noc: sprememba porta $L izvedena (#$id)\"\n"
            . "} do={\n"
            . "    :log error (\"noc: sprememba porta $L ni uspela (#$id) - \" . \$err)\n"
            . "    :do { /system script set [find name=\"$rb\"] source=(\$nocSwPre . \$nocSwRb) } on-error={}\n"
            . "    :do { /system script run \"$rb\" } on-error={}\n"
            . "    :set nocSwOk$id true\n"
            . "    :do { /system script remove [find name=\"$rb\"]; /system scheduler remove [find name=\"$rb\"] } on-error={}\n"
            . "    $fail\n"
            . "    :set stop true\n"
            . "}\n";
        // 4) potrditev: NOC dosegljiv po spremembi -> varovalka se prekine
        $s .= "# 4) potrditev\n:if (!\$stop) do={\n:delay 3s\n"
            . ":onerror e2 in={ $done; :set nocSwOk$id true; /system script remove [find name=\"$rb\"]; /system scheduler remove [find name=\"$rb\"]; :log info \"noc: sprememba porta $L potrjena (#$id)\" } do={ :log warning (\"noc: sprememba porta $L ni potrjena - povrnitev cez 5 min - \" . \$e2) }\n"
            . "}\n"
            . "} on-error={ :log error \"noc: sprememba porta $L ni izvedena (#$id)\"; $fail }\n";
        return $s;
    }
}
