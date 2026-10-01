<?php
declare(strict_types=1);

/**
 * Paket za MikroTik (RouterOS 7.13+): push skripta (1 min), nočni backup /export, ping s strežnika, traffic-flow.
 * Vse je zgrajeno iz analize /export (MikrotikExport) in nastavitev naprave.
 */
final class MikrotikScript
{
    public const POLICY = 'read,write,test,ftp,policy';   // RouterOS 7.24+: brez "policy" fetch iz schedulerja odpove

    /** [ime datoteke => vsebina] */
    public static function package(array $d): array
    {
        $key = dec($d['api_key_enc'] ?? null);
        $an = json_decode((string)($d['analysis_json'] ?? ''), true) ?: [];
        return [
            'NAVODILA.txt'     => self::readme($d, $an, $key),
            'noc-install.rsc'  => self::install($d, $an, $key),
            'noc-push.rsc'     => "# Ročno lepljenje: System > Scripts > noc-push (policy: " . self::POLICY . ")\n" . self::pushBody($d, $key),
            'noc-backup.rsc'   => "# Ročno lepljenje: System > Scripts > noc-backup (policy: " . self::POLICY . ")\n" . self::backupBody($d, $key),
            'noc-remove.rsc'   => self::remove($d),
        ];
    }

    private static function esc(string $s): string { return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s); }
    private static function q(string $s): string { return '"' . self::esc($s) . '"'; }

    public static function pushBody(array $d, string $key): string
    {
        $base = rtrim((string)cfg('base_url'), '/');
        $gwFallback = filter_var($d['wan_gateway'] ?? '', FILTER_VALIDATE_IP) ? $d['wan_gateway'] : '';
        $ext = trim((string)($d['ping_target'] ?? '')) ?: '1.1.1.1';
        $inc = (string)($d['log_include'] ?? ''); $exc = (string)($d['log_exclude'] ?? '');
        return <<<ROS
# NOC push – {$d['name']} – generirano {$GLOBALS['__gen']}
:local url "$base/api/push"
:local key "$key"
:local gwFallback "$gwFallback"
:local extTarget "$ext"
:local logInc "$inc"
:local logExc "$exc"

:global nocTick
:global nocLastLog
:global nocUpd
:if ([:typeof \$nocTick] != "num") do={ :set nocTick 0 }
:if ([:typeof \$nocLastLog] != "num") do={ :set nocLastLog 0 }
:if ([:typeof \$nocUpd] != "array") do={ :set nocUpd [:toarray ""] }
:set nocTick (\$nocTick + 1)

# --- sistem
:local r [/system resource get]
:local res {"uptime"=[:tostr (\$r->"uptime")];"cpu"=(\$r->"cpu-load");"cpus"=(\$r->"cpu-count");"memT"=(\$r->"total-memory");"memF"=(\$r->"free-memory");"hddT"=(\$r->"total-hdd-space");"hddF"=(\$r->"free-hdd-space");"ver"=(\$r->"version");"board"=(\$r->"board-name");"arch"=(\$r->"architecture-name")}
:local rb [:toarray ""]
:do { :local b [/system routerboard get]; :set rb {"model"=(\$b->"model");"serial"=(\$b->"serial-number");"fw"=(\$b->"current-firmware");"fwUp"=(\$b->"upgrade-firmware")} } on-error={}
:local ident [/system identity get name]

# --- senzorji (imena so odvisna od modela; pošljemo vse)
:local hl [:toarray ""]
:do { :foreach h in=[/system health find] do={ :set (\$hl->[/system health get \$h name]) [:tostr [/system health get \$h value]] } } on-error={}

# --- vmesniki
:local ifs [:toarray ""]
:foreach i in=[/interface find] do={
    :local x [/interface get \$i]
    :if ([:tostr (\$x->"dynamic")] != "true") do={
        :set (\$ifs->[:len \$ifs]) {"n"=(\$x->"name");"t"=(\$x->"type");"run"=(\$x->"running");"dis"=(\$x->"disabled");"rx"=(\$x->"rx-byte");"tx"=(\$x->"tx-byte");"rxe"=(\$x->"rx-error");"txe"=(\$x->"tx-error");"ld"=(\$x->"link-downs");"mac"=[:tostr (\$x->"mac-address")];"c"=[:tostr (\$x->"comment")];"lu"=[:tostr (\$x->"last-link-up-time")]}
    }
}
# hitrost povezave (vsakih 10 min)
:local rates [:toarray ""]
:if ((\$nocTick % 10) = 1) do={
    :foreach e in=[/interface ethernet find where running=yes] do={
        :local nm [/interface ethernet get \$e name]
        :do { :local mo [/interface ethernet monitor \$e once as-value]; :set (\$rates->\$nm) [:tostr (\$mo->"rate")] } on-error={}
    }
}

# --- ping: privzeti prehod (WAN) in zunanji cilj
# navaden /ping (flood-ping je na novejsih RouterOS omejen z device-mode); case izracuna NOC
:local gw ""
:do { :set gw [:tostr [/ip route get ([/ip route find where dst-address=0.0.0.0/0 active=yes]->0) gateway]] } on-error={}
:if ([:typeof [:find \$gw "%"]] = "num") do={ :set gw [:pick \$gw 0 [:find \$gw "%"]] }
# PPPoE / point-to-point: pot kaze na vmesnik -> prehod je "network" naslova na tem vmesniku
:if ([:typeof [:toip \$gw]] != "ip" && [:len \$gw] > 0) do={
    :do { :set gw [:tostr [/ip address get ([/ip address find where interface=\$gw]->0) network]] } on-error={ :set gw "" }
}
:if ([:typeof [:toip \$gw]] != "ip") do={ :set gw \$gwFallback }
:local gwOn false
:local gwT [:toarray ""]
:if ([:len \$gw] > 0) do={
    :set gwOn true
    :do { :foreach p in=[/ping address=[:toip \$gw] count=5 interval=200ms as-value] do={ :set (\$gwT->[:len \$gwT]) [:tostr (\$p->"time")] } } on-error={}
}
:local extT [:toarray ""]
:local extIp \$extTarget
:if ([:typeof [:toip \$extTarget]] != "ip") do={ :do { :set extIp [:resolve \$extTarget] } on-error={ :set extIp "" } }
:do { :foreach p in=[/ping address=[:toip \$extIp] count=5 interval=200ms as-value] do={ :set (\$extT->[:len \$extT]) [:tostr (\$p->"time")] } } on-error={}
:local ping {"v"=2;"gw"=\$gw;"gwOn"=\$gwOn;"gwT"=\$gwT;"ext"=\$extTarget;"extT"=\$extT;"cnt"=5}

# --- povezave, DHCP
:local conns -1
:do { :set conns [/ip firewall connection tracking get total-entries] } on-error={}
:local nLeases [:len [/ip dhcp-server lease find where status=bound]]
:local leases [:toarray ""]
:if ((\$nocTick % 5) = 1) do={
    :foreach l in=[/ip dhcp-server lease find where status=bound] do={
        :local lv [/ip dhcp-server lease get \$l]
        :set (\$leases->[:len \$leases]) {"m"=[:tostr (\$lv->"active-mac-address")];"a"=[:tostr (\$lv->"active-address")];"h"=[:tostr (\$lv->"host-name")];"s"=[:tostr (\$lv->"server")];"c"=[:tostr (\$lv->"comment")]}
    }
}

# --- zasedenost IP poolov (vsakih 5 min)
:local pools [:toarray ""]
:if ((\$nocTick % 5) = 1) do={
    :do {
        :foreach pl in=[/ip pool find] do={
            :local pn [/ip pool get \$pl name]
            :set (\$pools->[:len \$pools]) {"n"=\$pn;"r"=[:tostr [/ip pool get \$pl ranges]];"u"=[:len [/ip pool used find where pool=\$pn]]}
        }
    } on-error={}
}

# --- WireGuard
:local wg [:toarray ""]
:do {
    :foreach p in=[/interface wireguard peers find] do={
        :local pv [/interface wireguard peers get \$p]
        :set (\$wg->[:len \$wg]) {"i"=[:tostr (\$pv->"interface")];"n"=[:tostr (\$pv->"name")];"c"=[:tostr (\$pv->"comment")];"ep"=[:tostr (\$pv->"current-endpoint-address")];"hs"=[:tostr (\$pv->"last-handshake")];"rx"=(\$pv->"rx");"tx"=(\$pv->"tx");"dis"=(\$pv->"disabled")}
    }
} on-error={}

# --- logi (nove vrstice od zadnjega uspešnega pusha)
:local logs [:toarray ""]
:local maxid \$nocLastLog
:local cnt 0
:foreach e in=[/log find] do={
    :local es [:tostr \$e]
    :local idn [:tonum ("0x" . [:pick \$es 1 [:len \$es]])]
    :if (\$idn > \$nocLastLog && \$cnt < 200) do={
        :local lv [/log get \$e]
        :local tp [:tostr (\$lv->"topics")]
        :if ((\$tp ~ \$logInc) && !(\$tp ~ \$logExc)) do={
            :set (\$logs->\$cnt) {"t"=[:tostr (\$lv->"time")];"tp"=\$tp;"m"=[:tostr (\$lv->"message")]}
            :set cnt (\$cnt + 1)
        }
        :set maxid \$idn
    }
}

:local body [:serialize to=json value={"v"=1;"tick"=\$nocTick;"ident"=\$ident;"res"=\$res;"rb"=\$rb;"health"=\$hl;"ifs"=\$ifs;"rates"=\$rates;"ping"=\$ping;"conns"=\$conns;"nLeases"=\$nLeases;"leases"=\$leases;"pools"=\$pools;"wg"=\$wg;"logs"=\$logs;"upd"=\$nocUpd}]

# :onerror zapiše dejansko sporočilo RouterOS (npr. "not enough permissions", "failure: ... 401")
:onerror e in={
    :local out [/tool fetch url=\$url http-method=post http-data=\$body http-header-field=("Content-Type: application/json,X-Api-Key: " . \$key) output=user as-value]
    :if ((\$out->"status") = "finished") do={ :set nocLastLog \$maxid }
} do={ :log warning ("noc: push ni uspel - " . \$e) }
ROS;
    }

    public static function backupBody(array $d, string $key): string
    {
        $base = rtrim((string)cfg('base_url'), '/');
        return <<<ROS
# NOC nočni backup konfiguracije + preverjanje posodobitev – {$d['name']}
:local url "$base/api/backup"
:local key "$key"
:global nocUpd

:do {
    /system package update check-for-updates once
    :delay 15s
    :local u [/system package update get]
    :set nocUpd {"inst"=(\$u->"installed-version");"latest"=(\$u->"latest-version");"ch"=(\$u->"channel");"st"=(\$u->"status")}
} on-error={}

:local f "noc-export.rsc"
:do { /file remove [find name=\$f] } on-error={}
/export terse file=noc-export
:delay 5s
:local size [/file get \$f size]
:local uid [:tostr [:rndnum from=100000000 to=999999999]]
:local off 0
:local part 0
:while (\$off < \$size) do={
    :local chunk ([/file read file=\$f offset=\$off chunk-size=30000 as-value]->"data")
    :onerror e in={
        /tool fetch url=\$url http-method=post http-data=\$chunk http-header-field=("Content-Type: text/plain,X-Api-Key: " . \$key . ",X-Upload: " . \$uid . ",X-Part: " . \$part . ",X-Size: " . \$size) output=none
    } do={ :log warning ("noc: backup ni uspel - " . \$e); :set off \$size }
    :set off (\$off + 30000)
    :set part (\$part + 1)
}
/file remove [find name=\$f]
ROS;
    }

    private static function install(array $d, array $an, string $key): string
    {
        $GLOBALS['__gen'] = date('Y-m-d H:i');
        $noc = (string)cfg('noc_ip'); $port = (int)cfg('flow_port', 2055); $pol = self::POLICY;
        $min = random_int(5, 55); $hour = random_int(2, 4);
        $s = "# NOC – namestitev za \"{$d['name']}\" (" . ($d['model'] ?: 'MikroTik') . ")\n# Generirano " . date('Y-m-d H:i') . " – noc.dezman.net\n# Uvoz: /import noc-install.rsc\n\n";
        $s .= "# --- počisti prejšnjo namestitev\n/system scheduler remove [find name~\"^noc-\"]\n/system script remove [find name~\"^noc-\"]\n\n";
        $s .= "# --- skripti\n/system script add name=noc-push policy=$pol comment=\"noc.dezman.net\" source=\"" . self::esc(self::pushBody($d, $key)) . "\"\n\n";
        $s .= "/system script add name=noc-backup policy=$pol comment=\"noc.dezman.net\" source=\"" . self::esc(self::backupBody($d, $key)) . "\"\n\n";
        $s .= "# --- urnik: push vsako minuto, backup enkrat dnevno\n";
        $s .= "/system scheduler add name=noc-push interval=1m start-time=startup on-event=noc-push policy=$pol comment=\"noc.dezman.net\"\n";
        $s .= sprintf("/system scheduler add name=noc-backup interval=1d start-time=%02d:%02d:00 on-event=noc-backup policy=%s comment=\"noc.dezman.net\"\n\n", $hour, $min, $pol);

        if (!empty($an['needs_icmp_rule']) && $noc !== '') {
            $s .= "# --- ping s strežnika NOC (input chain na tem routerju zavrže ICMP z interneta)\n";
            $s .= ":if ([:len [/ip firewall filter find comment~\"^noc: ping s\"]] = 0) do={\n";
            $s .= "    :if ([:len [/ip firewall filter find]] > 0) do={\n";
            $s .= "        /ip firewall filter add chain=input protocol=icmp src-address=$noc action=accept comment=\"noc: ping s streznika\" place-before=([/ip firewall filter find]->0)\n";
            $s .= "    } else={ /ip firewall filter add chain=input protocol=icmp src-address=$noc action=accept comment=\"noc: ping s streznika\" }\n}\n\n";
        }
        if ((int)$d['flow_enabled'] === 1 && $noc !== '') {
            if (!empty($an['flow_existing'])) {
                $s .= "# --- traffic-flow je na routerju že nastavljen (" . implode(', ', $an['flow_targets'] ?? []) . ") – dodamo samo NOC kot dodaten cilj\n";
            } else {
                $s .= "# --- traffic-flow (promet po napravah v LAN-u)\n/ip traffic-flow set enabled=yes interfaces=all active-flow-timeout=1m inactive-flow-timeout=15s\n";
            }
            $s .= ":if ([:len [/ip traffic-flow target find dst-address=$noc]] = 0) do={ /ip traffic-flow target add dst-address=$noc port=$port version=ipfix }\n\n";
        }
        $s .= "# --- fetch,info ne polni loga (vsak push bi zapisal vrstico 'Download ... FINISHED'); napake fetcha se se vedno belezijo\n";
        $s .= ":foreach r in=[/system logging find] do={ :if ([:tostr [/system logging get \$r topics]] = \"info\") do={ /system logging set \$r topics=info,!fetch } }\n\n";
        $s .= "# --- prvi zagon\n/system script run noc-push\n:log info \"noc: nameščeno – push vsako minuto, backup ob " . sprintf('%02d:%02d', $hour, $min) . "\"\n";
        return $s;
    }

    private static function remove(array $d): string
    {
        $noc = (string)cfg('noc_ip');
        return "# NOC – odstranitev z routerja \"{$d['name']}\"\n/system scheduler remove [find name~\"^noc-\"]\n/system script remove [find name~\"^noc-\"]\n"
            . "/ip firewall filter remove [find comment=\"noc: ping s streznika\"]\n/ip firewall filter remove [find comment=\"noc: ping s tigra\"]\n"
            . "/ip traffic-flow target remove [find dst-address=$noc]\n"
            . ":if ([:len [/ip traffic-flow target find]] = 0) do={ /ip traffic-flow set enabled=no }\n"
            . ":foreach r in=[/system logging find] do={ :if ([:tostr [/system logging get \$r topics]] = \"info;!fetch\") do={ /system logging set \$r topics=info } }\n"
            . "/system script environment remove [find name~\"^noc\"]\n:log info \"noc: odstranjeno\"\n";
    }

    private static function readme(array $d, array $an, string $key): string
    {
        $ver = $an['ros_version'] ?? $d['os_version'];
        $warn = [];
        foreach ($an['warnings'] ?? [] as $w) $warn[] = '  - ' . $w;
        $t = "NOC – paket za napravo \"{$d['name']}\"\n"
           . "Naročnik: " . ($d['tenant_name'] ?? '–') . "   Model: " . ($d['model'] ?: '?') . "   RouterOS: " . ($ver ?: '?') . "\n"
           . "WAN: " . ($d['wan_iface'] ?: '?') . "   Javni IP: " . ($d['public_ip'] ?: '?') . "   LAN: " . ($d['lan_networks'] ?: '?') . "\n"
           . "Generirano: " . date('Y-m-d H:i') . "\n\n";
        if ($key === '') $t .= "POZOR: API ključ ni na voljo – v NOC klikni \"Nov ključ\" in paket prenesi znova.\n\n";
        if ($warn) $t .= "OPOZORILA IZ ANALIZE\n" . implode("\n", $warn) . "\n\n";
        $t .= "POSTOPEK\n"
           . "1. Device-mode mora dovoliti scheduler in fetch (traffic-flow je dovoljen privzeto):\n"
           . "     /system/device-mode/print\n"
           . "   Če scheduler ali fetch nista \"yes\":\n"
           . "     /system/device-mode/update scheduler=yes fetch=yes\n"
           . "   in v 5 minutah potrdi s pritiskom na reset gumb ali izklopom/vklopom napajanja.\n"
           . "2. Naloži noc-install.rsc v Files (Winbox: povleci datoteko v okno Files).\n"
           . "3. Terminal:  /import noc-install.rsc\n"
           . "   Preveri:   /log print where message~\"noc\"\n"
           . "4. V NOC se naprava v 1–2 minutah obarva zeleno. Prvi backup konfiguracije pride ponoči,\n"
           . "   takoj ga sprožiš z:  /system script run noc-backup\n"
           . "5. Promet po napravah (traffic-flow) se pokaže v 5–10 minutah, ko bin/ufw-sync.sh na strežniku odpre UDP $GLOBALS[__port] za ta IP.\n\n"
           . "Če /import javi napako pri skripti (narekovaji): System > Scripts > Add, ime noc-push oz. noc-backup,\n"
           . "policy " . self::POLICY . ", in prilepi vsebino datotek noc-push.rsc / noc-backup.rsc.\n\n"
           . "ODSTRANITEV:  /import noc-remove.rsc\n";
        return $t;
    }

    /** RouterOS terminal ne mara UTF-8 – .rsc datoteke so čisti ASCII */
    public static function ascii(string $s): string
    {
        $s = strtr($s, ['–' => '-', '—' => '-', '→' => '->', '…' => '...', '„' => '"', '“' => '"', '”' => '"', '’' => "'"]);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t === false ? $s : $t;
    }

    public static function zip(array $files, string $prefix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'noc'); $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $n => $c) $z->addFromString($prefix . '/' . $n, str_ends_with($n, '.txt') ? str_replace("\n", "\r\n", $c) : self::ascii($c));
        $z->close(); $data = (string)file_get_contents($tmp); unlink($tmp);
        return $data;
    }
}
$GLOBALS['__gen'] = date('Y-m-d H:i');
$GLOBALS['__port'] = (string)cfg('flow_port', 2055);
