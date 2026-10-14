<?php /** @var array $i @var array $timeline */ ?>
<div class="page-head">
  <div><div class="crumbs"><a href="/alerts"><?= h(A('Alarmi')) ?></a><?= icon('chev', 14) ?><a href="/devices/<?= (int)$i['device_id'] ?>?tab=alerts"><?= h($i['device_name']) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h($i['title']) ?></h1>
  <p><?= sev_tag($i['severity']) ?> <?= h(fmt_dt($i['started_at'])) ?> – <?= $i['ended_at'] ? h(fmt_dt($i['ended_at'])) : '<span class="tag down">' . h(A('še traja')) . '</span>' ?> · <?= h(A('trajanje {t}', ['t' => Incidents::duration($i)])) ?></p></div>
</div>
<?php if (trim((string)$i['ai_summary']) !== ''): ?>
<section class="panel"><div class="panel-body"><?= ai_note($i['ai_summary'], true) ?></div></section>
<?php elseif ($i['ended_at'] && $i['ai_summary'] === null && Ai::on('incidents')): ?>
<section class="panel"><div class="panel-body small muted"><?= h(A('AI povzetek bo pripravljen v nekaj minutah po koncu incidenta.')) ?></div></section>
<?php endif; ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('history', 18) ?><?= h(A('Časovnica')) ?></h2></div>
  <?php if (!$timeline): ?><div class="empty small"><?= h(A('Ni zapisov.')) ?></div><?php else: ?>
  <ul class="feed timeline"><?php foreach ($timeline as $e):
    $lbl = ['start' => A('začetek'), 'end' => A('konec'), 'event' => A('dogodek'), 'log' => A('log')][$e['kind']]; ?>
    <li class="tl-<?= h($e['kind']) ?>"><span class="sev-bar <?= h($e['kind'] === 'end' ? 'ok' : $e['sev']) ?>"></span>
      <div class="what"><span class="tag <?= $e['kind'] === 'end' ? 'up' : ($e['kind'] === 'log' ? '' : h($e['sev'])) ?>"><?= h($lbl) ?></span> <span class="<?= $e['kind'] === 'log' ? 'mono small' : '' ?>"><?= h($e['text']) ?></span></div>
      <span class="when"><?= h(date('d.m. H:i:s', strtotime($e['t']))) ?></span></li>
  <?php endforeach; ?></ul><?php endif; ?>
</section>
