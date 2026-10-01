<div class="page-head"><div><h1><?= h(A('Alarmi')) ?></h1><p><?= h(A('Odprti alarmi se zaprejo sami, ko težava izgine. Potrditev le skrije števec v meniju.')) ?></p></div></div>

<section class="panel">
  <div class="panel-head"><h2><?= icon('bell', 18) ?><?= h(A('Odprti')) ?> <span class="tag"><?= count($open) ?></span></h2></div>
  <?php if (!$open): ?><div class="empty"><?= icon('shield', 32) ?><div><?= h(A('Ni odprtih alarmov – vse deluje.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Naprava')) ?></th><th><?= h(A('Opis')) ?></th><th><?= h(A('Od')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($open as $a): ?><tr>
      <td><?= sev_tag($a['severity']) ?></td>
      <td><a class="rowlink" href="/devices/<?= (int)$a['device_id'] ?>"><?= h($a['device_name']) ?></a></td>
      <td><?= h($a['message']) ?></td>
      <td class="nowrap"><?= h(fmt_dt($a['started_at'])) ?> <span class="faint small">(<?= h(fmt_ago($a['started_at'])) ?>)</span></td>
      <td class="num"><?php if ($a['acked_at']): ?><span class="tag"><?= icon('check', 12) ?><?= h(A('potrjen')) ?></span><?php else: ?><form method="post" action="/alerts/<?= (int)$a['id'] ?>/ack"><?= Auth::csrf() ?><button class="btn sm"><?= icon('check', 14) ?><?= h(A('Potrdi')) ?></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head">
    <h2><?= icon('history', 18) ?><?= h(A('Zgodovina')) ?></h2>
    <form class="filters" method="get">
      <select name="d" data-autosubmit><option value=""><?= h(A('Vse naprave')) ?></option><?php foreach ($devs as $dv): ?><option value="<?= (int)$dv['id'] ?>" <?= (int)($_GET['d'] ?? 0) === (int)$dv['id'] ? 'selected' : '' ?>><?= h($dv['name']) ?></option><?php endforeach; ?></select>
      <select name="sev" data-autosubmit><option value=""><?= h(A('Vse stopnje')) ?></option><?php foreach (['critical', 'warning', 'info'] as $sv): ?><option value="<?= $sv ?>" <?= ($_GET['sev'] ?? '') === $sv ? 'selected' : '' ?>><?= h($sv) ?></option><?php endforeach; ?></select>
    </form>
  </div>
  <?php if (!$history): ?><div class="empty"><?= h(A('Ni zapisov.')) ?></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Naprava')) ?></th><th><?= h(A('Opis')) ?></th><th><?= h(A('Začetek')) ?></th><th><?= h(A('Trajanje')) ?></th></tr></thead>
    <tbody><?php foreach ($history as $a): $dur = strtotime($a['ended_at']) - strtotime($a['started_at']); ?><tr>
      <td><?= sev_tag($a['severity']) ?></td><td><a href="/devices/<?= (int)$a['device_id'] ?>"><?= h($a['device_name']) ?></a> <span class="small faint"><?= h($a['tenant_name'] ?? '') ?></span></td>
      <td><?= h($a['message']) ?></td><td class="nowrap"><?= h(fmt_dt($a['started_at'])) ?></td><td class="nowrap muted"><?= $dur > 59 ? h(fmt_uptime($dur)) : '–' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
