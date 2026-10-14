<div class="page-head"><div><h1><?= h(A('Alarmi')) ?></h1><p><?= h(A('Odprti alarmi se zaprejo sami, ko težava izgine. Potrditev le skrije števec v meniju.')) ?></p></div></div>

<section class="panel">
  <div class="panel-head"><h2><?= icon('bell', 18) ?><?= h(A('Odprti')) ?> <span class="tag"><?= count($open) ?></span></h2></div>
  <?php if (!$open): ?><div class="empty"><?= empty_art('ok', 130) ?><div><?= h(A('Ni odprtih alarmov – vse deluje.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Naprava')) ?></th><th><?= h(A('Opis')) ?></th><th><?= h(A('Od')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($open as $a): ?><tr>
      <td><?= sev_tag($a['severity']) ?></td>
      <td><a class="rowlink" href="/devices/<?= (int)$a['device_id'] ?>"><?= h($a['device_name']) ?></a></td>
      <td><?= h($a['message']) ?><?= ai_note($a['ai_note'] ?? null) ?></td>
      <td class="nowrap"><?= h(fmt_dt($a['started_at'])) ?> <span class="faint small">(<?= h(fmt_ago($a['started_at'])) ?>)</span></td>
      <td class="num"><?php if ($a['acked_at']): ?><span class="tag"><?= icon('check', 12) ?><?= h(A('potrjen')) ?></span><?php else: ?><form method="post" action="/alerts/<?= (int)$a['id'] ?>/ack"><?= Auth::csrf() ?><button class="btn sm"><?= icon('check', 14) ?><?= h(A('Potrdi')) ?></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<?php if (!empty($incidents)): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('activity', 18) ?><?= h(A('Incidenti (30 dni)')) ?></h2><span class="small muted"><?= h(A('povezani alarmi ene naprave s časovnico')) ?></span></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Stopnja')) ?></th><th><?= h(A('Naprava')) ?> / <?= h(A('Opis')) ?></th><th><?= h(A('Začetek')) ?></th><th><?= h(A('Trajanje')) ?></th><th class="num"><?= h(A('Alarmov')) ?></th></tr></thead>
    <tbody><?php foreach ($incidents as $inc): ?><tr>
      <td><?= sev_tag($inc['severity']) ?></td>
      <td><a class="rowlink" href="/incidents/<?= (int)$inc['id'] ?>"><b><?= h($inc['device_name']) ?></b> – <?= h($inc['title']) ?></a><?= trim((string)$inc['ai_summary']) !== '' ? ' <span class="tag violet">AI</span>' : '' ?></td>
      <td class="nowrap"><?= h(fmt_dt($inc['started_at'])) ?></td>
      <td class="nowrap"><?= $inc['ended_at'] ? h(Incidents::duration($inc)) : '<span class="tag down">' . h(A('še traja')) . '</span>' ?></td>
      <td class="num muted"><?= (int)$inc['n_alerts'] ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

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
      <td><?= h($a['message']) ?><?= ai_note($a['ai_note'] ?? null) ?></td><td class="nowrap"><?= h(fmt_dt($a['started_at'])) ?></td><td class="nowrap muted"><?= $dur > 59 ? h(fmt_uptime($dur)) : '–' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
