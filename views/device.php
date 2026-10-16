<?php
$id = (int)$d['id']; $s = $d['status']; $hh = $s['h'] ?? []; $res = $s['res'] ?? [];
$isSuper = Auth::isSuper();
$ports = face_ports($ifaces, $d['wan_iface'], 40);
$wanRow = null; foreach ($ifaces as $i) if ($i['name'] === $d['wan_iface']) $wanRow = $i;
$tabs = ['overview' => ['dashboard', A('Pregled')], 'ifaces' => ['ethernet', A('Vmesniki')], 'clients' => ['laptop', A('Naprave v LAN')], 'sla' => ['gauge', A('Razpoložljivost')], 'security' => ['shield', A('Varnost')], 'logs' => ['scroll', A('Logi')], 'alerts' => ['bell', A('Alarmi')]];
$manage = in_array($tab, ['backups', 'settings', 'protect'], true);   // upravljanje: odpre se z gumbom v glavi, ne kot zavihek
$upd = $s['upd'] ?? [];
?>
<div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?><?= h($d['tenant_name'] ?: '–') ?></div>
<div class="dev-hero">
  <div class="dev-title">
    <span class="dev-icon"><?= device_art((string)$d['model'], (string)$d['kind'], 46) ?></span>
    <div>
      <h1><?= h($d['name']) ?> <?= state_tag($d) ?></h1>
      <div class="dev-meta">
        <?php if ($d['site']): ?><span><?= icon('map', 14) ?><?= h($d['site']) ?></span><?php endif; ?>
        <span><?= icon('box', 14) ?><?= h($d['model'] ?: 'MikroTik') ?></span>
        <span><?= icon('tag', 14) ?>RouterOS <?= h($d['os_version'] ?: '?') ?></span>
        <?php if ($d['public_ip']): ?><span class="mono"><?= icon('globe', 14) ?><?= h($d['public_ip']) ?></span><?php endif; ?>
        <span><?= icon('clock', 14) ?><?= h(A('uptime {u}', ['u' => fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null)])) ?></span>
        <span><?= icon('refresh', 14) ?><?= h(fmt_ago($d['last_seen_at'])) ?></span>
      </div>
    </div>
  </div>
  <?php if ($isSuper): ?>
  <div class="actions">
    <form method="post" action="/devices/<?= $id ?>/mute" class="actions">
      <?= Auth::csrf() ?>
      <?php if ($d['muted']): ?>
        <span class="small muted"><?= h(A('Utišano do {t}', ['t' => fmt_dt($d['mute_until'])])) ?></span><input type="hidden" name="hours" value="0"><button class="btn sm"><?= icon('bell', 15) ?><?= h(A('Vklopi obvestila')) ?></button>
      <?php else: ?>
        <select name="hours" style="width:auto" aria-label="<?= h(A('Utišaj obvestila')) ?>"><option value="1">1 h</option><option value="4">4 h</option><option value="24">24 h</option><option value="168">7 d</option></select>
        <button class="btn sm" title="<?= h(A('Vzdrževanje: alarmi se beležijo, obvestila ne gredo ven')) ?>"><?= icon('bell-off', 15) ?><?= h(A('Utišaj')) ?></button>
      <?php endif; ?>
    </form>
    <a class="btn sm <?= $tab === 'backups' ? 'on' : '' ?>" href="/devices/<?= $id ?>?tab=backups"><?= icon('archive', 15) ?><?= h(A('Konfiguracija')) ?></a>
    <a class="btn sm <?= $tab === 'protect' ? 'on' : '' ?>" href="/devices/<?= $id ?>?tab=protect"><?= icon('shield', 15) ?><?= h(A('Zaščita')) ?><?php if (($d['filter_profile'] ?? 'off') !== 'off'): ?> <span class="dot up" style="margin-left:2px"></span><?php endif; ?></a>
    <a class="btn sm <?= $tab === 'wg' ? 'on' : '' ?>" href="/devices/<?= $id ?>?tab=wg"><?= icon('lock', 15) ?>WireGuard</a>
    <a class="btn sm <?= $tab === 'settings' ? 'on' : '' ?>" href="/devices/<?= $id ?>?tab=settings"><?= icon('settings', 15) ?><?= h(A('Nastavitve')) ?></a>
    <a class="btn sm" href="/devices/<?= $id ?>/install"><?= icon('download', 15) ?><?= h(A('Paket')) ?></a>
  </div>
  <?php endif; ?>
</div>

<?php if ($ports): ?>
<div class="faceplate" aria-label="<?= h(A('Vrata naprave')) ?>">
  <div class="ident"><?= h($d['name']) ?><small><?= h($d['model'] ?: 'MikroTik') ?></small></div>
  <?php
    // SFP stanje in dušenje za okno ob portu
    $fpSfp = []; $st = db()->prepare('SELECT * FROM sfp_state WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) $fpSfp[$r['iface']] = $r;
    $fpAtt = function (?array $sf) {
        if (!$sf || $sf['rx'] === null) return null;
        if ($sf['remote_tx'] !== null) return (float)$sf['remote_tx'] - (float)$sf['rx'];
        if ($sf['peer_device']) { $q = db()->prepare('SELECT tx FROM sfp_state WHERE device_id=? AND iface=?'); $q->execute([$sf['peer_device'], $sf['peer_iface']]); $tx = $q->fetchColumn(); if ($tx !== false && $tx !== null) return (float)$tx - (float)$sf['rx']; }
        return null;
    };
  ?>
  <div class="ports"><?php foreach ($ports as $p): $sf = $fpSfp[$p['name']] ?? ($p['sfp'] ? ['present' => 0] : null); ?><div class="port-wrap" tabindex="0" data-port-tip><?= port_html($p, false) ?><span><?= h(preg_replace(['/^ether/', '/^sfp-sfpplus/', '/^qsfp28-/', '/^combo/'], ['', 'sfp', 'q', 'c'], $p['name'])) ?></span><template><?= port_tip($p, $sf, $fpAtt($sf['present'] ?? 0 ? $sf : null)) ?></template></div><?php endforeach; ?></div>
  <?php if ($d['serial']): ?><div class="serial">S/N <?= h($d['serial']) ?></div><?php endif; ?>
</div>
<?php else: ?><div style="height:18px"></div><?php endif; ?>

<?php if ($manage): ?>
<?php $mt = ['backups' => ['archive', A('Konfiguracija')], 'settings' => ['settings', A('Nastavitve')], 'protect' => ['shield', A('Zaščita omrežja')]][$tab]; ?>
<div class="manage-head"><a href="/devices/<?= $id ?>" class="small"><?= icon('chev', 14, 'flip') ?><?= h(A('Nazaj na pregled naprave')) ?></a><h2><?= icon($mt[0], 18) ?><?= h($mt[1]) ?></h2></div>
<?php else: ?>
<nav class="tabs">
  <?php foreach ($tabs as $k => [$ic, $lbl]): ?><a href="/devices/<?= $id ?>?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= icon($ic, 16) ?><?= h($lbl) ?><?= $k === 'alerts' && $alerts ? ' <span class="tag down">' . count($alerts) . '</span>' : '' ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
<?php if (!$d['last_seen_at']): ?>
  <div class="panel"><div class="empty"><?= icon('clock', 34) ?><h2><?= h(A('Naprava še ni poslala podatkov')) ?></h2><p><?= h(A('Naloži paket na router – podatki pridejo v minuti.')) ?></p><?php if ($isSuper): ?><a class="btn primary" href="/devices/<?= $id ?>/install"><?= icon('download', 16) ?><?= h(A('Navodila za namestitev')) ?></a><?php endif; ?></div></div>
<?php else: ?>
<div class="grid g-main">
  <div>
    <section class="panel">
      <div class="panel-head">
        <h2><?= icon('activity', 18) ?><?= h(A('Promet WAN')) ?> <span class="faint mono small"><?= h($d['wan_iface']) ?></span></h2>
        <?= range_seg(['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d', '12m' => '12 m'], '24h', 'wan') ?>
      </div>
      <div class="panel-body">
        <div class="big-rate" style="margin-bottom:14px">
          <div><span><?= icon('arrow-down', 14) ?><?= h(A('Download zdaj')) ?></span><b class="down-c"><?= $wanRow ? h(fmt_bps($wanRow['rx_bps'])) : '–' ?></b></div>
          <div><span><?= icon('arrow-up', 14) ?><?= h(A('Upload zdaj')) ?></span><b class="up-c"><?= $wanRow ? h(fmt_bps($wanRow['tx_bps'])) : '–' ?></b></div>
          <?php if ($vol): ?>
          <div><span><?= h(A('Danes')) ?></span><b><?= h(fmt_bytes($vol['today']['rx'] + $vol['today']['tx'])) ?></b></div>
          <div><span><?= h(A('Ta mesec')) ?></span><b><?= h(fmt_bytes($vol['month']['rx'] + $vol['month']['tx'])) ?></b></div>
          <?php endif; ?>
          <?php if ($d['wan_down_mbps'] && $wanRow): ?><div><span><?= h(A('Zasedenost')) ?></span><b><?= round($wanRow['rx_bps'] / ($d['wan_down_mbps'] * 1e6) * 100) ?> %</b></div><?php endif; ?>
        </div>
        <div id="wan" data-chart-group><?= chart($id, 'traffic', '24h', $d['wan_iface'], 250) ?></div>
        <div class="legend" style="margin-top:8px"><span><i style="background:var(--aqua)"></i><?= h(A('Download')) ?></span><span><i style="background:var(--violet)"></i><?= h(A('Upload')) ?></span><?php if ($d['wan_down_mbps']): ?><span class="faint"><?= h(A('Zakup {d}/{u} Mb/s', ['d' => $d['wan_down_mbps'], 'u' => $d['wan_up_mbps'] ?: '?'])) ?></span><?php endif; ?></div>
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('ping', 18) ?><?= h(A('Latenca in izguba')) ?></h2><?= range_seg(['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d'], '24h', 'ping') ?></div>
      <div class="panel-body">
        <div id="ping" data-chart-group><?= chart($id, 'ping', '24h', '', 200) ?></div>
        <div class="legend" style="margin-top:8px"><span><i style="background:var(--violet)"></i><?= h(A('Prehod')) ?> <span class="mono"><?= h($s['ping']['gw'] ?? '') ?></span></span><span><i style="background:var(--aqua)"></i><?= h(A('Internet')) ?> <span class="mono"><?= h($d['ping_target']) ?></span></span><span><i style="background:var(--down)"></i><?= h(A('izguba paketov')) ?></span></div>
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('cpu', 18) ?><?= h(A('CPU in pomnilnik')) ?></h2><?= range_seg(['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d'], '24h', 'cpu') ?></div>
      <div class="panel-body"><div id="cpu" data-chart-group><?= chart($id, 'cpu', '24h', '', 180) ?></div>
        <div class="legend" style="margin-top:8px"><span><i style="background:var(--violet)"></i>CPU</span><span><i style="background:var(--yellow)"></i><?= h(A('Pomnilnik')) ?></span></div></div>
    </section>
  </div>
  <div>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('gauge', 18) ?><?= h(A('Zdravje')) ?></h2></div>
      <div class="panel-body"><div class="gauges">
        <?= gauge(isset($hh['cpu']) ? (float)$hh['cpu'] : null, 'CPU', '%', 100, 60, 85) ?>
        <?= gauge(isset($hh['mem_pct']) ? (float)$hh['mem_pct'] : null, A('Pomnilnik')) ?>
        <?= gauge(isset($hh['hdd_pct']) ? (float)$hh['hdd_pct'] : null, A('Disk')) ?>
        <?= gauge(isset($hh['temp']) ? (float)$hh['temp'] : null, A('Temp.'), '°', 100, 60, 75) ?>
      </div></div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('info', 18) ?><?= h(A('Podatki')) ?></h2></div>
      <div class="panel-body"><dl class="kv">
        <dt>Identity</dt><dd class="mono"><?= h(($s['ident'] ?? '') ?: '–') ?></dd>
        <dt><?= h(A('Model')) ?></dt><dd><?= h($d['model'] ?: '–') ?></dd>
        <dt><?= h(A('Serijska')) ?></dt><dd class="mono"><?= h($d['serial'] ?: '–') ?></dd>
        <dt>RouterOS</dt><dd><?= h($d['os_version'] ?: '–') ?><?php if (!empty($upd['latest']) && !empty($upd['inst']) && version_compare((string)$upd['latest'], (string)$upd['inst'], '>')): ?> <span class="tag info"><?= h(A('na voljo {v}', ['v' => $upd['latest']])) ?></span><?php endif; ?></dd>
        <dt>Firmware</dt><dd><?= h($d['firmware'] ?: '–') ?><?php $fwUp = $s['rb']['fwUp'] ?? ''; if ($fwUp && $fwUp !== $d['firmware']): ?> <span class="tag info">→ <?= h($fwUp) ?></span><?php endif; ?></dd>
        <dt><?= h(A('Arhitektura')) ?></dt><dd><?= h($d['arch'] ?: '–') ?> · <?= (int)($res['cpus'] ?? 0) ?> CPU</dd>
        <dt><?= h(A('Pomnilnik')) ?></dt><dd><?= isset($res['memT']) ? h(fmt_bytes((int)$res['memT'] - (int)$res['memF'])) . ' / ' . h(fmt_bytes((int)$res['memT'])) : '–' ?></dd>
        <dt><?= h(A('Prehod WAN')) ?></dt><dd class="mono"><?= h(($s['ping']['gw'] ?? '') ?: '–') ?></dd>
        <dt><?= h(A('Povezave (conntrack)')) ?></dt><dd><?= isset($hh['conns']) && $hh['conns'] !== null ? number_format((int)$hh['conns'], 0, ',', '.') : '–' ?></dd>
        <dt><?= h(A('DHCP najemi')) ?></dt><dd><?= $hh['leases'] ?? '–' ?></dd>
        <?php if (Auth::isSuper()): [$scCls, $scTxt] = MikrotikScript::scriptState($d); ?><dt><?= h(A('Skripta NOC')) ?></dt><dd><span class="tag <?= $scCls ?>" title="<?= h($d['script_ver'] ?? '') ?>"><?= h($scTxt) ?></span></dd><?php endif; ?>
        <dt><?= h(A('Zaščita')) ?></dt><dd><?php [$fok, $fmsg] = Filter::routerState($d); ?><span class="tag <?= ($d['filter_profile'] ?? 'off') === 'off' ? '' : ($fok ? 'up' : 'warn') ?>"><?= h(Filter::label($d['filter_profile'] ?? 'off')) ?></span></dd>
        <dt><?= h(A('Razpoložljivost 30 d')) ?></dt><dd><a href="?tab=sla"><span class="tag <?= Sla::cls($sla30['pct']) ?>"><?= h(Sla::fmt($sla30['pct'])) ?></span></a></dd>
        <?php foreach ($s['health'] ?? [] as $k => $v): if (str_contains((string)$k, 'temperature') || $k === 'cpu-temperature') continue; ?>
        <dt><?= h($k) ?></dt><dd><?= h($v) ?></dd>
        <?php endforeach; ?>
        <dt><?= h(A('Ping s strežnika')) ?></dt><dd><?= $d['tiger_ping_at'] ? ($d['tiger_ping_ok'] ? h(($d['tiger_ping_ms'] ?? '?') . ' ms') : '<span class="tag down">' . h(A('ne odgovarja')) . '</span>') . ' <span class="faint small">' . h(fmt_ago($d['tiger_ping_at'])) . '</span>' : '–' ?></dd>
      </dl></div>
    </section>
    <?php if (!empty($s['pools'])): ?>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('list', 18) ?><?= h(A('IP pooli')) ?></h2></div>
      <div class="panel-body" style="display:grid;gap:12px">
      <?php foreach ($s['pools'] as $pl): $pp = $pl['size'] > 0 ? $pl['used'] / $pl['size'] * 100 : 0; ?>
        <div><div class="small" style="display:flex;justify-content:space-between"><span class="mono"><?= h($pl['n']) ?></span><span class="muted"><?= (int)$pl['used'] ?> / <?= (int)$pl['size'] ?></span></div><?= pct_meter($pp, 75, 90) ?></div>
      <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
    <?php $spd = Speed::history($id, 6); $spdLast = $spd[0] ?? null;
      $st = db()->prepare("SELECT status FROM noc_actions WHERE device_id=? AND kind='speed' AND status IN ('approved','sent') ORDER BY id DESC LIMIT 1"); $st->execute([$id]); $spdPending = $st->fetchColumn();
      $hasBtest = (bool)Speed::btest(); ?>
    <section class="panel" id="speed">
      <div class="panel-head"><h2><?= icon('gauge', 18) ?><?= h(A('Hitrost interneta')) ?></h2><?php if ($spdLast): ?><span class="small muted"><?= h(fmt_dt($spdLast['at'])) ?></span><?php endif; ?></div>
      <div class="panel-body">
        <?php if ($spdLast && $spdLast['ok']): $zd = (float)$d['wan_down_mbps']; ?>
        <div class="big-rate">
          <div><span><?= icon('arrow-down', 14) ?><?= h(A('Download')) ?></span><b class="down-c"><?= h(Speed::fmt((float)$spdLast['down_mbps'])) ?></b><?php if ($zd > 0): ?><span class="small"><?= (int)round($spdLast['down_mbps'] / $zd * 100) ?> % <?= h(A('zakupa')) ?></span><?php endif; ?></div>
          <div><span><?= icon('arrow-up', 14) ?><?= h(A('Upload')) ?></span><b class="up-c"><?= h(Speed::fmt($spdLast['up_mbps'] !== null ? (float)$spdLast['up_mbps'] : null)) ?></b><?php if ($spdLast['up_mbps'] !== null && (float)$d['wan_up_mbps'] > 0): ?><span class="small"><?= (int)round($spdLast['up_mbps'] / $d['wan_up_mbps'] * 100) ?> % <?= h(A('zakupa')) ?></span><?php endif; ?></div>
        </div>
        <p class="small muted" style="margin:8px 0 0"><?= h(Speed::methodLabel($spdLast['method'])) ?><?= $spdLast['note'] ? ' · ' . h($spdLast['note']) : '' ?></p>
        <?php elseif ($spdLast): ?><p class="small" style="margin:0;color:var(--down)"><?= h($spdLast['note'] ?: A('Meritev ni uspela.')) ?></p>
        <?php else: ?><p class="small muted" style="margin:0"><?= h(A('Še ni meritev.')) ?></p><?php endif; ?>
      </div>
      <?php if (count($spd) > 1): ?><ul class="feed"><?php foreach (array_slice($spd, 1) as $x): ?><li><span class="sev-bar <?= $x['ok'] ? 'info' : 'warning' ?>"></span><div class="what small"><?= $x['ok'] ? '↓ ' . h(Speed::fmt((float)$x['down_mbps'])) . ($x['up_mbps'] !== null ? ' · ↑ ' . h(Speed::fmt((float)$x['up_mbps'])) : '') : h(A('ni uspela')) ?> <span class="faint"><?= h($x['source'] === 'noc' ? A('nočna') : A('ročna')) ?></span></div><span class="when"><?= h(fmt_dt($x['at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($isSuper): ?><div class="panel-body actions" style="border-top:1px solid var(--line-2)">
        <?php if ($spdPending): ?><span class="tag info"><?= icon('clock', 12) ?><?= h($spdPending === 'sent' ? A('meritev poteka') : A('meritev čaka na router')) ?></span>
        <?php else: ?>
        <form method="post" action="/devices/<?= $id ?>/speed" class="actions" data-confirm="<?= h(A('Meritev za nekaj sekund polno zasede linijo. Nadaljujem?')) ?>"><?= Auth::csrf() ?>
          <select name="m" style="width:auto;flex:1;min-width:0"><?php if ($hasBtest): ?><option value="btest"><?= 'btest ↓↑' ?></option><?php endif; ?><option value="http"><?= h(A('prenos datoteke')) . ' ↓' ?></option></select>
          <button class="btn sm"><?= icon('gauge', 14) ?><?= h(A('Izmeri zdaj')) ?></button>
        </form>
        <?php endif; ?>
        <form method="post" action="/devices/<?= $id ?>/speed"><?= Auth::csrf() ?><input type="hidden" name="op" value="auto"><input type="hidden" name="on" value="<?= (int)$d['speed_auto'] ? '0' : '1' ?>">
          <label class="check small"><input type="checkbox" <?= (int)$d['speed_auto'] ? 'checked' : '' ?> data-autosubmit> <?= h(A('Meri vsako noč')) ?></label></form>
      </div><?php endif; ?>
    </section>
    <?php $sec = Score::compute($d); if ($sec['score'] !== (int)($d['sec_score'] ?? -1)) db()->prepare('UPDATE devices SET sec_score=?, sec_at=NOW() WHERE id=?')->execute([$sec['score'], $id]); ?>
    <section class="panel" id="sec">
      <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Varnostna ocena')) ?></h2><span class="score-badge <?= Score::cls($sec['score']) ?>"><?= (int)$sec['score'] ?><small>/100</small></span></div>
      <?php if (!$sec['items']): ?><div class="empty" style="padding:20px"><?= icon('check', 24) ?><div><?= h(A('Brez pomanjkljivosti.')) ?></div></div>
      <?php else: ?><ul class="feed"><?php foreach ($sec['items'] as [$sv, $txt, $link, $pts]): ?><li><span class="sev-bar <?= h($sv) ?>"></span><div class="what"><?= $isSuper && $link !== '' ? '<a href="' . h($link) . '">' . h($txt) . '</a>' : h($txt) ?></div><span class="when">−<?= (int)round($pts) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <?php $rosV = Ros::ver((string)$d['os_version']); $rosL = Ros::latest(); if (str_starts_with($rosV, '7.') && $rosL !== '' && version_compare($rosV, $rosL, '<')): $rosSec = Ros::securityFixes($rosV);
      $st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND kind='upgrade' ORDER BY id DESC LIMIT 1"); $st->execute([$id]); $upg = $st->fetch(); ?>
    <section class="panel" id="ros">
      <div class="panel-head"><h2><?= icon('refresh', 18) ?>RouterOS <?= h($rosL) ?></h2><span class="small muted"><?= h(A('nameščen {v}', ['v' => $rosV])) ?></span></div>
      <?php if ($rosSec): ?><ul class="feed"><?php foreach (array_slice($rosSec, 0, 6) as [$rv, $line]): ?><li><span class="sev-bar critical"></span><div class="what small"><b><?= h($rv) ?></b> · <?= h($line) ?></div></li><?php endforeach; ?>
        <?php if (count($rosSec) > 6): ?><li><div class="what small muted"><?= h(A('… in še {n} varnostnih popravkov', ['n' => count($rosSec) - 6])) ?></div></li><?php endif; ?></ul>
      <?php else: ?><div class="panel-body small muted"><?= h(A('V novejših verzijah ni označenih varnostnih popravkov.')) ?></div><?php endif; ?>
      <?php if ($isSuper): ?><div class="panel-body actions" style="border-top:1px solid var(--line-2)">
        <?php if ($upg && (in_array($upg['status'], ['approved', 'sent'], true) || ($upg['status'] === 'done' && !$upg['verified_at']))): ?>
          <span class="tag info"><?= icon('clock', 12) ?><?= h($upg['status'] === 'done' ? A('posodobitev na {v} načrtovana ob 03:30', ['v' => $upg['ip']]) : A('posodobitev čaka na router')) ?></span>
        <?php else: ?>
          <form method="post" action="/devices/<?= $id ?>/upgrade" data-confirm="<?= h(A('Router ob 03:30 namesti RouterOS {v} in se ponovno zažene (približno 2–5 minut brez interneta). Nadaljujem?', ['v' => $rosL])) ?>"><?= Auth::csrf() ?><button class="btn sm"><?= icon('clock', 14) ?><?= h(A('Posodobi ponoči ob 03:30')) ?></button></form>
          <?php if ($upg && $upg['status'] === 'failed'): ?><span class="small" style="color:var(--down)"><?= h(A('Zadnja načrtovana posodobitev ni uspela.')) ?></span><?php endif; ?>
        <?php endif; ?>
        <a class="small" href="https://mikrotik.com/download/changelogs" target="_blank" rel="noopener"><?= h(A('Celoten changelog')) ?></a>
      </div><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php $fc = Forecast::forDevice($d); if ($fc): ?>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('activity', 18) ?><?= h(A('Napovedi')) ?></h2><span class="small muted"><?= h(A('trend zadnjih 14 dni')) ?></span></div>
      <ul class="feed"><?php foreach ($fc as [$k, $txt, $days, $sev]): ?><li><span class="sev-bar <?= h($sev) ?>"></span><div class="what"><?= h($txt) ?></div></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('bell', 18) ?><?= h(A('Odprti alarmi')) ?></h2></div>
      <?php if (!$alerts): ?><div class="empty" style="padding:24px"><?= icon('shield', 26) ?><div><?= h(A('Vse v redu.')) ?></div></div>
      <?php else: ?><ul class="feed"><?php foreach ($alerts as $a): ?><li><span class="sev-bar <?= h($a['severity']) ?>"></span><div class="what"><?= h($a['message']) ?><?= ai_note($a['ai_note'] ?? null) ?></div><span class="when"><?= h(fmt_ago($a['started_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'ifaces'):
  $mon = Devices::list((string)$d['monitor_ifaces']);
  if ($d['wan_iface'] !== '' && !in_array($d['wan_iface'], $mon, true)) array_unshift($mon, $d['wan_iface']);
  $cur = $_GET['i'] ?? ($mon[0] ?? '');
?>
<?php if ($mon): ?>
<section class="panel">
  <div class="panel-head">
    <h2><?= icon('activity', 18) ?><?= h(A('Promet')) ?>
      <form method="get" style="display:inline"><input type="hidden" name="tab" value="ifaces"><select name="i" data-autosubmit style="width:auto;margin-left:8px"><?php foreach ($mon as $m): ?><option <?= $m === $cur ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></form>
    </h2>
    <?= range_seg(['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d', '12m' => '12 m'], '24h', 'ifc') ?>
  </div>
  <div class="panel-body"><div id="ifc" data-chart-group><?= chart($id, 'traffic', '24h', $cur, 240) ?></div></div>
</section>
<?php endif; ?>
<?php
  // --- stanje porta (izbrani vmesnik) in SFP moduli
  $pi = null; foreach ($ifaces as $x) if ($x['name'] === $cur) $pi = $x;
  $cnt = function (string $iv) use ($id, $cur) { $q = db()->prepare("SELECT COALESCE(SUM(CAST(detail AS UNSIGNED)),0) FROM iface_events WHERE device_id=? AND iface=? AND kind='down' AND ts > NOW() - INTERVAL $iv"); $q->execute([$id, $cur]); return (int)$q->fetchColumn(); };
  $evs = db()->prepare('SELECT * FROM iface_events WHERE device_id=? AND iface=? ORDER BY ts DESC LIMIT 12'); $evs->execute([$id, $cur]); $evs = $evs->fetchAll();
  $sfps = db()->prepare('SELECT * FROM sfp_state WHERE device_id=?'); $sfps->execute([$id]); $sfps = $sfps->fetchAll();
  usort($sfps, fn($a, $b) => strnatcasecmp($a['iface'], $b['iface']));   // naravni vrstni red: 1, 2 … 9, 10 … 16
  $evIco = ['down' => ['unlink', 'down'], 'up' => ['link', 'up'], 'speed' => ['gauge', 'warn'], 'sfp_in' => ['plug', 'up'], 'sfp_out' => ['plug', 'down'], 'sfp_swap' => ['refresh', 'info']];
?>
<?php if ($pi): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('ethernet', 18) ?><?= h(A('Stanje porta')) ?> <span class="mono faint small"><?= h($cur) ?></span></h2>
    <?= $pi['disabled'] ? '<span class="tag">' . h(A('izklopljen')) . '</span>' : ($pi['running'] ? '<span class="tag up">' . h(A('povezan')) . ($pi['rate'] ? ' · ' . h($pi['rate']) : '') . '</span>' : '<span class="tag down">' . h(A('brez povezave')) . '</span>') ?></div>
  <div class="panel-body port-grid">
    <div><span class="small muted"><?= h(A('Zadnja vzpostavitev')) ?></span><b><?= h($pi['last_up'] ? fmt_dt(date('Y-m-d H:i:s', strtotime(str_replace('/', ' ', $pi['last_up'])) ?: time())) : '–') ?></b></div>
    <div><span class="small muted"><?= h(A('Zadnja prekinitev')) ?></span><b><?= h($pi['last_down'] ? fmt_dt(date('Y-m-d H:i:s', strtotime(str_replace('/', ' ', $pi['last_down'])) ?: time())) : '–') ?></b></div>
    <div title="<?= h(A('Števec link-downs na routerju – od zadnjega ponovnega zagona ali ponastavitve števcev')) ?>"><span class="small muted"><?= h(A('Prekinitve (števec routerja)')) ?></span><b><?= (int)$pi['link_downs'] ?></b></div>
    <div title="<?= h(A('Prekinitve, ki jih je zaznal NOC – štejejo tudi prek ponovnih zagonov')) ?>"><span class="small muted"><?= h(A('Prekinitve (NOC) 24 h / 7 dni / skupaj')) ?></span><b><?= $cnt('24 HOUR') ?> / <?= $cnt('7 DAY') ?> / <?= (int)$pi['link_downs_noc'] ?></b></div>
  </div>
  <?php if ($evs): ?>
  <ul class="feed" style="border-top:1px solid var(--line-2)">
    <?php foreach ($evs as $e): [$ei, $ec] = $evIco[$e['kind']] ?? ['info', 'info']; ?>
    <li><span class="badge b-<?= ['down' => 'red', 'up' => 'aqua', 'warn' => 'yellow', 'info' => 'violet'][$ec] ?>" style="width:26px;height:26px"><?= icon($ei, 14) ?></span><div class="what"><?= h(['down' => A('Prekinitev'), 'up' => A('Vzpostavitev'), 'speed' => A('Sprememba hitrosti'), 'sfp_in' => A('SFP vstavljen'), 'sfp_out' => A('SFP odstranjen'), 'sfp_swap' => A('SFP zamenjan')][$e['kind']] ?? $e['kind']) ?> <span class="muted small"><?= h($e['kind'] === 'down' ? ((int)$e['detail'] > 1 ? '(' . (int)$e['detail'] . '×)' : '') : $e['detail']) ?></span></div><span class="when"><?= h(fmt_dt($e['ts'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><div class="panel-body small muted" style="border-top:1px solid var(--line-2)"><?= h(A('Od dodajanja naprave ni bilo prekinitev na tem portu.')) ?></div><?php endif; ?>
</section>
<?php endif; ?>

<?php foreach ($sfps as $sf):
  $copper = $sf['present'] && Devices::sfpCopper($sf);
  $th = Alerts::sfpThresholds($sf);
  $rxCls = $sf['rx'] === null ? '' : ($sf['rx'] <= $th['crit'] ? 'down' : ($sf['rx'] <= $th['warn'] || $sf['rx'] >= $th['high'] ? 'warn' : 'up'));
  $peerTx = null;
  if ($sf['peer_device']) { $q = db()->prepare('SELECT tx FROM sfp_state WHERE device_id=? AND iface=?'); $q->execute([$sf['peer_device'], $sf['peer_iface']]); $peerTx = $q->fetchColumn(); $peerTx = $peerTx === false ? null : $peerTx; }
  elseif ($sf['remote_tx'] !== null) $peerTx = $sf['remote_tx'];
  $loss = ($peerTx !== null && $sf['rx'] !== null) ? (float)$peerTx - (float)$sf['rx'] : null;
?>
<section class="panel">
  <div class="panel-head">
    <h2><?= icon('plug', 18) ?>SFP <span class="mono faint small"><?= h($sf['iface']) ?></span></h2>
    <?= !$sf['present'] ? '<span class="tag down">' . h(A('modul ni vstavljen')) . '</span>' : ($copper ? '<span class="tag">' . h(A('bakreni (RJ45)')) . '</span>' : '<span class="tag violet">' . h(strtoupper($sf['speed_class'])) . '</span>') ?>
  </div>
  <?php if ($sf['present']): ?>
  <div class="panel-body sfp-grid">
    <div class="sfp-id">
      <b><?= h(trim($sf['vendor'] . ' ' . $sf['part'])) ?: '–' ?></b>
      <div class="small muted"><?= h(implode(' · ', array_filter([$sf['stype'], $sf['wavelength'] ? (int)$sf['wavelength'] . ' nm' : '', $sf['length_km'] ? rtrim(rtrim(number_format((float)$sf['length_km'], 1, ',', ''), '0'), ',') . ' km' : '']))) ?></div>
      <div class="small faint mono">SN <?= h($sf['serial'] ?: '–') ?></div>
    </div>
    <?php if ($copper): ?>
      <div class="small muted" style="grid-column: span 3"><?= h(A('Bakreni modul (RJ45) – povezava po kablu, optičnih meritev ni.')) ?></div>
    <?php elseif ($sf['rx'] === null && $sf['tx'] === null): ?>
      <div class="small muted" style="grid-column: span 3"><?= h(A('Optični modul brez diagnostike (DDM) – moči signala ni mogoče brati.')) ?></div>
    <?php else: ?>
    <div><span class="small muted">RX</span><b class="sfp-pow <?= $rxCls ?>"><?= $sf['rx'] !== null ? number_format((float)$sf['rx'], 2, ',', '') . ' dBm' : '–' ?></b><span class="small faint"><?= h(A('meja {w} / {c}', ['w' => number_format($th['warn'], 0), 'c' => number_format($th['crit'], 0)])) ?></span></div>
    <div><span class="small muted">TX</span><b class="sfp-pow"><?= $sf['tx'] !== null ? number_format((float)$sf['tx'], 2, ',', '') . ' dBm' : '–' ?></b><span class="small faint"><?= $loss !== null ? h(A('dušenje trase {l} dB', ['l' => number_format($loss, 1, ',', '')])) : h(A('dušenje: nastavi nasprotno stran')) ?></span></div>
    <div><span class="small muted"><?= h(A('Modul')) ?></span><b><?= $sf['temp'] !== null ? number_format((float)$sf['temp'], 1, ',', '') . ' °C' : '–' ?></b><span class="small faint"><?= $sf['volt'] !== null ? number_format((float)$sf['volt'], 2, ',', '') . ' V' : '' ?><?= $sf['bias'] !== null ? ' · ' . number_format((float)$sf['bias'], 1, ',', '') . ' mA' : '' ?></span></div>
    <?php endif; ?>
  </div>
  <?php if ($sf['rx'] !== null): ?>
  <div class="panel-body" style="padding-top:0">
    <div class="actions" style="justify-content:space-between;margin-bottom:6px"><span class="legend"><span><i style="background:var(--aqua)"></i>RX</span><span><i style="background:var(--violet)"></i>TX</span></span>
      <?= range_seg(['24h' => '24 h', '7d' => '7 d', '30d' => '30 d'], '7d', 'sfpc-' . md5($sf['iface'])) ?></div>
    <div id="sfpc-<?= md5($sf['iface']) ?>" data-chart-group><?= chart($id, 'sfp', '7d', $sf['iface'], 170) ?></div>
  </div>
  <?php endif; ?>
  <?php if (Auth::isSuper() && !$copper): $peers = db()->query('SELECT s.device_id, s.iface, d.name FROM sfp_state s JOIN devices d ON d.id=s.device_id WHERE s.present=1 AND NOT (s.device_id=' . $id . ' AND s.iface=' . db()->quote($sf['iface']) . ') ORDER BY d.name, s.iface')->fetchAll(); ?>
  <details class="sfp-set"><summary class="small"><?= icon('sliders', 14) ?> <?= h(A('Pragovi in nasprotna stran')) ?></summary>
    <form class="form panel-body" method="post" action="/devices/<?= $id ?>/sfp"><?= Auth::csrf() ?><input type="hidden" name="iface" value="<?= h($sf['iface']) ?>"><input type="hidden" name="i" value="<?= h($cur) ?>">
      <div class="row c3">
        <label class="f"><span><?= h(A('Opozorilo pod')) ?> <small>(dBm)</small></span><input type="text" name="th_warn" value="<?= h($sf['th_warn'] ?? '') ?>" placeholder="<?= h(Alerts::SFP_TH[$sf['speed_class']]['warn']) ?>"></label>
        <label class="f"><span><?= h(A('Kritično pod')) ?> <small>(dBm)</small></span><input type="text" name="th_crit" value="<?= h($sf['th_crit'] ?? '') ?>" placeholder="<?= h(Alerts::SFP_TH[$sf['speed_class']]['crit']) ?>"></label>
        <label class="f"><span><?= h(A('Premočno nad')) ?> <small>(dBm)</small></span><input type="text" name="th_high" value="<?= h($sf['th_high'] ?? '') ?>" placeholder="<?= h(Alerts::SFP_TH[$sf['speed_class']]['high']) ?>"></label>
      </div>
      <div class="row c3">
        <label class="f"><span><?= h(A('Nasprotna stran v NOC')) ?></span><select name="peer"><option value="">–</option><?php foreach ($peers as $pp): $v = $pp['device_id'] . '|' . $pp['iface']; ?><option value="<?= h($v) ?>" <?= (int)$sf['peer_device'] === (int)$pp['device_id'] && $sf['peer_iface'] === $pp['iface'] ? 'selected' : '' ?>><?= h($pp['name'] . ' · ' . $pp['iface']) ?></option><?php endforeach; ?></select></label>
        <label class="f"><span><?= h(A('… ali TX nasprotne strani')) ?> <small>(dBm)</small></span><input type="text" name="remote_tx" value="<?= h($sf['remote_tx'] ?? '') ?>" placeholder="-3"></label>
        <div style="align-self:end"><button class="btn"><?= icon('check', 15) ?><?= h(A('Shrani')) ?></button></div>
      </div>
      <small class="muted"><?= h(A('Prazno = privzeti pragovi za {c}. Dušenje trase = TX nasprotne strani − RX tukaj.', ['c' => strtoupper($sf['speed_class'])])) ?></small>
    </form>
  </details>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="panel">
  <div class="panel-head"><h2><?= icon('ethernet', 18) ?><?= h(A('Vsi vmesniki')) ?></h2><span class="small muted"><?= h(A('Zgodovino prometa imajo vmesniki, izbrani v nastavitvah.')) ?></span></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Vmesnik')) ?></th><th><?= h(A('Tip')) ?></th><th><?= h(A('Stanje')) ?></th><th><?= h(A('Hitrost')) ?></th><th class="num">RX</th><th class="num">TX</th><th class="num"><?= h(A('Skupaj RX / TX')) ?></th><th class="num"><?= h(A('Napake')) ?></th><th class="num">Link-down</th><th>MAC</th></tr></thead>
    <tbody>
    <?php foreach ($ifaces as $i): ?>
      <tr>
        <td><b class="mono"><?= h($i['name']) ?></b><?= $i['name'] === $d['wan_iface'] ? ' <span class="tag violet">WAN</span>' : '' ?><?= in_array($i['name'], $mon, true) ? ' <span title="' . h(A('zgodovina prometa')) . '">' . icon('activity', 13, 'faint') . '</span>' : '' ?><?php if ($i['comment']): ?><div class="small muted"><?= h($i['comment']) ?></div><?php endif; ?></td>
        <td class="muted"><?= h($i['type']) ?></td>
        <td><?= $i['disabled'] ? '<span class="tag">' . h(A('izklopljen')) . '</span>' : ($i['running'] ? '<span class="tag up">' . h(A('povezan')) . '</span>' : '<span class="tag">' . h(A('brez povezave')) . '</span>') ?></td>
        <td class="nowrap"><?= h($i['rate'] ?: '–') ?></td>
        <td class="num down-c"><?= h(fmt_bps($i['rx_bps'])) ?></td>
        <td class="num up-c"><?= h(fmt_bps($i['tx_bps'])) ?></td>
        <td class="num muted"><?= h(fmt_bytes($i['rx_byte'])) ?> / <?= h(fmt_bytes($i['tx_byte'])) ?></td>
        <td class="num <?= $i['rx_error'] + $i['tx_error'] > 0 ? '' : 'faint' ?>" style="<?= $i['rx_error'] + $i['tx_error'] > 0 ? 'color:var(--warn)' : '' ?>"><?= (int)$i['rx_error'] + (int)$i['tx_error'] ?></td>
        <td class="num <?= $i['link_downs'] ? '' : 'faint' ?>"><?= (int)$i['link_downs'] ?></td>
        <td class="mono small muted"><?= h($i['mac']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php $wg = $s['wg'] ?? []; if ($wg): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('lock', 18) ?><?= h(A('WireGuard peerji')) ?></h2></div>
  <?php if (true): ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Peer</th><th><?= h(A('Vmesnik')) ?></th><th>Endpoint</th><th><?= h(A('Zadnji handshake')) ?></th><th class="num">RX</th><th class="num">TX</th></tr></thead>
    <tbody><?php foreach ($wg as $p): $hs = ros_seconds((string)($p['hs'] ?? '')); ?><tr>
      <td><b><?= h(($p['n'] ?? '') ?: ($p['c'] ?? '') ?: '–') ?></b><?= !empty($p['dis']) ? ' <span class="tag">' . h(A('izklopljen')) . '</span>' : '' ?></td>
      <td class="mono"><?= h($p['i'] ?? '') ?></td><td class="mono small"><?= h(($p['ep'] ?? '') ?: '–') ?></td>
      <td><?= $hs === null ? '<span class="faint">' . h(A('nikoli')) . '</span>' : '<span class="tag ' . ($hs < 180 ? 'up' : ($hs < 900 ? 'warn' : '')) . '">' . h(A('pred {t}', ['t' => fmt_uptime($hs)])) . '</span>' ?></td>
      <td class="num"><?= h(fmt_bytes($p['rx'] ?? 0)) ?></td><td class="num"><?= h(fmt_bytes($p['tx'] ?? 0)) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($tab === 'clients'): ?>
<section class="panel">
  <div class="panel-head">
    <h2><?= icon('laptop', 18) ?><?= h(A('Največ prometa')) ?></h2>
    <div class="seg"><?php foreach (['1h' => '1 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d'] as $r => $l): ?><a href="?tab=clients&r=<?= $r ?>" class="<?= $range === $r ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
  </div>
  <?php if (!(int)$d['flow_enabled']): ?><div class="empty"><?= icon('unlink', 28) ?><div><?= h(A('Traffic-flow je za to napravo izklopljen (Nastavitve).')) ?></div></div>
  <?php elseif (!$top): ?><div class="empty"><?= icon('clock', 28) ?><div><?= h(A('Ni podatkov o tokovih. Prvi se pokažejo 5–10 minut po namestitvi, ko ufw odpre UDP port za ta IP.')) ?></div></div>
  <?php else: $maxT = max(array_map(fn($r) => $r['up'] + $r['down'], $top)) ?: 1; ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Naprava')) ?></th><th>IP</th><th>MAC</th><th class="num"><?= h(A('Download')) ?></th><th class="num"><?= h(A('Upload')) ?></th><th style="width:28%"><?= h(A('Skupaj')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($top as $r): $tot = $r['up'] + $r['down']; ?>
      <tr>
        <td><?php if ($isSuper && $r['mac']): ?><form method="post" action="/devices/<?= $id ?>/host" class="actions" style="gap:6px"><?= Auth::csrf() ?><input type="hidden" name="mac" value="<?= h($r['mac']) ?>"><input type="hidden" name="r" value="<?= h($range) ?>"><input type="text" name="label" value="<?= h($r['label']) ?>" placeholder="<?= h($r['hostname'] ?: A('dodaj ime')) ?>" style="max-width:210px;padding:5px 9px"></form><?php else: ?><?= h($r['label'] ?: ($r['hostname'] ?: '–')) ?><?php endif; ?></td>
        <td class="mono"><?= h($r['ip']) ?></td>
        <td class="mono small muted"><?= h($r['mac'] ?: '–') ?></td>
        <td class="num down-c"><?= h(fmt_bytes($r['down'])) ?></td>
        <td class="num up-c"><?= h(fmt_bytes($r['up'])) ?></td>
        <td><div class="meter" title="<?= h(fmt_bytes($tot)) ?>"><i style="width:<?= max(1, round($tot / $maxT * 100)) ?>%;background:linear-gradient(90deg,var(--aqua),var(--violet))"></i></div><span class="small muted"><?= h(fmt_bytes($tot)) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ((int)$d['flow_enabled'] && $wanSum > 0): ?>
  <div class="panel-body small muted" style="border-top:1px solid var(--line-2)"><?= icon('info', 14) ?> <?= h(A('Kontrola zadnjih 24 h: tokovi {f}, števec WAN {w} ({p} %). Če je razlika velika, del prometa gre mimo traffic-flow (npr. HW offload).', ['f' => fmt_bytes($flowSum), 'w' => fmt_bytes($wanSum), 'p' => round($flowSum / $wanSum * 100)])) ?></div>
  <?php endif; ?>
</section>
<?php if ($geoTop): $gTot = array_sum(array_column($geoTop, 'b')) ?: 1; ?>
<div class="grid g2">
<section class="panel" id="geo">
  <div class="panel-head"><h2><?= icon('globe', 18) ?><?= h(A('Promet po državah')) ?></h2><span class="small muted"><?= h($geoLbl) ?></span></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Država')) ?></th><th class="num"><?= h(A('Download')) ?></th><th class="num"><?= h(A('Upload')) ?></th><th style="width:30%"><?= h(A('Delež')) ?></th></tr></thead>
    <tbody><?php foreach ($geoTop as $g): ?><tr>
      <td><?= h(Geo::flag($g['cc']) . ' ' . ($g['cc'] !== '' ? Geo::name($g['cc']) : A('neznano'))) ?></td>
      <td class="num"><?= h(fmt_bytes($g['down'])) ?></td><td class="num"><?= h(fmt_bytes($g['up'])) ?></td>
      <td><div class="meter"><i style="width:<?= max(1, round($g['b'] / $gTot * 100)) ?>%;background:linear-gradient(90deg,var(--aqua),var(--violet))"></i></div></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<section class="panel" id="asn">
  <div class="panel-head"><h2><?= icon('net', 18) ?><?= h(A('Omrežja (ponudniki storitev)')) ?></h2><span class="small muted"><?= h($geoLbl) ?></span></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Omrežje')) ?></th><th><?= h(A('Država')) ?></th><th class="num"><?= h(A('Skupaj')) ?></th></tr></thead>
    <tbody><?php foreach ($asnTop as $g): ?><tr>
      <td><?= h($g['org'] ?: ($g['asn'] ? 'AS' . $g['asn'] : A('neznano'))) ?><?= $g['asn'] ? ' <span class="small faint">AS' . (int)$g['asn'] . '</span>' : '' ?></td>
      <td><?= h(Geo::flag($g['cc'])) ?> <?= h($g['cc']) ?></td><td class="num"><?= h(fmt_bytes($g['b'])) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="panel-body small faint" style="border-top:1px solid var(--line-2)"><?= Geo::attribution() ?></div>
</section>
</div>
<?php endif; ?>
<section class="panel" id="hosts">
  <div class="panel-head"><h2><?= icon('monitor', 18) ?><?= h(A('Znane naprave (DHCP)')) ?></h2><span class="small muted"><?= count($hosts) ?> · <?= h(A('vrsta določa, katere naprave NOC posebej spremlja (kamere, tiskalniki …)')) ?></span></div>
  <?php if (!$hosts): ?><div class="empty"><?= h(A('Ni DHCP najemov.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Ime')) ?></th><th><?= h(A('Vrsta')) ?></th><th>IP</th><th>MAC</th><th><?= h(A('DHCP strežnik')) ?></th><th><?= h(A('Prvič')) ?></th><th><?= h(A('Zadnjič')) ?></th></tr></thead>
    <tbody><?php $kinds = Profile::kinds(); foreach ($hosts as $hst): $hk = (string)($hst['kind'] ?? ''); ?><tr>
      <td><?= h($hst['label'] ?: ($hst['hostname'] ?: '–')) ?><?= $hst['label'] && $hst['hostname'] ? ' <span class="small faint">' . h($hst['hostname']) . '</span>' : '' ?><?= strtotime($hst['first_seen']) > time() - 86400 ? ' <span class="tag info">' . h(A('nova')) . '</span>' : '' ?></td>
      <td class="nowrap"><?php if ($isSuper): ?><form method="post" action="/devices/<?= $id ?>/hostkind" class="inline-sel"><?= Auth::csrf() ?><input type="hidden" name="mac" value="<?= h($hst['mac']) ?>"><?= icon(Profile::icon($hk), 14, in_array($hk, Profile::WATCH, true) ? '' : 'faint') ?><select name="kind" data-autosubmit aria-label="<?= h(A('Vrsta')) ?>"><?php foreach ($kinds as $kk => $kl): ?><option value="<?= h($kk) ?>" <?= $kk === $hk ? 'selected' : '' ?>><?= h($kk === '' ? A('samodejno') : $kl) ?></option><?php endforeach; ?></select></form>
        <?php else: ?><?= icon(Profile::icon($hk), 14, 'faint') ?> <?= h($kinds[$hk] ?? $hk) ?><?php endif; ?></td>
      <td class="mono"><?= h($hst['ip']) ?></td><td class="mono small muted"><?= h($hst['mac']) ?><?= ($hst['vendor'] ?? '') !== '' ? '<div class="small faint" style="font-family:inherit">' . h(mb_strimwidth($hst['vendor'], 0, 32, '…')) . '</div>' : '' ?></td><td class="muted"><?= h($hst['server']) ?></td>
      <td class="nowrap muted small"><?= h(fmt_dt($hst['first_seen'])) ?></td><td class="nowrap small"><?= h(fmt_ago($hst['last_seen'])) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'sla'):
  $prevM = date('Y-m', strtotime($ym . '-01 -1 month')); $nextM = date('Y-m', strtotime($ym . '-01 +1 month'));
  $dur = fn(int $x) => $x < 60 ? $x . ' s' : ($x < 3600 ? intdiv($x, 60) . ' min' : intdiv($x, 3600) . ' h ' . intdiv($x % 3600, 60) . ' min');
?>
<div class="grid g3">
  <?php foreach ([[A('Ta mesec'), $slaMonth], [A('Zadnjih 30 dni'), $sla30], [A('Zadnjih 12 mesecev'), $sla365]] as [$lbl, $p]): ?>
  <section class="panel"><div class="panel-body">
    <div class="small muted"><?= h($lbl) ?></div>
    <div style="font-size:2rem;font-weight:600;color:var(--<?= Sla::cls($p['pct']) ?: 'text' ?>)"><?= h(Sla::fmt($p['pct'])) ?></div>
    <div class="small muted"><?= $p['count'] ? h(A('{n} izpadov, skupaj {t}', ['n' => $p['count'], 't' => $dur($p['down_s'])])) : h(A('brez izpadov')) ?></div>
  </div></section>
  <?php endforeach; ?>
</div>
<section class="panel">
  <div class="panel-head">
    <h2><?= icon('gauge', 18) ?><?= h(Report::monthName($ym)) ?></h2>
    <div class="seg"><a href="?tab=sla&m=<?= $prevM ?>">‹ <?= h(Report::monthName($prevM)) ?></a><?php if ($nextM <= date('Y-m')): ?><a href="?tab=sla&m=<?= $nextM ?>"><?= h(Report::monthName($nextM)) ?> ›</a><?php endif; ?></div>
  </div>
  <div class="panel-body">
    <div class="sla-strip">
    <?php foreach ($slaDays as $day => $pct): ?>
      <div class="sla-day <?= $pct === null ? 'none' : Sla::cls($pct) ?>" title="<?= h(date('d.m.Y', strtotime($day)) . ': ' . Sla::fmt($pct)) ?>"><span><?= (int)date('j', strtotime($day)) ?></span></div>
    <?php endforeach; ?>
    </div>
    <div class="legend" style="margin-top:12px"><span><i style="background:var(--up)"></i>≥ 99,9 %</span><span><i style="background:var(--warn)"></i>99–99,9 %</span><span><i style="background:var(--down)"></i>&lt; 99 %</span><span><i style="background:var(--line)"></i><?= h(A('ni podatkov')) ?></span></div>
  </div>
</section>
<section class="panel">
  <div class="panel-head"><h2><?= icon('history', 18) ?><?= h(A('Izpadi v mesecu')) ?></h2><span class="small muted"><?= h(A('Šteje nedosegljiva naprava in izpad interneta.')) ?></span></div>
  <?php if (!$slaMonth['outages']): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('V tem mesecu ni bilo izpadov.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Vrsta')) ?></th><th><?= h(A('Začetek')) ?></th><th><?= h(A('Konec')) ?></th><th class="num"><?= h(A('Trajanje')) ?></th></tr></thead>
    <tbody><?php foreach ($slaMonth['outages'] as $o): ?><tr>
      <td><?= sev_tag('critical') ?> <?= h($o['key'] === 'offline' ? A('Naprava nedosegljiva') : A('Internet ne deluje')) ?></td>
      <td class="nowrap"><?= h(date('d.m.Y H:i', $o['from'])) ?></td>
      <td class="nowrap"><?= $o['open'] ? '<span class="tag down">' . h(A('traja')) . '</span>' : h(date('d.m.Y H:i', $o['to'])) ?></td>
      <td class="num"><?= h($dur(max(0, $o['to'] - $o['from']))) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'security'): [$fsCls, $fsTxt] = FwLog::state($d); ?>
<section class="panel" id="fw">
  <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Požarni zid – poskusi z interneta')) ?></h2><span class="tag <?= $fsCls ?>"><?= h($fsTxt) ?></span></div>
  <?php if (!(int)$d['fwlog_enabled']): ?>
  <div class="empty"><?= empty_art('search') ?><div><?= h(A('Varnostna analiza je za to napravo izklopljena.')) ?></div>
    <?php if ($isSuper): ?><a class="btn sm" style="margin-top:10px" href="/devices/<?= $id ?>?tab=protect#fwlog"><?= icon('shield', 15) ?><?= h(A('Vklopi na strani Zaščita')) ?></a><?php endif; ?></div>
  <?php else: $hrs = $fw['hours']; $mx = max(1, max($hrs)); $i = 0; ?>
  <div class="panel-body">
    <div class="fw-stats">
      <div><span class="small muted"><?= h(A('Zavrnjenih poskusov (24 h)')) ?></span><b><?= number_format((int)$fw['tot']['hits'], 0, ',', '.') ?></b></div>
      <div><span class="small muted"><?= h(A('Različnih naslovov')) ?></span><b><?= number_format((int)$fw['tot']['src'], 0, ',', '.') ?></b></div>
      <div><span class="small muted"><?= h(A('Različnih portov')) ?></span><b><?= number_format((int)$fw['tot']['ports'], 0, ',', '.') ?></b></div>
      <div><span class="small muted"><?= h(A('Z varnostne liste')) ?></span><b><?= number_format($fw['bl_in'], 0, ',', '.') ?></b></div>
    </div>
    <svg class="fw-bars" viewBox="0 0 840 110" preserveAspectRatio="none" role="img" aria-label="<?= h(A('Poskusi po urah, zadnjih 7 dni')) ?>">
      <?php foreach ($hrs as $hk => $n): $bh = $n ? max(2, round($n / $mx * 96)) : 0; ?><rect x="<?= $i * 5 ?>" y="<?= 100 - $bh ?>" width="4" height="<?= $bh ?>"><title><?= h(date('d.m. H:00', strtotime($hk)) . ' – ' . $n) ?></title></rect><?php if (substr($hk, 11, 2) === '00'): ?><line x1="<?= $i * 5 ?>" x2="<?= $i * 5 ?>" y1="0" y2="100" class="day"/><?php endif; $i++; endforeach; ?>
      <line x1="0" x2="840" y1="100.5" y2="100.5" class="axis"/>
    </svg>
    <div class="fw-axis small faint"><span><?= h(date('d.m.', time() - 7 * 86400)) ?></span><span><?= h(A('poskusi po urah, zadnjih 7 dni · najvišja ura {n}', ['n' => $mx])) ?></span><span><?= h(A('zdaj')) ?></span></div>
  </div>
  <div class="grid g2 fw-tables">
    <div><h3 class="fw-h"><?= h(A('Največ poskusov (24 h)')) ?></h3>
      <?php if (!$fw['src']): ?><div class="empty small"><?= h(A('Ni zapisov v zadnjih 24 urah.')) ?></div><?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th><?= h(A('Izvorni IP')) ?></th><th class="num"><?= h(A('Poskusov')) ?></th><th><?= h(A('Porti')) ?></th><th><?= h(A('Zadnjič')) ?></th></tr></thead><tbody>
      <?php foreach ($fw['src'] as $x): ?><tr><td class="mono"><?= h($x['src']) ?></td><td class="num"><b><?= (int)$x['hits'] ?></b></td><td class="small"><?= (int)$x['ports'] > 6 ? '<b>' . (int)$x['ports'] . '</b> · ' : '' ?><span class="mono muted"><?= h($x['pl']) ?></span></td><td class="small nowrap"><?= h(fmt_ago($x['last'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?></div>
    <div><h3 class="fw-h"><?= h(A('Najbolj ciljani porti (24 h)')) ?></h3>
      <?php if (!$fw['ports']): ?><div class="empty small"><?= h(A('Ni zapisov v zadnjih 24 urah.')) ?></div><?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th><?= h(A('Port')) ?></th><th class="num"><?= h(A('Poskusov')) ?></th><th class="num"><?= h(A('Naslovov')) ?></th></tr></thead><tbody>
      <?php foreach ($fw['ports'] as $x): ?><tr><td class="mono"><?= h(FwLog::portLabel($x['proto'], (int)$x['port'])) ?></td><td class="num"><b><?= (int)$x['hits'] ?></b></td><td class="num"><?= (int)$x['src'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?></div>
  </div>
  <?php endif; ?>
</section>

<?php if ((int)$d['fwlog_enabled']): ?>
<div class="grid g2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('laptop', 18) ?><?= h(A('Naprave v LAN-u – sumljiv odhodni promet')) ?></h2></div>
    <?php if (!$fw['out'] && !$fw['bl_out']): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Ni sumljivega odhodnega prometa.')) ?></div><div class="small faint" style="margin-top:4px"><?= h(A('Iz traffic-flow (promet prek CPU routerja) in zadetkov varnostne liste.')) ?></div></div>
    <?php else: ?><div class="tbl-wrap"><table class="tbl"><thead><tr><th><?= h(A('Naprava')) ?></th><th><?= h(A('Ugotovitev')) ?></th><th><?= h(A('Zadnjič')) ?></th></tr></thead><tbody>
      <?php foreach ($fw['bl_out'] as $x): ?><tr><td class="mono small"><?= h($x['who']) ?></td><td class="small"><span class="tag down"><?= h(A('varnostna lista')) ?></span> <?= h($x['dl']) ?> · <?= (int)$x['hits'] ?>×</td><td class="small nowrap"><?= h(fmt_ago($x['last'])) ?></td></tr><?php endforeach; ?>
      <?php $thr = Devices::thresholds($d); foreach ($fw['out'] as $x): $f = [];
        if ((int)$x['bl_hits']) $f[] = '<span class="tag down">' . h(A('varnostna lista')) . '</span> ' . h((string)$x['bl_sample']) . ' · ' . (int)$x['bl_hits'] . '×';
        if ((int)$x['smtp_ips']) $f[] = '<span class="tag ' . ((int)$x['smtp_ips'] >= (int)$thr['th_out_smtp'] ? 'warn' : '') . '">SMTP</span> ' . h(A('{n} strežnikov', ['n' => (int)$x['smtp_ips']]));
        if ((int)$x['scan_ips'] >= (int)$thr['th_out_ips']) $f[] = '<span class="tag warn">' . h(A('skeniranje')) . '</span> ' . h(A('port {p} → {n} naslovov', ['p' => (int)$x['scan_port'], 'n' => (int)$x['scan_ips']]));
        elseif ((int)$x['scan_ips'] >= 20) $f[] = '<span class="muted">' . h(A('port {p} → {n} naslovov', ['p' => (int)$x['scan_port'], 'n' => (int)$x['scan_ips']])) . '</span>';
        if (!$f) $f[] = '<span class="muted">' . h(A('{n} različnih ciljev v 5 min', ['n' => (int)$x['dst_ips']])) . '</span>'; ?>
      <tr><td class="mono small"><?= h($x['who']) ?></td><td class="small"><?= implode(' · ', $f) ?></td><td class="small nowrap"><?= h(fmt_ago($x['last'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('alert', 18) ?><?= h(A('Zaznani dogodki (30 dni)')) ?></h2></div>
    <?php if (!$fw['events']): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Ni zaznanih dogodkov.')) ?></div></div><?php else: ?>
    <ul class="feed"><?php foreach ($fw['events'] as $a): ?><li><span class="sev-bar <?= h($a['severity']) ?>"></span><div class="what"><?= h($a['message']) ?></div><span class="when"><?= h(fmt_dt($a['started_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Neuspele prijave na router (30 dni)')) ?></h2><span class="small muted"><?= h(A('Alarm: {n} poskusov z istega IP-ja v {m} min', ['n' => (int)setting('th_attack', '5'), 'm' => (int)setting('th_attack_min', '15')])) ?></span></div>
  <?php if (!$fails): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Ni neuspelih prijav.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Izvorni IP')) ?></th><th><?= h(A('Način')) ?></th><th><?= h(A('Uporabniška imena')) ?></th><th class="num"><?= h(A('Poskusov')) ?></th><th><?= h(A('Prvič')) ?></th><th><?= h(A('Zadnjič')) ?></th></tr></thead>
    <tbody><?php foreach ($fails as $f): ?><tr>
      <td class="mono"><?= h($f['src'] ?: '?') ?></td><td class="muted"><?= h(str_replace('via ', '', (string)$f['via'])) ?></td>
      <td class="small"><?= h(mb_strimwidth((string)$f['users'], 0, 60, '…')) ?></td><td class="num"><b><?= (int)$f['n'] ?></b></td>
      <td class="nowrap muted small"><?= h(fmt_dt($f['first'])) ?></td><td class="nowrap small"><?= h(fmt_ago($f['last'])) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
<div class="grid g2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('alert', 18) ?><?= h(A('Zaznani napadi')) ?></h2></div>
    <?php if (!$attacks): ?><div class="empty"><?= h(A('Ni zaznanih napadov.')) ?></div><?php else: ?>
    <ul class="feed"><?php foreach ($attacks as $a): ?><li><span class="sev-bar warning"></span><div class="what"><?= h($a['message']) ?></div><span class="when"><?= h(fmt_dt($a['started_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('user', 18) ?><?= h(A('Uspešne prijave')) ?></h2></div>
    <?php if (!$logins): ?><div class="empty small"><?= h(A('Ni zapisov. Router jih pošilja, če ima tema "account" vklopljeno v filtru logov.')) ?></div><?php else: ?>
    <ul class="feed"><?php foreach ($logins as $l): ?><li><span class="sev-bar info"></span><div class="what mono small"><?= h($l['message']) ?></div><span class="when"><?= h(fmt_dt($l['ts'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>
<?php $acts = Actions::forDevice($id); $unblocked = [];
  foreach ($acts as $x) if ($x['kind'] === 'unblock' && in_array($x['status'], ['approved', 'sent', 'done'], true)) $unblocked[$x['ip']] = max($unblocked[$x['ip']] ?? 0, (int)$x['id']); ?>
<section class="panel" id="actions" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Blokade in ukrepi')) ?></h2><span class="small muted"><?= h(A('Router jih izvede ob naslednjem pushu · seznam noc-block (raw)')) ?></span></div>
  <?php if ($isSuper): ?>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/action" style="padding-bottom:0"><?= Auth::csrf() ?><input type="hidden" name="op" value="block">
    <div class="row c3">
      <label class="f"><span><?= h(A('Blokiraj zunanji IP')) ?></span><input type="text" name="ip" class="mono" placeholder="203.0.113.7" required></label>
      <label class="f"><span><?= h(A('Za')) ?></span><select name="hours"><?php foreach ([1 => '1 h', 24 => '24 h', 168 => '7 d', 720 => '30 d'] as $hv => $hl): ?><option value="<?= $hv ?>" <?= $hv === 24 ? 'selected' : '' ?>><?= $hl ?></option><?php endforeach; ?></select></label>
      <div style="align-self:end"><button class="btn"><?= icon('shield', 15) ?><?= h(A('Blokiraj')) ?></button></div>
    </div>
  </form>
  <?php endif; ?>
  <?php if (!$acts): ?><div class="empty small"><?= h(A('Še ni ukrepov. Blokado lahko predlaga AI ali jo potrdiš z gumbom pod obvestilom o napadu.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>IP</th><th><?= h(A('Ukrep')) ?></th><th><?= h(A('Stanje')) ?></th><th><?= h(A('Razlog')) ?></th><th><?= h(A('Čas')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($acts as $x): [$sc, $sl] = Actions::statusLabel($x['status']);
      $isActive = ($x['kind'] === 'block' && in_array($x['status'], ['approved', 'sent'], true)) || (Actions::active($x) && ($unblocked[$x['ip']] ?? 0) < (int)$x['id']); ?><tr>
      <td class="mono"><?= h($x['ip']) ?></td>
      <td class="nowrap"><?= $x['kind'] === 'block' ? h(A('blokada {h} h', ['h' => (int)$x['hours']])) : ($x['kind'] === 'upgrade' ? h(A('posodobitev RouterOS')) : h(A('odblokiranje'))) ?> <span class="small faint"><?= h(['ai' => 'AI', 'alarm' => A('alarm'), 'splet' => A('ročno')][$x['source']] ?? $x['source']) ?></span></td>
      <td class="nowrap"><span class="tag <?= $sc ?>"><?= h($sl) ?></span><?= $isActive && $x['status'] === 'done' ? ' <span class="tag down">' . h(A('aktivna')) . '</span>' : '' ?></td>
      <td class="small"><?= h($x['reason']) ?></td>
      <td class="nowrap small muted"><?= h(fmt_dt($x['created_at'])) ?></td>
      <td class="num nowrap"><?php if ($isSuper && $x['status'] === 'proposed'): ?>
        <form method="post" action="/devices/<?= $id ?>/action" style="display:inline"><?= Auth::csrf() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="aid" value="<?= (int)$x['id'] ?>"><button class="btn sm primary"><?= icon('check', 14) ?><?= h(A('Izvedi')) ?></button></form>
        <form method="post" action="/devices/<?= $id ?>/action" style="display:inline"><?= Auth::csrf() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="aid" value="<?= (int)$x['id'] ?>"><button class="btn sm"><?= icon('x', 14) ?><?= h(A('Zavrni')) ?></button></form>
      <?php elseif ($isSuper && $isActive): ?>
        <form method="post" action="/devices/<?= $id ?>/action"><?= Auth::csrf() ?><input type="hidden" name="op" value="unblock"><input type="hidden" name="ip" value="<?= h($x['ip']) ?>"><button class="btn sm"><?= icon('x', 14) ?><?= h(A('Odblokiraj')) ?></button></form>
      <?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'logs'): ?>
<section class="panel">
  <div class="panel-head">
    <form class="filters" method="get"><input type="hidden" name="tab" value="logs">
      <input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h(A('Išči v logih …')) ?>">
      <select name="sev" data-autosubmit><option value=""><?= h(A('Vse stopnje')) ?></option><?php foreach (['critical', 'error', 'warning', 'info'] as $sv): ?><option value="<?= $sv ?>" <?= ($_GET['sev'] ?? '') === $sv ? 'selected' : '' ?>><?= h($sv) ?></option><?php endforeach; ?></select>
      <button class="btn sm"><?= icon('search', 15) ?><?= h(A('Išči')) ?></button>
    </form>
    <span class="small muted"><?= h(A('Zadnjih 500 vrstic, hramba 90 dni')) ?></span>
  </div>
  <?php if (!$logs): ?><div class="empty"><?= empty_art('search') ?><div><?= h(A('Ni zapisov.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl log-tbl"><tbody>
    <?php foreach ($logs as $l): ?><tr><td class="nowrap muted"><?= h(date('d.m. H:i:s', strtotime($l['ts']))) ?></td><td><?= sev_tag($l['severity']) ?></td><td class="small muted nowrap"><?= h($l['topics']) ?></td><td class="msg"><?= h($l['message']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'alerts'): $incidents = Incidents::list($id, 90, 20); ?>
<?php if ($incidents): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('activity', 18) ?><?= h(A('Incidenti (90 dni)')) ?></h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Opis')) ?></th><th><?= h(A('Začetek')) ?></th><th><?= h(A('Trajanje')) ?></th><th class="num"><?= h(A('Alarmov')) ?></th></tr></thead>
    <tbody><?php foreach ($incidents as $inc): ?><tr>
      <td><?= sev_tag($inc['severity']) ?></td>
      <td><a class="rowlink" href="/incidents/<?= (int)$inc['id'] ?>"><?= h($inc['title']) ?></a><?= trim((string)$inc['ai_summary']) !== '' ? ' <span class="tag violet">AI</span>' : '' ?></td>
      <td class="nowrap"><?= h(fmt_dt($inc['started_at'])) ?></td>
      <td class="nowrap"><?= $inc['ended_at'] ? h(Incidents::duration($inc)) : '<span class="tag down">' . h(A('še traja')) . '</span>' ?></td>
      <td class="num muted"><?= (int)$inc['n_alerts'] ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('history', 18) ?><?= h(A('Zgodovina alarmov')) ?></h2></div>
  <?php if (!$history): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Še ni bilo alarmov.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Opis')) ?></th><th><?= h(A('Začetek')) ?></th><th><?= h(A('Konec')) ?></th><th><?= h(A('Trajanje')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($history as $a): $dur = ($a['ended_at'] ? strtotime($a['ended_at']) : time()) - strtotime($a['started_at']); ?><tr>
      <td><?= sev_tag($a['severity']) ?></td><td><?= h($a['message']) ?><?= ai_note($a['ai_note'] ?? null) ?></td><td class="nowrap"><?= h(fmt_dt($a['started_at'])) ?></td>
      <td class="nowrap"><?= $a['ended_at'] ? h(fmt_dt($a['ended_at'])) : '<span class="tag down">' . h(A('odprt')) . '</span>' ?></td><td class="nowrap muted"><?= $dur > 59 ? h(fmt_uptime($dur)) : '–' ?></td>
      <td class="num"><?php if (!$a['ended_at']): ?><?php if ($a['acked_at']): ?><span class="tag"><?= icon('check', 12) ?><?= h(A('potrjen')) ?></span><?php else: ?><form method="post" action="/alerts/<?= (int)$a['id'] ?>/ack"><?= Auth::csrf() ?><button class="btn sm"><?= icon('check', 14) ?><?= h(A('Potrdi')) ?></button></form><?php endif; ?><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'wg'):
  $wgDef = array_key_first($wgSrv) ?? ''; $wgSug = $wgDef !== '' ? Wg::suggestIp($d, $wgDef) : ''; ?>
<?php if (!Wg::ready($d)): ?>
<section class="panel"><div class="panel-body small muted"><?= icon('clock', 14) ?> <?= h(A('Router še pošilja podatke stare skripte. Posodobi se sam v nekaj minutah, nato se tu pokažejo WireGuard vmesniki in uporabniki.')) ?></div></section>
<?php endif; ?>
<section class="panel" id="wg-users">
  <div class="panel-head"><h2><?= icon('users', 18) ?><?= h(A('WireGuard uporabniki')) ?> <span class="tag"><?= count($wgPeers) ?></span></h2><span class="small muted"><?= h(A('Spremembe router izvede ob naslednjem pushu')) ?></span></div>
  <?php if (!$wgPeers): ?><div class="empty small"><?= h(A('Na tem routerju še ni WireGuard peerjev.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Ime')) ?></th><th><?= h(A('Vmesnik')) ?></th><th>IP</th><th><?= h(A('Stanje')) ?></th><th><?= h(A('Zadnja povezava')) ?></th><th class="num">RX / TX</th><th>Config</th><th></th></tr></thead>
    <tbody><?php foreach ($wgPeers as $p): [$sc, $sl] = Wg::statusLabel($p); $rp = $wgRouter[$p['pubkey']] ?? null; $hs = $rp ? ros_seconds((string)($rp['hs'] ?? '')) : null; ?><tr>
      <td><a class="rowlink" href="/devices/<?= $id ?>/wg/<?= (int)$p['id'] ?>"><?= icon($p['kind'] === 'phone' ? 'smartphone' : ($p['kind'] === 'pc' ? 'laptop' : 'link'), 14, 'faint') ?> <?= h($p['name'] ?: '–') ?></a></td>
      <td class="mono small"><?= h($p['iface']) ?></td>
      <td class="mono"><?= h($p['ip'] ?: $p['allowed']) ?></td>
      <td class="nowrap"><span class="tag <?= $sc ?>"><?= h($sl) ?></span><?= !(int)$p['enabled'] ? ' <span class="tag">' . h(A('izklopljen')) . '</span>' : '' ?></td>
      <td class="nowrap"><?= $hs === null ? '<span class="faint">' . h(A('nikoli')) . '</span>' : '<span class="tag ' . ($hs < 180 ? 'up' : ($hs < 900 ? 'warn' : '')) . '">' . h(A('pred {t}', ['t' => fmt_uptime($hs)])) . '</span>' ?><?= $rp && ($rp['ep'] ?? '') !== '' ? '<div class="small faint mono">' . ($p['last_cc'] !== '' ? h(Geo::flag($p['last_cc'])) . ' ' : '') . h($rp['ep']) . '</div>' : '' ?></td>
      <td class="num small nowrap"><?= $rp ? h(fmt_bytes($rp['rx'] ?? 0)) . ' / ' . h(fmt_bytes($rp['tx'] ?? 0)) : '–' ?></td>
      <td><?= $p['priv_enc'] ? '<span class="tag up">' . icon('key', 12) . h(A('na voljo')) . '</span>' : '<span class="tag" title="' . h(A('Zasebnega ključa ni v NOC – config ni mogoče izdelati. Uvozi ga iz generatorja ali zamenjaj ključ.')) . '">' . h(A('ni ključa')) . '</span>' ?></td>
      <td class="num nowrap">
        <?php if (in_array($p['status'], ['ok', 'sync', 'failed'], true)): ?><form method="post" action="/devices/<?= $id ?>/wg/<?= (int)$p['id'] ?>" style="display:inline"><?= Auth::csrf() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="back" value="list"><button class="btn sm" title="<?= h((int)$p['enabled'] ? A('Izklopi') : A('Vklopi')) ?>"><?= icon('power', 14) ?></button></form><?php endif; ?>
        <a class="btn sm" href="/devices/<?= $id ?>/wg/<?= (int)$p['id'] ?>"><?= icon($p['priv_enc'] ? 'eye' : 'edit', 14) ?><?= h($p['priv_enc'] ? A('QR / config') : A('Uredi')) ?></a>
      </td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php $wgIn = Wg::inactive(90, $id); if ($wgIn): ?>
<section class="panel" id="wg-inactive">
  <div class="panel-head"><h2><?= icon('clock', 18) ?><?= h(A('Neaktivni uporabniki')) ?> <span class="tag warn"><?= count($wgIn) ?></span></h2>
    <form method="post" action="/devices/<?= $id ?>/wg" data-confirm="<?= h(A('Izklopim vse neaktivne uporabnike na tej napravi? Ključi ostanejo, kadar koli jih vklopiš nazaj.')) ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="disable-inactive"><button class="btn sm"><?= icon('power', 14) ?><?= h(A('Izklopi vse')) ?></button></form></div>
  <div class="panel-body small muted" style="padding-bottom:0"><?= h(A('Vklopljeni, a brez povezave več kot 90 dni. Pozabljen dostop je pogosta varnostna luknja – izklopi jih, ključi ostanejo.')) ?></div>
  <ul class="feed"><?php foreach ($wgIn as $x): ?><li><span class="sev-bar warning"></span><div class="what"><a href="/devices/<?= $id ?>/wg/<?= (int)$x['id'] ?>"><?= h($x['name'] ?: $x['ip']) ?></a> <span class="small faint mono"><?= h($x['ip']) ?></span></div><span class="when"><?= h($x['last_hs_at'] ? A('zadnjič {t}', ['t' => fmt_ago($x['last_hs_at'])]) : A('nikoli')) ?></span></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="panel" id="wg-add">
  <div class="panel-head"><h2><?= icon('plus', 18) ?><?= h(A('Dodaj uporabnika')) ?></h2><span class="small muted"><?= h(A('NOC ustvari ključe, config in QR kodo ter peer doda na router')) ?></span></div>
  <?php if (!$wgSrv): ?><div class="panel-body small muted"><?= h(A('Router nima WireGuard vmesnika s portom in naslovom (strežnik). Ko ga nastaviš, se tu pokaže obrazec.')) ?></div><?php else: ?>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/wg"><?= Auth::csrf() ?><input type="hidden" name="op" value="add">
    <div class="row c3">
      <label class="f"><span><?= h(A('Ime')) ?></span><input type="text" name="name" maxlength="100" required placeholder="<?= h(A('npr. Janez – telefon')) ?>"></label>
      <label class="f"><span><?= h(A('Vrsta')) ?></span><select name="kind"><option value="phone"><?= h(A('Telefon (QR koda)')) ?></option><option value="pc"><?= h(A('Računalnik (config datoteka)')) ?></option></select></label>
      <label class="f"><span><?= h(A('Vmesnik')) ?></span><select name="iface"><?php foreach ($wgSrv as $wn => $w): ?><option value="<?= h($wn) ?>"><?= h($wn . ' · ' . $w['a'] . ' · :' . $w['p']) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row c3">
      <label class="f"><span><?= h(A('IP naslov uporabnika')) ?></span><input type="text" name="ip" class="mono" value="<?= h($wgSug) ?>" required><small><?= h(A('predlagan prvi prosti naslov')) ?></small></label>
      <label class="f"><span><?= h(A('Promet skozi tunel')) ?></span><select name="route"><option value="all"><?= h(A('Ves promet (tudi internet)')) ?></option><option value="lan"><?= h(A('Samo omrežje stranke (LAN)')) ?></option></select></label>
      <label class="f"><span><?= h(A('DNS strežniki')) ?></span><input type="text" name="dns" value="<?= h(Wg::DEFAULT_DNS) ?>"></label>
    </div>
    <div class="actions"><button class="btn primary"><?= icon('plus', 15) ?><?= h(A('Dodaj in prikaži QR kodo')) ?></button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel" id="wg-server">
  <div class="panel-head"><h2><?= icon('server', 18) ?><?= h(A('WireGuard na routerju')) ?></h2></div>
  <?php if (!$wgIfaces): ?><div class="empty small"><?= h(A('Na routerju ni WireGuard vmesnikov.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Vmesnik')) ?></th><th><?= h(A('Naslov')) ?></th><th>Port</th><th><?= h(A('Javni ključ')) ?></th><th>Endpoint</th></tr></thead>
    <tbody><?php foreach ($wgIfaces as $wn => $w): ?><tr>
      <td class="mono"><b><?= h($wn) ?></b><?= $w['dis'] ? ' <span class="tag">' . h(A('izklopljen')) . '</span>' : '' ?><?= !isset($wgSrv[$wn]) ? ' <span class="tag" title="' . h(A('brez porta ali naslova – verjetno odjemalec (povezava na drug router)')) . '">' . h(A('odjemalec')) . '</span>' : '' ?></td>
      <td class="mono"><?= h($w['a'] ?: '–') ?></td><td class="mono"><?= $w['p'] ?: '–' ?></td>
      <td class="mono small nowrap"><?= h($w['pk']) ?> <button type="button" class="btn sm ghost" data-copy="<?= h($w['pk']) ?>" title="<?= h(A('Kopiraj')) ?>"><?= icon('copy', 13) ?></button></td>
      <td class="mono small"><?= isset($wgSrv[$wn]) ? h(Wg::endpoint($d, $wn) ?: '–') : '–' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/wg" style="border-top:1px solid var(--line-2)"><?= Auth::csrf() ?><input type="hidden" name="op" value="endpoint">
    <div class="row c3">
      <label class="f"><span><?= h(A('Endpoint v configu')) ?></span><input type="text" name="endpoint" class="mono" value="<?= h((string)($d['wg_endpoint'] ?? '')) ?>" placeholder="<?= h($d['public_ip'] ?: 'vpn.domena.si') ?>"></label>
      <div style="align-self:end"><button class="btn"><?= icon('check', 15) ?><?= h(A('Shrani')) ?></button></div>
    </div>
    <small class="muted"><?= h(A('Prazno = javni IP naprave. Domena ali IP, po želji s portom.')) ?></small>
  </form>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'backups'): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('archive', 18) ?><?= h(A('Zgodovina konfiguracije')) ?></h2><span class="small muted"><?= h(A('Nov vnos samo, ko se /export spremeni; hrani se zadnjih 60.')) ?></span></div>
  <?php if (!$backups): ?><div class="empty"><?= empty_art('archive') ?><div><?= h(A('Še ni backupa. Router ga pošlje ponoči; takoj ga sprožiš z /system script run noc-backup.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Datum')) ?></th><th><?= h(A('Spremembe')) ?></th><th class="num"><?= h(A('Velikost')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($backups as $i => $b): ?><tr>
      <td class="nowrap"><b><?= h(date('d.m.Y H:i', strtotime($b['created_at']))) ?></b><?= $i === 0 ? ' <span class="tag violet">' . h(A('trenutna')) . '</span>' : '' ?></td>
      <td><?php if ($i === count($backups) - 1): ?><span class="muted"><?= h(A('prvi backup')) ?></span><?php else: ?><span class="tag up">+<?= (int)$b['added'] ?></span> <span class="tag down">−<?= (int)$b['removed'] ?></span><?php endif; ?>
        <?php if (($b['ai_risk'] ?? '') === 'warn'): ?> <span class="tag warn"><?= icon('alert', 12) ?><?= h(A('tvegano')) ?></span><?php endif; ?><?= ai_note($b['ai_note'] ?? null, false, (string)($b['ai_risk'] ?? '')) ?></td>
      <td class="num muted"><?= h(fmt_bytes($b['bytes'])) ?></td>
      <td class="num"><a class="btn sm" href="/devices/<?= $id ?>/backups/<?= (int)$b['id'] ?>"><?= icon('diff', 15) ?><?= h(A('Odpri')) ?></a> <a class="btn sm ghost" href="/devices/<?= $id ?>/backups/<?= (int)$b['id'] ?>/download" title="<?= h(A('Prenesi .rsc')) ?>"><?= icon('download', 15) ?></a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php elseif ($tab === 'protect'):
  $prof = $d['filter_profile'] ?? 'off'; [$fok, $fmsg] = Filter::routerState($d);
  $lan = Devices::list((string)$d['lan_networks']); $sel = Devices::list((string)($d['filter_networks'] ?? '')) ?: $lan;
  $routerDns = $s['flt']['dns'] ?? null;
?>
<div class="grid g-main">
  <form class="form" method="post" action="/devices/<?= $id ?>/filter">
    <?= Auth::csrf() ?>
    <section class="panel"><div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Profil zaščite')) ?></h2></div>
      <div class="panel-body"><div class="choice-grid">
        <?php foreach (['off' => ['x', A('Router uporablja DNS kot doslej. Nastavitve zaščite se odstranijo, DNS se vrne na prvotnega.')],
                        'basic' => ['shield', A('Blokira zlonamerne in phishing strani (Cloudflare 1.1.1.2, Quad9). Za pisarne in vse stranke.')],
                        'family' => ['users', A('Osnovna zaščita in še vsebine za odrasle (Cloudflare 1.1.1.3). Za gostujoči WiFi in javne prostore.')]] as $k => [$ic, $desc]): ?>
        <label class="choice"><input type="radio" name="filter_profile" value="<?= $k ?>" <?= $prof === $k ? 'checked' : '' ?>>
          <span class="choice-ico c-<?= $k ?>"><?= icon($ic, 20) ?></span><b><?= h(Filter::label($k)) ?></b><small><?= h($desc) ?></small></label>
        <?php endforeach; ?>
      </div></div></section>
    <section class="panel"><div class="panel-head"><h2><?= icon('sliders', 18) ?><?= h(A('Dodatno')) ?></h2></div>
      <div class="panel-body form">
        <label class="check"><input type="checkbox" name="filter_force_dns" value="1" <?= (int)($d['filter_force_dns'] ?? 1) ? 'checked' : '' ?>> <span><b><?= h(A('Vsi morajo prek routerja')) ?></b> – <?= h(A('naprave z ročno vpisanim DNS (npr. 8.8.8.8) se preusmerijo na DNS routerja')) ?></span></label>
        <label class="check"><input type="checkbox" name="filter_block_doh" value="1" <?= (int)($d['filter_block_doh'] ?? 1) ? 'checked' : '' ?>> <span><b><?= h(A('Blokiraj šifriran DNS')) ?></b> – <?= h(A('DoH in DoT, prek katerih bi brskalniki obšli filter')) ?></span></label>
        <label class="check"><input type="checkbox" name="filter_ip_lists" value="1" <?= (int)($d['filter_ip_lists'] ?? 1) ? 'checked' : '' ?>> <span><b><?= h(A('Varnostne IP liste')) ?></b> – <?= h(A('znana zlonamerna omrežja (Spamhaus DROP), posodobitev vsak dan')) ?></span></label>
        <?php if ($lan): ?>
        <div><b class="small"><?= h(A('Omrežja z zaščito')) ?></b><div class="actions" style="margin-top:8px">
          <?php foreach ($lan as $n): ?><label class="check"><input type="checkbox" name="nets[]" value="<?= h($n) ?>" <?= in_array($n, $sel, true) ? 'checked' : '' ?>> <span class="mono"><?= h($n) ?></span></label><?php endforeach; ?>
        </div><small class="muted"><?= h(A('Npr. samo gostujoči WiFi, pisarna pa brez filtra.')) ?></small></div>
        <?php endif; ?>
        <label class="f"><?= h(A('Lastne blokirane domene')) ?><textarea name="filter_domains" rows="4" class="mono" placeholder="primer.com&#10;igre.primer.net"><?= h((string)($d['filter_domains'] ?? '')) ?></textarea><small><?= h(A('Ena na vrstico. Blokirane so tudi vse poddomene.')) ?></small></label>
        <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani')) ?></button></div>
      </div></section>
  </form>
  <aside>
    <section class="panel"><div class="panel-head"><h2><?= icon('router', 18) ?><?= h(A('Na routerju')) ?></h2></div>
      <div class="panel-body"><dl class="kv">
        <dt><?= h(A('Izbrano v NOC')) ?></dt><dd><?= h(Filter::label($prof)) ?></dd>
        <dt><?= h(A('Stanje')) ?></dt><dd><span class="tag <?= $fok === null ? '' : ($fok ? 'up' : 'warn') ?>"><?= h($fmsg) ?></span></dd>
        <dt><?= h(A('DNS routerja')) ?></dt><dd class="mono"><?= h($routerDns === null ? '–' : ($routerDns !== '' ? $routerDns : A('od ponudnika'))) ?></dd>
        <?php if ($d['dns_original'] !== null): $o = json_decode((string)$d['dns_original'], true) ?: []; ?><dt><?= h(A('Prvotni DNS')) ?></dt><dd class="mono"><?= h(($o['servers'] ?? '') !== '' ? $o['servers'] : A('od ponudnika')) ?></dd><?php endif; ?>
      </dl></div></section>
    <section class="panel"><div class="panel-head"><h2><?= icon('download', 18) ?><?= h(A('Uveljavi na routerju')) ?></h2></div>
      <div class="panel-body"><p class="small muted" style="margin-top:0"><?= h(A('Po shranjevanju prilepi v terminal routerja:')) ?></p>
        <?php $one = MikrotikScript::oneLiner($d); ?><div class="cmd"><code><?= h($one) ?></code><button type="button" data-copy="<?= h($one) ?>"><?= icon('copy', 14) ?></button></div>
        <p class="small muted"><?= h(A('Stanje se tukaj osveži v minuti po namestitvi.')) ?></p></div></section>
    <section class="panel"><div class="panel-body small muted">
      <?= icon('info', 14) ?> <?= h(A('Vse spremembe na routerju nosijo oznako "noc-filter" in se ob izklopu odstranijo v celoti. NOC ne beleži, katere strani kdo obiskuje.')) ?>
      <br><br><?= h(A('Naprave, ki uporabljajo zasebni relay (iCloud Private Relay) ali VPN, filtra ne gredo skozi – to je pričakovano.')) ?>
    </div></section>
  </aside>
</div>

<?php $fa = Firewall::audit($d); $fx = json_decode((string)($d['fw_fixes'] ?? ''), true) ?: []; $fxIds = $fx['ids'] ?? [];
  $applied = array_diff($fxIds, array_keys($fa['findings'])); $fwOnRouter = (int)($s['flt']['fw'] ?? 0);
  $scCls = $fa['score'] === null ? '' : ($fa['score'] >= 8 ? 'up' : ($fa['score'] >= 5 ? 'warn' : 'down')); ?>
<section class="panel" id="fw" style="margin-top:20px">
  <div class="panel-head">
    <h2><?= icon('shield', 18) ?><?= h(A('Pregled požarnega zidu')) ?></h2>
    <div class="actions">
      <?php if ($fa['at']): ?><span class="small muted"><?= h(A('iz backupa {t}', ['t' => fmt_dt($fa['at'])])) ?></span><?php endif; ?>
      <?php if ($fa['score'] !== null): ?><span class="fw-score <?= $scCls ?>"><?= (int)$fa['score'] ?><small>/10</small></span><?php endif; ?>
    </div>
  </div>
  <?php if ($fa['score'] === null): ?>
    <div class="empty"><?= empty_art('archive') ?><div><?= h(A('Ni konfiguracije za pregled – počakaj na nočni backup ali ga sproži z /system script run noc-backup.')) ?></div></div>
  <?php else: ?>
  <form method="post" action="/devices/<?= $id ?>/fw"><?= Auth::csrf() ?>
    <?php if (!$fa['findings'] && !$applied): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Požarni zid je urejen po dobri praksi.')) ?></div></div><?php endif; ?>
    <ul class="fw-list">
    <?php foreach ($fa['findings'] as $f): ?>
      <li class="fw-item">
        <?php if ($f['fixable']): ?><input type="checkbox" name="fx[]" value="<?= h($f['id']) ?>" id="fx-<?= h($f['id']) ?>" <?= in_array($f['id'], $fxIds, true) ? 'checked' : '' ?>><?php else: ?><span style="width:17px"></span><?php endif; ?>
        <label for="fx-<?= h($f['id']) ?>"><span class="sev-bar <?= h($f['sev']) ?>"></span><span><span class="fw-title"><b><?= h($f['title']) ?></b><?= sev_tag($f['sev']) ?></span><span class="small muted"><?= h($f['why']) ?></span><?php if ($f['note']): ?><span class="small" style="color:var(--warn)"><?= icon('alert', 13) ?> <?= h($f['note']) ?></span><?php endif; ?></span></label>
      </li>
    <?php endforeach; ?>
    <?php foreach ($applied as $aid): ?>
      <li class="fw-item done"><input type="checkbox" name="fx[]" value="<?= h($aid) ?>" id="fx-<?= h($aid) ?>" checked>
        <label for="fx-<?= h($aid) ?>"><span class="sev-bar" style="background:var(--up)"></span><span><span class="fw-title"><b><?= h($fx['titles'][$aid] ?? $aid) ?></b><span class="tag up"><?= icon('check', 12) ?><?= h(A('popravljeno')) ?></span></span><span class="small muted"><?= h(A('Popravek NOC je aktiven. Odkljukaj in uveljavi, če ga želiš odstraniti.')) ?></span></span></label></li>
    <?php endforeach; ?>
    </ul>
    <div class="panel-body fw-foot">
      <div class="small muted"><?= icon('info', 14) ?> <?= h(A('Upravljalni naslovi ({ips}) so vedno dovoljeni. Če router po spremembi ne doseže NOC, se vse samodejno povrne v 5 minutah.', ['ips' => implode(', ', Firewall::mgmtIps()) ?: '–'])) ?>
        <?php if (!Firewall::mgmtIps()): ?><br><b style="color:var(--down)"><?= h(A('Najprej vpiši upravljalne naslove v Alarmi in obvestila.')) ?></b><?php endif; ?></div>
      <div class="actions"><span class="small muted"><?= h(A('Na routerju: {n} pravil noc-fw', ['n' => $fwOnRouter])) ?><?= $d['fw_confirmed_at'] ? ' · ' . h(A('potrjeno {t}', ['t' => fmt_ago($d['fw_confirmed_at'])])) : '' ?></span>
        <button class="btn primary" <?= !Firewall::mgmtIps() ? 'disabled' : '' ?>><?= icon('check', 16) ?><?= h(A('Shrani izbiro')) ?></button></div>
    </div>
  </form>
  <?php endif; ?>
</section>

<?php [$fsCls, $fsTxt] = FwLog::state($d); $fwOn = (int)$d['fwlog_enabled']; ?>
<section class="panel" id="fwlog" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('scroll', 18) ?><?= h(A('Varnostna analiza')) ?></h2><span class="tag <?= $fsCls ?>"><?= h($fsTxt) ?></span></div>
  <div class="panel-body fwlog-body">
    <div>
      <p style="margin-top:0"><?= h(A('Router pošilja zapise požarnega zidu na NOC, ta pa jih analizira skupaj s tokovi iz traffic-flow. Ob nenavadnem vedenju te obvesti.')) ?></p>
      <ul class="fwlog-list small">
        <li><b><?= h(A('Od zunaj:')) ?></b> <?= h(A('skeniranje portov, vztrajni poskusi na en port, nenaden porast poskusov, promet z varnostne liste')) ?></li>
        <li><b><?= h(A('Iz omrežja:')) ?></b> <?= h(A('naprava, ki skenira internet, pošilja pošto mimo strežnika ali kontaktira omrežja z varnostne liste (možna okužba)')) ?></li>
      </ul>
      <p class="small muted"><?= h(A('Na routerju doda samo pravila za beleženje z oznako "noc-log" (promet ne blokirajo), omejena na 20 zapisov na minuto na izvorni naslov. Zapisi ne polnijo lokalnega loga routerja. Rezultati so na zavihku Varnost.')) ?></p>
    </div>
    <form method="post" action="/devices/<?= $id ?>/fwlog" class="fwlog-act"><?= Auth::csrf() ?>
      <input type="hidden" name="on" value="<?= $fwOn ? '0' : '1' ?>">
      <?php if ($fwOn): ?><a class="btn" href="/devices/<?= $id ?>?tab=security#fw"><?= icon('shield', 16) ?><?= h(A('Rezultati')) ?></a><button class="btn"><?= icon('x', 16) ?><?= h(A('Izklopi')) ?></button>
      <?php else: ?><button class="btn primary"><?= icon('zap', 16) ?><?= h(A('Vklopi')) ?></button><?php endif; ?>
    </form>
  </div>
</section>

<?php [$tsCls, $tsTxt] = MikrotikScript::tlsState($d); $tlsOn = (int)($d['tls_check'] ?? 0); ?>
<section class="panel" id="tls" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('lock', 18) ?><?= h(A('Preverjanje certifikata NOC')) ?></h2><span class="tag <?= $tsCls ?>"><?= h($tsTxt) ?></span></div>
  <div class="panel-body fwlog-body">
    <div>
      <p style="margin-top:0"><?= h(A('Router pri povezavi na NOC preveri certifikat HTTPS. Tako nihče na poti med routerjem in NOC ne more podtakniti skripte za samodejno posodobitev.')) ?></p>
      <ul class="fwlog-list small">
        <li><b><?= h(A('Posodobitve:')) ?></b> <?= h(A('skripta se prenese in uvozi samo s preverjenim certifikatom')) ?></li>
        <li><b><?= h(A('Push:')) ?></b> <?= h(A('najprej s preverjanjem; če router certifikatu ne zaupa, pošlje brez, da nadzor ne izpade – NOC to pokaže tukaj')) ?></li>
      </ul>
      <p class="small muted"><?= h(A('Preizkus na routerju pred vklopom:')) ?> <code>/tool fetch url="<?= h(rtrim((string)cfg('base_url'), '/')) ?>/login" check-certificate=yes-without-crl output=none</code></p>
    </div>
    <form method="post" action="/devices/<?= $id ?>/tls" class="fwlog-act"><?= Auth::csrf() ?>
      <input type="hidden" name="on" value="<?= $tlsOn ? '0' : '1' ?>">
      <?php if ($tlsOn): ?><button class="btn"><?= icon('x', 16) ?><?= h(A('Izklopi')) ?></button>
      <?php else: ?><button class="btn primary"><?= icon('lock', 16) ?><?= h(A('Vklopi')) ?></button><?php endif; ?>
    </form>
  </div>
</section>

<?php $st = db()->prepare('SELECT * FROM ai_reviews WHERE device_id=? ORDER BY id DESC LIMIT 1'); $st->execute([$id]); $rv = $st->fetch();
  $rvF = $rv && $rv['status'] === 'done' ? (json_decode((string)$rv['findings'], true) ?: []) : []; ?>
<section class="panel" id="ai-review" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('zap', 18) ?><?= h(A('AI pregled konfiguracije')) ?></h2>
    <div class="actions">
      <?php if ($rv): ?><span class="small muted"><?= h(fmt_dt($rv['created_at'])) ?></span><?php endif; ?>
      <?php if ($rv && $rv['status'] === 'pending'): ?><span class="tag info"><?= h(A('v teku …')) ?></span>
      <?php elseif (Ai::on()): ?><form method="post" action="/devices/<?= $id ?>/ai-review"><?= Auth::csrf() ?><button class="btn sm<?= $rv ? '' : ' primary' ?>"><?= icon('zap', 14) ?><?= h($rv ? A('Ponovi pregled') : A('Zaženi pregled')) ?></button></form><?php endif; ?>
    </div></div>
  <?php if (!Ai::on()): ?><div class="panel-body small muted"><?= h(A('Za AI pregled vpiši API ključ v Nastavitve → AI pomočnik.')) ?></div>
  <?php elseif (!$rv): ?><div class="panel-body small muted"><?= h(A('AI pregleda celotno konfiguracijo routerja (zadnji backup) in predlaga konkretne izboljšave: varnost, zastarele nastavitve, VLAN-i, varovalke. Skrivnosti se pred pošiljanjem odstranijo, pravil NOC ne komentira.')) ?></div>
  <?php elseif ($rv['status'] === 'failed'): ?><div class="panel-body small"><span class="tag down"><?= h(A('ni uspel')) ?></span> <?= h($rv['note']) ?></div>
  <?php elseif ($rv['status'] === 'pending'): ?><div class="panel-body small muted"><?= h(A('Pregled se izvaja v ozadju – osveži stran čez minuto.')) ?></div>
  <?php elseif (!$rvF): ?><div class="panel-body small"><?= h(A('AI ni našel ničesar, kar bi bilo treba popraviti.')) ?></div>
  <?php else: ?>
  <ul class="feed review"><?php foreach ($rvF as $k => $f): $stt = $f['state'] ?? 'open'; ?>
    <li class="<?= $stt !== 'open' ? 'faded' : '' ?>"><span class="sev-bar <?= h($f['severity']) ?>"></span>
      <div class="what"><?= sev_tag($f['severity']) ?> <b><?= h($f['title']) ?></b><?= $stt === 'done' ? ' <span class="tag up">' . h(A('urejeno')) . '</span>' : ($stt === 'dismissed' ? ' <span class="tag">' . h(A('ni pomembno')) . '</span>' : '') ?>
        <div class="small" style="margin-top:4px"><?= h($f['why']) ?></div>
        <?php if (trim($f['fix']) !== ''): ?><pre class="raw small" style="margin:6px 0 0"><?= h($f['fix']) ?></pre><?php endif; ?></div>
      <?php if ($isSuper): ?><div class="when nowrap">
        <?php foreach ($stt === 'open' ? ['done' => [A('Urejeno'), 'check'], 'dismissed' => [A('Ni pomembno'), 'x']] : ['open' => [A('Odpri znova'), 'refresh']] as $ns => [$lbl, $ic]): ?>
        <form method="post" action="/devices/<?= $id ?>/ai-review" style="display:inline"><?= Auth::csrf() ?><input type="hidden" name="op" value="state"><input type="hidden" name="rid" value="<?= (int)$rv['id'] ?>"><input type="hidden" name="k" value="<?= (int)$k ?>"><input type="hidden" name="state" value="<?= $ns ?>"><button class="btn sm ghost" title="<?= h($lbl) ?>"><?= icon($ic, 14) ?></button></form>
        <?php endforeach; ?></div><?php endif; ?>
    </li><?php endforeach; ?></ul>
  <p class="panel-body small muted" style="margin:0"><?= h(A('Ukazov AI ne izvede sam – preveri jih in jih po potrebi prilepi v terminal routerja.')) ?></p>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'settings'):
  $f = $d; $th = json_decode((string)$d['thresholds'], true) ?: [];
  $thLabels = ['th_cpu' => ['CPU %', '%'], 'th_temp' => [A('Temperatura'), '°C'], 'th_mem' => [A('Pomnilnik'), '%'], 'th_hdd' => [A('Disk'), '%'], 'th_gw_loss' => [A('Izguba do prehoda'), '%'], 'th_gw_ms' => [A('Latenca do prehoda'), 'ms'], 'th_ext_loss' => [A('Izguba do interneta'), '%'], 'th_ext_ms' => [A('Latenca do interneta'), 'ms'], 'th_offline_min' => [A('Brez pusha'), 'min'], 'th_host_gb_h' => [A('Naprava v LAN – GB/uro'), 'GB'], 'th_host_mbps' => [A('Naprava v LAN – Mb/s'), 'Mb/s'], 'th_attack' => [A('Napad: neuspelih prijav'), A('št.')], 'th_pool' => [A('Zasedenost IP poola'), '%'], 'th_backup_days' => [A('Brez backupa'), A('dni')], 'th_fw_ports' => [A('Skeniranje: različnih portov'), A('v 15 min')], 'th_out_ips' => [A('Okužena naprava: ciljev na en port'), A('v 5 min')], 'th_out_smtp' => [A('Spam: SMTP strežnikov'), A('v 5 min')]];
?>
<form class="form" method="post" action="/devices/<?= $id ?>/save">
  <?= Auth::csrf() ?>
  <section class="panel"><div class="panel-head"><h2><?= icon('settings', 18) ?><?= h(A('Nastavitve naprave')) ?></h2></div>
    <div class="panel-body form"><?php $isNew = false; require __DIR__ . '/_device_form.php'; ?></div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('laptop', 18) ?><?= h(A('Nove naprave v LAN-u')) ?></h2></div>
    <div class="panel-body form">
      <label class="check"><input type="checkbox" name="newdev_alert" value="1" <?= (int)($d['newdev_alert'] ?? 1) ? 'checked' : '' ?>> <?= h(A('Obvesti, ko se v LAN-u prvič pojavi nova naprava (nov MAC naslov)')) ?></label>
      <label class="f"><?= h(A('Brez obvestil za DHCP strežnike')) ?><input type="text" name="newdev_ignore" value="<?= h($d['newdev_ignore'] ?? '') ?>" class="mono" placeholder="hs-dhcp, dhcpVlan50"><small><?= h(A('Npr. gostujoči WiFi, kjer so nove naprave nekaj običajnega. Ločeno z vejico.')) ?></small></label>
    </div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('sliders', 18) ?><?= h(A('Pragovi za to napravo')) ?></h2><span class="small muted"><?= h(A('Prazno = globalna nastavitev')) ?></span></div>
    <div class="panel-body"><div class="form"><div class="row c3">
      <?php foreach ($thLabels as $k => [$l, $u]): ?><label class="f"><span><?= h($l) ?> <small>(<?= h($u) ?>)</small></span><input type="number" step="any" name="th[<?= $k ?>]" value="<?= h($th[$k] ?? '') ?>" placeholder="<?= h(setting($k)) ?>"></label><?php endforeach; ?>
    </div></div></div></section>
  <section class="panel" id="alarm-off"><div class="panel-head"><h2><?= icon('bell-off', 18) ?><?= h(A('Izklopljeni alarmi za to napravo')) ?></h2><span class="small muted"><?= h(A('Izbrane vrste se pri tej napravi ne javljajo več; odprti se zaprejo ob shranjevanju')) ?></span></div>
    <div class="panel-body"><div class="form"><div class="row c3">
      <?php $offNow = (array)($th['off'] ?? []); foreach (Alerts::offable() as $k => $l): ?><label class="check"><input type="checkbox" name="off[]" value="<?= h($k) ?>" <?= in_array($k, $offNow, true) ? 'checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?>
    </div></div></div></section>
  <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani')) ?></button></div>
</form>
<?php
  // optični porti (vstavljen modul, ni bakreni RJ45) + porti z lastnimi pragovi
  $st = db()->prepare('SELECT * FROM sfp_state WHERE device_id=?'); $st->execute([$id]);
  $optic = array_values(array_filter($st->fetchAll(), fn($x) => ($x['present'] && !Devices::sfpCopper($x)) || $x['th_warn'] !== null || $x['th_crit'] !== null || $x['th_high'] !== null));
  usort($optic, fn($a, $b) => strnatcasecmp($a['iface'], $b['iface']));
  $dbm = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');
?>
<section class="panel" id="sfp-th" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('sliders', 18) ?><?= h(A('Optični porti – pragovi alarmov')) ?></h2><span class="small muted"><?= h(A('RX moč v dBm · prazno = privzeto za hitrost modula')) ?></span></div>
  <?php if (!$optic): ?><div class="panel-body small muted"><?= h(A('Naprava nima optičnih modulov (ali jih še ni sporočila).')) ?></div>
  <?php else: ?>
  <form method="post" action="/devices/<?= $id ?>/sfp-th"><?= Auth::csrf() ?>
  <div class="tbl-wrap"><table class="tbl sfp-th">
    <thead><tr><th><?= h(A('Port')) ?></th><th><?= h(A('Modul')) ?></th><th class="num"><?= h(A('RX zdaj')) ?></th><th><?= h(A('Opozorilo pod')) ?></th><th><?= h(A('Kritično pod')) ?></th><th><?= h(A('Premočno nad')) ?></th></tr></thead>
    <tbody><?php foreach ($optic as $o): $def = Alerts::SFP_TH[$o['speed_class']] ?? Alerts::SFP_TH['1g']; $t = Alerts::sfpThresholds($o);
      $cls = $o['rx'] === null ? 'muted' : ((float)$o['rx'] <= $t['crit'] ? 'rx-down' : ((float)$o['rx'] <= $t['warn'] || (float)$o['rx'] >= $t['high'] ? 'rx-warn' : 'rx-up')); $k = h($o['iface']); ?>
      <tr>
        <td class="mono"><a href="/devices/<?= $id ?>?tab=ifaces&amp;i=<?= urlencode($o['iface']) ?>"><?= $k ?></a></td>
        <td class="small"><?= h(trim($o['vendor'] . ' ' . $o['part']) ?: '–') ?> <span class="tag violet"><?= h(strtoupper($o['speed_class'])) ?></span><?= $o['present'] ? '' : ' <span class="tag down">' . h(A('ni vstavljen')) . '</span>' ?></td>
        <td class="num mono <?= $cls ?>"><?= $o['rx'] !== null ? h($dbm($o['rx'])) : h(A('brez DDM')) ?></td>
        <td><input type="text" inputmode="decimal" name="sfp[<?= $k ?>][warn]" value="<?= h($dbm($o['th_warn'])) ?>" placeholder="<?= h($dbm($def['warn'])) ?>" aria-label="<?= h(A('Opozorilo pod')) ?>"></td>
        <td><input type="text" inputmode="decimal" name="sfp[<?= $k ?>][crit]" value="<?= h($dbm($o['th_crit'])) ?>" placeholder="<?= h($dbm($def['crit'])) ?>" aria-label="<?= h(A('Kritično pod')) ?>"></td>
        <td><input type="text" inputmode="decimal" name="sfp[<?= $k ?>][high]" value="<?= h($dbm($o['th_high'])) ?>" placeholder="<?= h($dbm($def['high'])) ?>" aria-label="<?= h(A('Premočno nad')) ?>"></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="panel-body" style="display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <small class="muted"><?= h(A('Opozorilo, ko RX pade pod prvo mejo, kritičen alarm pod drugo. Premočan signal pomeni, da manjka atenuator. Vpiši npr. −18 ali -18,5.')) ?></small>
    <button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani pragove')) ?></button>
  </div>
  </form>
  <?php endif; ?>
</section>
<?php $st = db()->prepare('SELECT * FROM ai_notes WHERE device_id=? ORDER BY id'); $st->execute([$id]); $aiNotes = $st->fetchAll(); ?>
<section class="panel" id="ai-notes" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('Zapiski za AI')) ?></h2><span class="small muted"><?= h(A('AI jih upošteva pri vseh analizah te naprave · v Telegramu: "zapomni si: …"')) ?></span></div>
  <?php if ($aiNotes): ?><ul class="feed"><?php foreach ($aiNotes as $nt): ?><li><span class="sev-bar info"></span><div class="what"><?= h($nt['note']) ?> <span class="small faint">· <?= h($nt['source'] === 'telegram' ? 'Telegram' : A('ročno')) ?>, <?= h(fmt_dt($nt['created_at'])) ?></span></div>
    <form method="post" action="/devices/<?= $id ?>/note" class="when"><?= Auth::csrf() ?><input type="hidden" name="op" value="del"><input type="hidden" name="nid" value="<?= (int)$nt['id'] ?>"><button class="btn sm ghost" title="<?= h(A('Izbriši')) ?>"><?= icon('trash', 14) ?></button></form></li><?php endforeach; ?></ul><?php endif; ?>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/note"><?= Auth::csrf() ?><input type="hidden" name="op" value="add">
    <div class="row" style="grid-template-columns:1fr auto"><label class="f"><span><?= h(A('Nov zapisek')) ?></span><input type="text" name="note" maxlength="500" placeholder="<?= h(A('npr. disk je pri tej napravi vedno okoli 96 % – to je normalno')) ?>"></label>
      <div style="align-self:end"><button class="btn"><?= icon('plus', 15) ?><?= h(A('Dodaj')) ?></button></div></div>
  </form>
</section>
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('Zadnji surovi push')) ?></h2>
    <span class="small muted"><?= $rawPush ? h(date('d.m.Y H:i:s', $rawPush['at']) . ' · ' . fmt_bytes($rawPush['size'])) : h(A('še ni')) ?>
    <?php $dup = explode('|', setting('logdup:' . $id, '0|')); if ((int)$dup[0] > 0): ?> · <span class="tag warn" title="<?= h(A('Vrstice loga, ki jih je router poslal znova in jih NOC ni zapisal')) ?>"><?= h(A('zavrnjenih dvojnikov: {n}', ['n' => (int)$dup[0]])) ?>, <?= h(A('zadnji {t}', ['t' => fmt_ago($dup[1])])) ?></span><?php endif; ?></span></div>
  <?php if ($rawPush): ?><details><summary class="panel-body" style="cursor:pointer;padding:12px 20px"><?= h(A('Prikaži podatke, ki jih je router poslal')) ?></summary><pre class="raw" style="border-top:1px solid var(--line-2)"><?= h($rawPush['json']) ?></pre></details>
  <?php else: ?><div class="panel-body small muted"><?= h(A('Naprava še ni poslala podatkov.')) ?></div><?php endif; ?>
</section>
<div class="grid g2" style="margin-top:20px">
  <section class="panel"><div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('Ponovna analiza konfiguracije')) ?></h2></div>
    <div class="panel-body"><form class="form" method="post" action="/devices/<?= $id ?>/reanalyze" enctype="multipart/form-data"><?= Auth::csrf() ?>
      <p class="small muted" style="margin:0"><?= h(A('Po večjih spremembah na routerju (nov WAN, VLAN-i) naloži svež /export in prenesi nov paket.')) ?></p>
      <input type="file" name="file" accept=".rsc,.txt"><button class="btn"><?= icon('refresh', 16) ?><?= h(A('Analiziraj')) ?></button></form></div></section>
  <section class="panel"><div class="panel-head"><h2 style="color:var(--down)"><?= icon('trash', 18) ?><?= h(A('Izbriši napravo')) ?></h2></div>
    <div class="panel-body"><form class="form" method="post" action="/devices/<?= $id ?>/delete"><?= Auth::csrf() ?>
      <p class="small muted" style="margin:0"><?= h(A('Izbriše vse podatke naprave. Na routerju poženi še noc-remove.rsc.')) ?></p>
      <input type="text" name="confirm" placeholder="<?= h(A('Vpiši ime: {n}', ['n' => $d['name']])) ?>"><button class="btn danger"><?= icon('trash', 16) ?><?= h(A('Izbriši')) ?></button></form></div></section>
</div>
<?php endif; ?>
