<?php
$n = count($devs);
$cnt = ['up' => 0, 'warn' => 0, 'down' => 0, 'new' => 0];
$rx = 0; $tx = 0; $lat = [];
foreach ($devs as $d) {
    $cnt[$d['state']]++;
    foreach ($ifaces[$d['id']] ?? [] as $i) if ($i['name'] === $d['wan_iface']) { $rx += $i['rx_bps']; $tx += $i['tx_bps']; }
    if ($d['online'] && isset($d['status']['h']['gw_ms'])) $lat[] = (float)$d['status']['h']['gw_ms'];
}
$crit = count(array_filter($alerts, fn($a) => $a['severity'] === 'critical'));
$warnN = count(array_filter($alerts, fn($a) => $a['severity'] === 'warning'));
$working = $cnt['up'] + $cnt['warn'];
$pct = fn($k) => $n ? round($cnt[$k] / $n * 100, 2) : 0;
?>
<div class="page-head">
  <div>
    <h1>NOC – Network Operations Center</h1>
    <p><?= h(A('Pregled')) ?> · <?= h(A('Osveženo ob {t}', ['t' => date('H:i')])) ?> · <?= h(A('stran se osveži vsako minuto')) ?></p>
  </div>
</div>

<?php if (!$n): ?>
<div class="panel"><div class="empty">
  <?= empty_art('empty', 140) ?>
  <h2><?= h(A('Še nobena naprava ni povezana')) ?></h2>
  <p><?= h(A('Dodaj prvi router: naloži njegov /export, NOC pripravi skripto, ti jo uvoziš na router.')) ?></p>
  <?php if (Auth::isSuper()): ?><p><a class="btn primary" href="/devices/new"><?= icon('plus', 16) ?><?= h(A('Dodaj napravo')) ?></a></p><?php endif; ?>
</div></div>
<?php else: ?>

<section class="panel fleet" aria-label="<?= h(A('Stanje omrežja')) ?>">
  <div class="fleet-state">
    <?= fibres_svg('fibres', 360, 210) ?>
    <div style="position:relative">
      <div class="greet"><?= h(greeting()) ?></div>
      <div class="big"><?= $working ?><small> / <?= $n ?></small></div>
      <p><?= $cnt['down'] ? h($cnt['down'] . ' ' . plural($cnt['down'], A('naprava ne deluje'), A('napravi ne delujeta'), A('naprave ne delujejo'), A('naprav ne deluje')) . ' – ' . A('poglej alarme.')) : ($cnt['warn'] ? h(A('Vse naprave so dosegljive, nekatere z opozorili.')) : h(A('Vse naprave delujejo brez težav.'))) ?></p>
    </div>
    <div style="position:relative;display:grid;gap:10px">
      <div class="fleet-bar" role="img" aria-label="<?= h("{$cnt['up']} / {$cnt['warn']} / {$cnt['down']}") ?>">
        <i style="width:<?= $pct('up') ?>%;background:#2ee59d"></i><i style="width:<?= $pct('warn') ?>%;background:#ffb020"></i><i style="width:<?= $pct('down') ?>%;background:#ff4d61"></i><i style="width:<?= $pct('new') ?>%;background:#5d627e"></i>
      </div>
      <div class="fleet-legend">
        <span><span class="dot up"></span><?= $cnt['up'] ?> <?= h(A('deluje')) ?></span>
        <span><span class="dot warn"></span><?= $cnt['warn'] ?> <?= h(A('opozorilo')) ?></span>
        <span><span class="dot down"></span><?= $cnt['down'] ?> <?= h(A('ne deluje')) ?></span>
        <?php if ($cnt['new']): ?><span><span class="dot"></span><?= $cnt['new'] ?> <?= h(A('čaka')) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="fleet-stats">
    <a class="fleet-link" href="/alerts" title="<?= h(A('Odpri alarme')) ?>"><span class="k"><span class="badge b-red"><?= icon('bell', 17) ?></span><?= h(A('Alarmi')) ?><?= icon('chev', 14, 'go') ?></span><span class="v" style="color:<?= $crit ? 'var(--down)' : ($warnN ? 'var(--warn)' : 'inherit') ?>"><?= $crit + $warnN ?></span><span class="s"><?= $crit ?> <?= h(plural($crit, A('kritičen'), A('kritična'), A('kritični'), A('kritičnih'))) ?>, <?= $warnN ?> <?= h(plural($warnN, A('opozorilo'), A('opozorili'), A('opozorila'), A('opozoril'))) ?></span></a>
    <div><span class="k"><span class="badge b-aqua"><?= icon('arrow-down', 17) ?></span><?= h(A('Download')) ?></span><span class="v down-c"><?= h(fmt_bps($rx)) ?></span><span class="s"><?= h(A('WAN zdaj · vsota vseh naprav')) ?></span></div>
    <div><span class="k"><span class="badge b-violet"><?= icon('arrow-up', 17) ?></span><?= h(A('Upload')) ?></span><span class="v up-c"><?= h(fmt_bps($tx)) ?></span><span class="s"><?= h(A('WAN zdaj · vsota vseh naprav')) ?></span></div>
    <div><span class="k"><span class="badge b-yellow"><?= icon('ping', 17) ?></span><?= h(A('Latenca')) ?></span><span class="v"><?= $lat ? number_format(array_sum($lat) / count($lat), 1, ',', '') . ' ms' : '–' ?></span><span class="s"><?= $lat ? h(A('do prehoda · najslabša {m} ms', ['m' => number_format(max($lat), 1, ',', '')])) : h(A('do prehoda · ni podatkov')) ?></span></div>
  </div>
</section>

<?php [$dgAt, $dgTxt] = array_pad(explode("\n", (string)setting('ai_digest_text', ''), 2), 2, ''); if ($dgTxt !== '' && Ai::on()): ?>
<details class="panel ai-digest"><summary class="panel-head"><h2><?= icon('zap', 18) ?><?= h(A('AI povzetek')) ?></h2><span class="small muted"><?= h($dgAt) ?></span></summary>
  <div class="panel-body"><?= nl2br(h(trim($dgTxt))) ?></div></details>
<?php endif; ?>
<div class="page-head" style="margin:30px 0 14px"><h2><?= h(A('Naprave')) ?></h2><a class="small" href="/devices"><?= h(A('Seznam vseh')) ?></a></div>
<div class="racks">
<?php foreach ($devs as $d):
    $ports = face_ports($ifaces[$d['id']] ?? [], $d['wan_iface'], 14);
    $wanRow = null; foreach ($ifaces[$d['id']] ?? [] as $i) if ($i['name'] === $d['wan_iface']) $wanRow = $i;
    $hh = $d['status']['h'] ?? [];
    $da = array_values(array_filter($alerts, fn($a) => (int)$a['device_id'] === (int)$d['id'] && $a['severity'] !== 'info'));
?>
  <a class="unit <?= h($d['state']) ?>" href="/devices/<?= (int)$d['id'] ?>">
    <div class="unit-face">
      <span class="led-status" title="<?= h(state_label($d['state'])) ?>"></span>
      <span class="unit-model"><?= h($d['model'] ?: 'MikroTik') ?></span>
      <span class="ports"><?php foreach ($ports as $p) echo port_html($p); ?></span>
    </div>
    <div class="unit-body">
      <div class="unit-title">
        <div class="unit-name"><span class="unit-art"><?= device_art((string)$d['model'], (string)$d['kind'], 42) ?></span><div><b><?= h($d['name']) ?></b><div class="sub"><?= h($d['tenant_name'] ?: '–') ?><?= $d['site'] ? ' · ' . h($d['site']) : '' ?></div></div></div>
        <?= state_tag($d) ?>
      </div>
      <div class="unit-metrics">
        <div><?= h(A('Download')) ?><b class="down-c"><?= $wanRow ? h(fmt_bps($wanRow['rx_bps'])) : '–' ?></b></div>
        <div><?= h(A('Upload')) ?><b class="up-c"><?= $wanRow ? h(fmt_bps($wanRow['tx_bps'])) : '–' ?></b></div>
        <div>CPU<b><?= isset($hh['cpu']) ? (int)$hh['cpu'] . ' %' : '–' ?></b></div>
        <div><?= h(A('Ping')) ?><b><?= isset($hh['gw_ms']) && $hh['gw_ms'] !== null ? number_format((float)$hh['gw_ms'], 1, ',', '') . ' ms' : '–' ?></b></div>
      </div>
      <?php if ($da): ?><div class="unit-alert <?= $da[0]['severity'] === 'warning' ? 'warn' : '' ?>"><?= icon('alert', 15) ?><span><?= h($da[0]['message']) ?><?= count($da) > 1 ? ' (+' . (count($da) - 1) . ')' : '' ?></span></div>
      <?php elseif ($d['state'] === 'new'): ?><div class="unit-alert" style="color:var(--muted)"><?= icon('clock', 15) ?><span><?= h(A('Naloži paket na router')) ?></span></div>
      <?php else: ?><div class="unit-alert" style="color:var(--muted)"><?= icon('clock', 15) ?><span><?= h(A('Uptime {u}', ['u' => fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null)])) ?> · <?= h(fmt_ago($d['last_seen_at'])) ?></span></div><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>

<?php $secDevs = array_values(array_filter($devs, fn($x) => $x['sec_score'] !== null)); usort($secDevs, fn($a, $b) => (int)$a['sec_score'] <=> (int)$b['sec_score']);
  $ids = implode(',', array_map(fn($x) => (int)$x['id'], $devs)) ?: '0';
  $sevCnt = db()->query("SELECT SUM(akey LIKE 'threat:%' OR akey LIKE 'outbl:%') thr, SUM(akey LIKE 'anom:%') anom, SUM(akey LIKE 'geo:%') geo, SUM(akey LIKE 'wggeo:%') wg FROM alerts WHERE device_id IN ($ids) AND started_at > NOW() - INTERVAL 7 DAY")->fetch();
  $blk7 = (int)db()->query("SELECT COALESCE(SUM(hits),0) FROM fw_daily WHERE device_id IN ($ids) AND kind IN ('in','bl_in') AND day > CURDATE() - INTERVAL 7 DAY")->fetchColumn();
  $vpnOn = (int)db()->query("SELECT COUNT(*) FROM wg_peers WHERE device_id IN ($ids) AND enabled=1 AND last_hs_at > NOW() - INTERVAL 3 MINUTE")->fetchColumn();
  if ($secDevs): $avg = (int)round(array_sum(array_column($secDevs, 'sec_score')) / count($secDevs)); ?>
<div class="section-break"><h2><?= icon('shield', 18) ?><?= h(A('Varnost')) ?></h2></div>
<div class="grid g2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Varnostne ocene')) ?></h2><span class="score-badge <?= Score::cls($avg) ?>" title="<?= h(A('povprečje')) ?>"><?= $avg ?><small>/100</small></span></div>
    <ul class="feed"><?php foreach (array_slice($secDevs, 0, 6) as $x): ?><li><span class="sev-bar <?= h(['up' => 'info', 'warn' => 'warning', 'down' => 'critical'][Score::cls((int)$x['sec_score'])] ?? 'info') ?>"></span><div class="what"><a href="/devices/<?= (int)$x['id'] ?>#sec"><?= h($x['name']) ?></a> <span class="small faint"><?= h($x['tenant_name'] ?: '') ?></span></div><span class="score-badge <?= Score::cls((int)$x['sec_score']) ?>" style="font-size:.9rem"><?= (int)$x['sec_score'] ?></span></li><?php endforeach; ?></ul>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('activity', 18) ?><?= h(A('Zadnjih 7 dni')) ?></h2></div>
    <ul class="feed">
      <li><span class="sev-bar info"></span><div class="what"><?= h(A('Zavrnjeni poskusi z interneta')) ?></div><b><?= number_format($blk7, 0, ',', '.') ?></b></li>
      <li><span class="sev-bar <?= (int)$sevCnt['thr'] ? 'critical' : 'info' ?>"></span><div class="what"><?= h(A('Stik z zlonamernimi strežniki')) ?></div><b><?= (int)$sevCnt['thr'] ?></b></li>
      <li><span class="sev-bar <?= (int)$sevCnt['anom'] ? 'warning' : 'info' ?>"></span><div class="what"><?= h(A('Nenavaden promet pametnih naprav')) ?></div><b><?= (int)$sevCnt['anom'] ?></b></li>
      <li><span class="sev-bar <?= (int)$sevCnt['geo'] ? 'warning' : 'info' ?>"></span><div class="what"><?= h(A('Pametne naprave v novih državah')) ?></div><b><?= (int)$sevCnt['geo'] ?></b></li>
      <li><span class="sev-bar <?= (int)$sevCnt['wg'] ? 'warning' : 'info' ?>"></span><div class="what"><?= h(A('VPN prijave iz nove države')) ?></div><b><?= (int)$sevCnt['wg'] ?></b></li>
      <li><span class="sev-bar info"></span><div class="what"><?= h(A('VPN uporabnikov povezanih zdaj')) ?></div><b><?= $vpnOn ?></b></li>
    </ul>
  </section>
</div>
<?php endif; ?>

<div class="section-break"><h2><?= icon('bell', 18) ?><?= h(A('Alarmi in dogodki')) ?></h2></div>
<div class="grid g2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('bell', 18) ?><?= h(A('Odprti alarmi')) ?></h2><a class="small" href="/alerts"><?= h(A('Vsi alarmi')) ?></a></div>
    <?php if (!$alerts): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('Ni odprtih alarmov.')) ?></div></div>
    <?php else: ?><ul class="feed">
      <?php foreach (array_slice($alerts, 0, 10) as $a): ?>
      <li><span class="sev-bar <?= h($a['severity']) ?>"></span><div class="what"><b><a href="/devices/<?= (int)$a['device_id'] ?>"><?= h($a['device_name']) ?></a></b><div><?= h($a['message']) ?></div></div><span class="when"><?= h(fmt_ago($a['started_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('scroll', 18) ?><?= h(A('Opozorila v logih (24 h)')) ?></h2><a class="small" href="/logs?sev=warning"><?= h(A('Vsi logi')) ?></a></div>
    <?php if (!$logs): ?><div class="empty"><?= empty_art('ok') ?><div><?= h(A('V zadnjih 24 urah ni opozoril ali napak.')) ?></div></div>
    <?php else: ?><ul class="feed">
      <?php foreach ($logs as $l): ?>
      <li><span class="sev-bar <?= h($l['severity']) ?>"></span><div class="what"><b><a href="/devices/<?= (int)$l['device_id'] ?>?tab=logs"><?= h($l['device_name']) ?></a></b> <span class="faint small"><?= h($l['topics']) ?></span><div class="mono small"><?= h($l['message']) ?></div></div><span class="when"><?= h(fmt_dt($l['ts'])) ?></span></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </section>
</div>
<?php endif; ?>
