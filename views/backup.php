<div class="page-head">
  <div><div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?><a href="/devices/<?= (int)$d['id'] ?>?tab=backups"><?= h($d['name']) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h(A('Konfiguracija {t}', ['t' => date('d.m.Y H:i', strtotime($b['created_at']))])) ?></h1>
  <p><?= $prev ? h(A('Primerjava s stanjem {t}', ['t' => date('d.m.Y H:i', strtotime($prev['created_at']))])) : h(A('Prvi shranjeni backup')) ?></p></div>
  <div class="actions">
    <?php if ($prev): ?><div class="seg"><a href="?" class="<?= !$raw ? 'on' : '' ?>"><?= h(A('Spremembe')) ?></a><a href="?raw=1" class="<?= $raw ? 'on' : '' ?>"><?= h(A('Celoten izpis')) ?></a></div><?php endif; ?>
    <a class="btn" href="/devices/<?= (int)$d['id'] ?>/backups/<?= (int)$b['id'] ?>/download"><?= icon('download', 16) ?><?= h(A('Prenesi .rsc')) ?></a>
  </div>
</div>
<section class="panel">
<?php if ($prev && !$raw): ?>
  <div class="panel-head"><h2><?= icon('diff', 18) ?><span class="tag up">+<?= (int)$b['added'] ?></span> <span class="tag down">−<?= (int)$b['removed'] ?></span></h2></div>
  <div class="diff"><?php foreach ($hunks as $o):
    if ($o[0] === '…') { echo '<div class="gap">' . h(A('… {n} nespremenjenih vrstic', ['n' => $o[1]])) . '</div>'; continue; }
    $cls = $o[0] === '+' ? 'add' : ($o[0] === '-' ? 'rem' : '');
    echo '<div class="' . $cls . '">' . h($o[0] . ' ' . $o[1]) . '</div>';
  endforeach; ?></div>
<?php else: ?>
  <pre class="raw"><?= h($b['content']) ?></pre>
<?php endif; ?>
</section>
