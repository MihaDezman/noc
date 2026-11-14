<?php
// Pregled stikala: tabela portov (klik na vrstico odpre panel porta). Pričakuje $d, $id.
$swIf = []; $st = db()->prepare('SELECT * FROM device_ifaces WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) if (SwitchIngest::isPort($r['name'])) $swIf[$r['name']] = $r;
$swP = []; $st = db()->prepare('SELECT * FROM sw_ports WHERE device_id=?'); $st->execute([$id]); foreach ($st as $r) $swP[$r['port']] = $r;
$swH = SwitchIngest::hostsByPort($d); $swUp = SwitchIngest::uplink($d); $swW = Devices::list((string)$d['down_ifaces']);
uksort($swIf, 'strnatcasecmp');
?>
<section class="panel" id="sw-ports">
  <div class="panel-head"><h2><?= icon('ethernet', 18) ?><?= h(A('Porti')) ?></h2><span class="small muted"><?= h(A('{u} od {n} povezanih', ['u' => count(array_filter($swIf, fn($i) => (int)$i['running'])), 'n' => count($swIf)])) ?></span></div>
  <?php if (!$swIf): ?><div class="panel-body small muted"><?= h(A('Stikalo še ni poslalo podatkov o portih.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl sw-table">
    <thead><tr><th><?= h(A('Port')) ?></th><th><?= h(A('Naprava')) ?></th><th><?= h(A('Stanje')) ?></th><th>VLAN</th><th>PoE</th></tr></thead>
    <tbody><?php foreach ($swIf as $n => $i): $p = $swP[$n] ?? null; $hh2 = $swH[$n] ?? null;
      $ut = $p ? array_filter(explode(',', (string)$p['untagged'])) : []; $tg = $p ? array_filter(explode(',', (string)$p['tagged'])) : []; ?>
      <tr data-sw-port="<?= h($n) ?>" data-dev="<?= $id ?>" tabindex="0">
        <td class="nowrap"><b><?= h(SwLayout::label($n)) ?></b><?= $n === $swUp ? ' <span class="tag violet">uplink</span>' : '' ?><?= in_array($n, $swW, true) ? ' ' . icon('bell', 12, 'faint') : '' ?><?php if ($i['comment'] !== ''): ?><div class="small muted"><?= h($i['comment']) ?></div><?php endif; ?></td>
        <td><?= $hh2 ? h($hh2['name'] !== '' ? $hh2['name'] : $hh2['mac']) . ($hh2['ip'] !== '' ? ' <span class="mono faint small">' . h($hh2['ip']) . '</span>' : '') . ($hh2['n'] > 1 ? ' <span class="faint small">+' . ($hh2['n'] - 1) . '</span>' : '') : '<span class="faint">–</span>' ?></td>
        <td class="nowrap"><?= (int)$i['disabled'] ? '<span class="tag">' . h(A('izklopljen')) . '</span>' : ((int)$i['running'] ? '<span class="tag up">' . h($i['rate'] ?: A('povezan')) . '</span>' : '<span class="tag">' . h(A('brez povezave')) . '</span>') ?></td>
        <td class="nowrap"><?php if (!$p): ?><span class="faint">–</span><?php elseif ($p['mode'] === 'trunk'): ?><span class="tag violet">trunk</span> <span class="small mono"><?= h(implode(',', $tg)) ?></span><?php else: $v = (int)($ut ? reset($ut) : $p['pvid']); ?><i class="sw-dot" style="background:<?= SwLayout::vlanColor($v) ?>"></i><?= $v ?><?php endif; ?></td>
        <td class="nowrap small"><?= $p && $p['poe_w'] !== null && (float)$p['poe_w'] > 0 ? h(number_format((float)$p['poe_w'], 1, ',', '') . ' W') : '<span class="faint">–</span>' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
