<div class="page-head"><div><h1><?= h(A('Konfiguracije')) ?></h1><p><?= h(A('Nočni /export vseh naprav (brez gesel) in zadnje spremembe.')) ?></p></div></div>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('archive', 18) ?><?= h(A('Naprave')) ?></h2></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th><?= h(A('Naprava')) ?></th><th><?= h(A('Zadnji backup')) ?></th><th class="num"><?= h(A('Različic')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): $old = !$r['last_at'] || strtotime($r['last_at']) < time() - 2 * 86400; ?><tr>
        <td><a class="rowlink" href="/devices/<?= (int)$r['id'] ?>?tab=backups"><?= h($r['name']) ?></a><div class="small muted"><?= h($r['tenant_name'] ?? '–') ?></div></td>
        <td><?= $r['last_at'] ? h(fmt_dt($r['last_at'])) : '' ?><?= $old ? ' <span class="tag warn">' . h($r['last_at'] ? A('star') : A('ni backupa')) . '</span>' : '' ?></td>
        <td class="num"><?= (int)$r['n'] ?></td>
        <td class="num"><?php if ($r['last_id']): ?><a class="btn sm" href="/devices/<?= (int)$r['id'] ?>/backups/<?= (int)$r['last_id'] ?>"><?= icon('eye', 14) ?><?= h(A('Odpri')) ?></a><?php endif; ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('diff', 18) ?><?= h(A('Zadnje spremembe')) ?></h2></div>
    <?php if (!$changes): ?><div class="empty"><?= h(A('Še ni zabeleženih sprememb.')) ?></div><?php else: ?>
    <ul class="feed"><?php foreach ($changes as $c): ?><li><span class="sev-bar info"></span><div class="what"><b><a href="/devices/<?= (int)$c['device_id'] ?>/backups/<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></b><div><span class="tag up">+<?= (int)$c['added'] ?></span> <span class="tag down">−<?= (int)$c['removed'] ?></span></div></div><span class="when"><?= h(fmt_dt($c['created_at'])) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
