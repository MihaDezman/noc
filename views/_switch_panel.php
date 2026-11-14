<?php
declare(strict_types=1);

/**
 * Sprednja plošča stikala: porti v skupinah (liho zgoraj), lučka = povezava, črtica = VLAN (črtasto = trunk),
 * strela = PoE, rdeč rob = port je padel (odprt alarm ali padec v 24 h in še ne deluje).
 * Lebdenje pokaže povzetek (<template> za [data-port-tip]), klik odpre panel porta (app.js, [data-sw-port]).
 */
function switch_panel(array $d, bool $compact = false): string
{
    $id = (int)$d['id']; $pdo = db();
    $ifs = []; $st = $pdo->prepare('SELECT * FROM device_ifaces WHERE device_id=?'); $st->execute([$id]);
    foreach ($st as $r) if (SwitchIngest::isPort($r['name'])) $ifs[$r['name']] = $r;
    if (!$ifs) return '<div class="swpanel empty-sw' . ($compact ? ' compact' : '') . '"><div class="sw-ident"><a href="/devices/' . $id . '">' . h($d['name']) . '</a><small>' . h($d['model'] ?: 'MikroTik') . '</small></div><span class="small" style="color:#b9bdd3">' . h(A('Stikalo še ni poslalo podatkov o portih.')) . '</span></div>';
    $sp = []; $st = $pdo->prepare('SELECT * FROM sw_ports WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) $sp[$r['port']] = $r;
    $vn = []; $st = $pdo->prepare('SELECT vlan_id, comment FROM sw_vlans WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) if ($r['comment'] !== '') $vn[(int)$r['vlan_id']] = $r['comment'];
    $sfp = []; $st = $pdo->prepare('SELECT * FROM sfp_state WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) $sfp[$r['iface']] = $r;
    $al = []; $st = $pdo->prepare("SELECT SUBSTRING(akey, 7) p FROM alerts WHERE device_id=? AND akey LIKE 'iface:%' AND ended_at IS NULL"); $st->execute([$id]); foreach ($st as $r) $al[$r['p']] = true;
    $dn = []; $st = $pdo->prepare("SELECT DISTINCT iface FROM iface_events WHERE device_id=? AND kind='down' AND ts > NOW() - INTERVAL 24 HOUR"); $st->execute([$id]); foreach ($st as $r) $dn[$r['iface']] = true;
    $hosts = SwitchIngest::hostsByPort($d);
    $uplink = SwitchIngest::uplink($d);
    $watch = Devices::list((string)$d['down_ifaces']);
    $used = [];

    $vlanTxt = function (int $v) use ($vn): string { return 'VLAN ' . $v . (isset($vn[$v]) ? ' ' . $vn[$v] : ''); };
    $port = function (string $n) use ($ifs, $sp, $sfp, $al, $dn, $hosts, $uplink, $watch, $id, $vlanTxt, &$used): string {
        $i = $ifs[$n]; $p = $sp[$n] ?? null; $h = $hosts[$n] ?? null;
        $up = (int)$i['running'] === 1; $dis = (int)$i['disabled'] === 1;
        $down = !$up && !$dis && (isset($al[$n]) || isset($dn[$n]));
        $mode = $p['mode'] ?? 'none';
        $ut = $p ? array_map('intval', array_filter(explode(',', (string)$p['untagged']))) : [];
        $tg = $p ? array_map('intval', array_filter(explode(',', (string)$p['tagged']))) : [];
        $vid = $ut[0] ?? (int)($p['pvid'] ?? 1);
        if ($mode === 'trunk') { foreach ($tg as $t) $used[$t] = true; $bar = 'trunk'; }
        else { $used[$vid] = true; $bar = 'background:' . SwLayout::vlanColor($vid); }
        $poe = $p && $p['poe_w'] !== null && (float)$p['poe_w'] > 0;
        $cls = 'swp' . ($up ? ' up' : '') . ($dis ? ' dis' : '') . ($down ? ' down' : '') . ($poe ? ' poe' : '') . (preg_match('/^(sfp|qsfp)/', $n) ? ' sfp' : '') . ($n === $uplink ? ' uplink' : '');
        // povzetek ob lebdenju
        $state = $dis ? A('izklopljen') : ($up ? A('povezan') . ($i['rate'] ? ' · ' . $i['rate'] : '') : ($down ? A('padel') : A('brez povezave')));
        $t = '<div class="pt-head"><b>' . h(A('Port {n}', ['n' => SwLayout::label($n)])) . '</b> <span class="mono faint">' . h($n) . '</span>'
           . ($n === $uplink ? ' <span class="tag violet">uplink</span>' : '') . '<span class="pt-state ' . ($dis ? '' : ($up ? 'up' : 'down')) . '">' . h($state) . '</span></div>';
        if ($i['comment'] !== '') $t .= '<div class="pt-com">' . h($i['comment']) . '</div>';
        $rows = [];
        if ($h) $rows[] = [A('Naprava'), h($h['name'] !== '' ? $h['name'] : $h['mac']) . ($h['ip'] !== '' ? ' <span class="mono faint">' . h($h['ip']) . '</span>' : '') . ($h['n'] > 1 ? ' <span class="faint">' . h(A('+{n} naprav', ['n' => $h['n'] - 1])) . '</span>' : '')];
        if ($mode === 'trunk') $rows[] = ['VLAN', h('trunk: ' . implode(', ', $tg)) . ($ut ? ' <span class="faint">(native ' . (int)$ut[0] . ')</span>' : '')];
        elseif ($p) $rows[] = ['VLAN', '<i class="sw-dot" style="background:' . SwLayout::vlanColor($vid) . '"></i>' . h($vlanTxt($vid))];
        if ($poe) $rows[] = ['PoE', h(number_format((float)$p['poe_w'], 1, ',', '') . ' W')];
        if ((int)$i['rx_error'] + (int)$i['tx_error'] > 0) $rows[] = [A('Napake'), '<b style="color:var(--warn)">' . ((int)$i['rx_error'] + (int)$i['tx_error']) . '</b> <span class="faint">' . h(A('od zagona')) . '</span>'];
        if (isset($sfp[$n]) && (int)$sfp[$n]['present'] && $sfp[$n]['rx'] !== null) $rows[] = ['SFP RX', h(number_format((float)$sfp[$n]['rx'], 2, ',', '') . ' dBm')];
        if (in_array($n, $watch, true)) $rows[] = [A('Obvestilo'), h(A('ob padcu in vrnitvi'))];
        if ($rows) $t .= '<dl>' . implode('', array_map(fn($r) => '<dt>' . h($r[0]) . '</dt><dd>' . $r[1] . '</dd>', $rows)) . '</dl>';
        return '<button type="button" class="' . $cls . '" data-port-tip data-sw-port="' . h($n) . '" data-dev="' . $id . '" aria-label="' . h(A('Port {n}', ['n' => $n])) . '">'
             . '<span class="led"></span><span class="n">' . h(SwLayout::label($n)) . '</span><i class="vbar' . ($bar === 'trunk' ? ' trunk' : '') . '"' . ($bar === 'trunk' ? '' : ' style="' . $bar . '"') . '></i>'
             . '<template>' . $t . '</template></button>';
    };

    $o = '<div class="swpanel' . ($compact ? ' compact' : '') . '" data-sw="' . $id . '">'
       . '<div class="sw-ident">' . ($compact ? '<a href="/devices/' . $id . '">' . h($d['name']) . '</a>' : h($d['name'])) . '<small>' . h($d['model'] ?: 'MikroTik') . '</small></div><div class="sw-groups">';
    foreach (SwLayout::groups((string)$d['model'], array_keys($ifs)) as $g)
        $o .= '<div class="sw-grp r' . $g['rows'] . '">' . implode('', array_map($port, $g['ports'])) . '</div>';
    $o .= '</div></div>';
    ksort($used);
    $o .= '<div class="sw-legend">' . implode('', array_map(fn($v) => '<span><i style="background:' . SwLayout::vlanColor($v) . '"></i>' . h($vlanTxt($v)) . '</span>', array_keys($used)))
        . '<span><i class="trunk"></i>trunk</span><span>⚡ PoE</span><span class="lg-down">' . h(A('rdeč rob = port je padel')) . '</span></div>';
    return $o;
}
