<?php
$cronDef = ['minute' => [A('Alarmi in ping s strežnika'), 3], 'flows' => [A('Traffic-flow'), 12], 'daily' => [A('Dnevno stiskanje in čiščenje'), 1500]];
?>
<div class="page-head"><div><h1><?= h(A('Sistem')) ?></h1><p><?= h(A('Stanje NOC na strežniku.')) ?></p></div></div>
<div class="grid g3">
  <section class="panel"><div class="panel-head"><h2><?= icon('clock', 18) ?>Cron</h2></div><div class="panel-body"><dl class="kv">
    <?php foreach ($cronDef as $k => [$l, $maxMin]): $r = $crons[$k] ?? null; $late = !$r || strtotime($r['last_at']) < time() - $maxMin * 60; ?>
      <dt><?= h($l) ?></dt><dd><?= $r ? h(fmt_ago($r['last_at'])) : '–' ?> <?= $late ? '<span class="tag warn">' . h(A('preveri')) . '</span>' : '<span class="tag up">OK</span>' ?><?php if ($r && $r['info']): ?><div class="small faint"><?= h($r['info']) ?></div><?php endif; ?></dd>
    <?php endforeach; ?>
  </dl></div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('waypoints', 18) ?>Traffic-flow</h2></div><div class="panel-body"><dl class="kv">
    <dt>nfcapd</dt><dd><?= $nfcapd ? '<span class="tag up">' . h(A('teče')) . '</span>' : '<span class="tag down">' . h(A('ne teče')) . '</span>' ?></dd>
    <dt><?= h(A('Zadnja datoteka')) ?></dt><dd><?= $newest ? h(fmt_ago(date('Y-m-d H:i:s', $newest))) : '–' ?></dd>
    <dt><?= h(A('Datotek')) ?></dt><dd><?= count($flowFiles) ?></dd>
    <dt><?= h(A('UDP port')) ?></dt><dd class="mono"><?= (int)cfg('flow_port', 2055) ?></dd>
    <dt><?= h(A('Dovoljeni IP-ji (ufw)')) ?></dt><dd class="mono small"><?= $flowIps ? h(implode(', ', $flowIps)) : '–' ?></dd>
  </dl></div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('hdd', 18) ?><?= h(A('Disk in baza')) ?></h2></div><div class="panel-body">
    <?php if ($disk['total']): $used = (1 - $disk['free'] / $disk['total']) * 100; ?>
    <div class="small muted" style="margin-bottom:6px"><?= h(A('Disk: prosto {f} od {t}', ['f' => fmt_bytes($disk['free']), 't' => fmt_bytes($disk['total'])])) ?></div><?= pct_meter($used, 80, 92) ?>
    <?php endif; ?>
    <dl class="kv" style="margin-top:14px"><?php foreach (array_slice($dbSize, 0, 8) as $t): ?><dt class="mono small"><?= h($t['t']) ?></dt><dd class="small"><?= h($t['mb']) ?> MB · <?= number_format((int)$t['r'], 0, ',', '.') ?></dd><?php endforeach; ?></dl>
  </div></section>
</div>
<section class="panel"><div class="panel-head"><h2><?= icon('history', 18) ?><?= h(A('Revizijska sled')) ?></h2></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th><?= h(A('Čas')) ?></th><th><?= h(A('Uporabnik')) ?></th><th><?= h(A('Dejanje')) ?></th><th><?= h(A('Podrobnosti')) ?></th><th>IP</th></tr></thead><tbody>
  <?php foreach ($audit as $a): ?><tr><td class="nowrap muted"><?= h(fmt_dt($a['at'])) ?></td><td><?= h($a['name'] ?? '–') ?></td><td class="mono small"><?= h($a['action']) ?></td><td><?= h($a['detail']) ?></td><td class="mono small muted"><?= h($a['ip']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
