<?php
/** @var array $r  (Report::build)  @var bool $forMail */
$month = Report::monthName($r['ym']);
$t = $r['tenant'];
$C = ['ink' => '#1c1f2e', 'muted' => '#6a7088', 'line' => '#e3e6ee', 'violet' => '#6b4fd8', 'aqua' => '#0f9fb5', 'up' => '#1d9a6c', 'warn' => '#d98200', 'down' => '#d6394a'];
$slaColor = fn($p) => $p === null ? $C['muted'] : ($p >= 99.9 ? $C['up'] : ($p >= 99 ? $C['warn'] : $C['down']));
$dur = fn(int $s) => $s < 60 ? $s . ' s' : ($s < 3600 ? intdiv($s, 60) . ' min' : intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min');
?><!doctype html>
<html lang="<?= h($GLOBALS['LANG']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(A('Mesečno poročilo')) ?> – <?= h($t['name'] ?? '') ?> – <?= h($month) ?></title>
<style>
  body { margin: 0; background: #eef0f5; font: 14px/1.5 'Segoe UI', Arial, sans-serif; color: <?= $C['ink'] ?>; }
  .doc { max-width: 760px; margin: 0 auto; background: #fff; }
  .head { background: <?= $C['ink'] ?>; color: #fff; padding: 28px 32px; }
  .head small { color: #b9bdd3; display: block; font-size: 12px; }
  .head h1 { margin: 6px 0 2px; font-size: 24px; font-weight: 600; }
  .sec { padding: 24px 32px; border-bottom: 1px solid <?= $C['line'] ?>; }
  h2 { font-size: 17px; margin: 0 0 4px; }
  .sub { color: <?= $C['muted'] ?>; font-size: 12px; margin: 0 0 16px; }
  table { border-collapse: collapse; width: 100%; }
  .kpi td { width: 25%; padding: 10px 12px; border: 1px solid <?= $C['line'] ?>; vertical-align: top; }
  .kpi .k { font-size: 11px; color: <?= $C['muted'] ?>; }
  .kpi .v { font-size: 20px; font-weight: 700; }
  .list th { text-align: left; font-size: 11px; color: <?= $C['muted'] ?>; font-weight: 600; padding: 6px 8px; border-bottom: 1px solid <?= $C['line'] ?>; }
  .list td { padding: 6px 8px; border-bottom: 1px solid #f1f2f6; font-size: 13px; }
  .num { text-align: right; white-space: nowrap; }
  .bars td { vertical-align: bottom; padding: 0 1px; height: 90px; }
  .foot { padding: 18px 32px 28px; color: <?= $C['muted'] ?>; font-size: 11px; }
  .noprint { text-align: right; padding: 12px 32px; background: #f6f7fa; }
  .noprint a, .noprint button { font: inherit; font-size: 13px; padding: 7px 14px; border-radius: 8px; border: 1px solid <?= $C['line'] ?>; background: #fff; color: <?= $C['ink'] ?>; text-decoration: none; cursor: pointer; margin-left: 6px; }
  @media print { body { background: #fff; } .noprint { display: none; } .sec { page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="doc">
<?php if (!$forMail): ?>
  <div class="noprint">
    <a href="/reports"><?= h(A('Nazaj')) ?></a>
    <button onclick="window.print()"><?= h(A('Natisni / shrani PDF')) ?></button>
  </div>
<?php endif; ?>
  <div class="head">
    <small>NOC – Network Operations Center · Računalniške storitve Miha Dežman s.p.</small>
    <h1><?= h(A('Mesečno poročilo o omrežju')) ?></h1>
    <div><?= h($t['name'] ?? '') ?> · <?= h($month) ?></div>
  </div>

<?php if (!$r['devices']): ?>
  <div class="sec"><p><?= h(A('V tem mesecu za naročnika ni bilo spremljanih naprav.')) ?></p></div>
<?php endif; ?>

<?php foreach ($r['devices'] as $x): $d = $x['d']; $sla = $x['sla']; ?>
  <div class="sec">
    <h2><?= h($d['name']) ?></h2>
    <p class="sub"><?= h(trim(($d['site'] ? $d['site'] . ' · ' : '') . ($d['model'] ?: 'MikroTik') . ' · RouterOS ' . preg_replace('/\s.*$/', '', (string)$d['os_version']))) ?></p>

    <table class="kpi"><tr>
      <td><div class="k"><?= h(A('Razpoložljivost')) ?></div><div class="v" style="color:<?= $slaColor($sla['pct']) ?>"><?= h(Sla::fmt($sla['pct'])) ?></div><div class="k"><?= $sla['count'] ? h(A('{n} izpadov, skupaj {t}', ['n' => $sla['count'], 't' => $dur($sla['down_s'])])) : h(A('brez izpadov')) ?></div></td>
      <td><div class="k"><?= h(A('Prenos WAN')) ?></div><div class="v"><?= h(fmt_bytes($x['rx'] + $x['tx'])) ?></div><div class="k">↓ <?= h(fmt_bytes($x['rx'])) ?> · ↑ <?= h(fmt_bytes($x['tx'])) ?></div></td>
      <td><div class="k"><?= h(A('Najvišja hitrost')) ?></div><div class="v"><?= h(fmt_bps($x['peakRx'])) ?></div><div class="k">↑ <?= h(fmt_bps($x['peakTx'])) ?><?= $d['wan_down_mbps'] ? ' · ' . h(A('zakup {d}/{u} Mb/s', ['d' => $d['wan_down_mbps'], 'u' => $d['wan_up_mbps'] ?: '?'])) : '' ?></div></td>
      <td><div class="k"><?= h(A('Latenca do interneta')) ?></div><div class="v"><?= $x['lat']['ext'] !== null ? number_format((float)$x['lat']['ext'], 1, ',', '') . ' ms' : '–' ?></div><div class="k"><?= $x['lat']['gw'] !== null ? h(A('do ponudnika {m} ms', ['m' => number_format((float)$x['lat']['gw'], 1, ',', '')])) : '' ?></div></td>
    </tr></table>

<?php if ($x['daily']): $max = max(array_map(fn($r) => $r['rx_bytes'] + $r['tx_bytes'], $x['daily'])) ?: 1; $days = (int)date('t', $r['from']); ?>
    <p class="sub" style="margin:18px 0 6px"><?= h(A('Dnevni prenos')) ?></p>
    <table class="bars"><tr>
    <?php for ($i = 0; $i < $days; $i++): $day = date('Y-m-d', strtotime("+$i day", $r['from'])); $v = isset($x['daily'][$day]) ? $x['daily'][$day]['rx_bytes'] + $x['daily'][$day]['tx_bytes'] : 0; $hgt = max(1, (int)round($v / $max * 86)); ?>
      <td title="<?= h(date('d.m.', strtotime($day)) . ' ' . fmt_bytes($v)) ?>"><div style="height:<?= $hgt ?>px;background:<?= $v ? $C['aqua'] : $C['line'] ?>;border-radius:2px 2px 0 0"></div></td>
    <?php endfor; ?>
    </tr><tr><?php for ($i = 0; $i < $days; $i++): ?><td style="height:auto;font-size:9px;color:<?= $C['muted'] ?>;text-align:center"><?= ($i % 5 === 0) ? $i + 1 : '' ?></td><?php endfor; ?></tr></table>
<?php endif; ?>

    <table class="list" style="margin-top:18px">
      <tr><th><?= h(A('Dogodki v mesecu')) ?></th><th class="num"></th></tr>
      <tr><td><?= h(A('Kritični alarmi')) ?></td><td class="num"><?= (int)($x['al']['critical'] ?? 0) ?></td></tr>
      <tr><td><?= h(A('Opozorila')) ?></td><td class="num"><?= (int)($x['al']['warning'] ?? 0) ?></td></tr>
      <tr><td><?= h(A('Ponovni zagoni routerja')) ?></td><td class="num"><?= $x['reboots'] ?></td></tr>
      <tr><td><?= h(A('Zaznani napadi na router (zavrnjene prijave)')) ?></td><td class="num"><?= $x['attacks'] ?></td></tr>
      <tr><td><?= h(A('Spremembe konfiguracije')) ?></td><td class="num"><?= $x['changes'] ?></td></tr>
      <tr><td><?= h(A('Naprav v omrežju (DHCP)')) ?></td><td class="num"><?= $x['hosts'] ?></td></tr>
    </table>

<?php if ($sla['outages']): ?>
    <table class="list" style="margin-top:18px">
      <tr><th><?= h(A('Izpadi')) ?></th><th><?= h(A('Začetek')) ?></th><th class="num"><?= h(A('Trajanje')) ?></th></tr>
      <?php foreach (array_slice($sla['outages'], 0, 15) as $o): ?>
      <tr><td><?= h($o['key'] === 'offline' ? A('Naprava nedosegljiva') : A('Internet ne deluje')) ?></td><td><?= h(date('d.m. H:i', $o['from'])) ?></td><td class="num"><?= h($dur(max(0, $o['to'] - $o['from']))) ?></td></tr>
      <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php if ($x['top']): ?>
    <table class="list" style="margin-top:18px">
      <tr><th><?= h(A('Največ prometa v LAN-u')) ?></th><th>IP</th><th class="num"><?= h(A('Skupaj')) ?></th></tr>
      <?php foreach ($x['top'] as $tp): ?><tr><td><?= h($tp['name'] ?: '–') ?></td><td><?= h($tp['ip']) ?></td><td class="num"><?= h(fmt_bytes($tp['up'] + $tp['down'])) ?></td></tr><?php endforeach; ?>
    </table>
<?php endif; ?>
  </div>
<?php endforeach; ?>

  <div class="foot">
    <?= h(A('Razpoložljivost upošteva čas, ko naprava ni bila dosegljiva ali ni imela internetne povezave. Podatki so zbrani samodejno vsako minuto.')) ?><br>
    <?= h(A('Ustvarjeno {t}', ['t' => date('d.m.Y H:i')])) ?> · noc.dezman.net
  </div>
</div>
</body>
</html>
