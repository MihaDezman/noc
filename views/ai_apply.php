<?php
/** AI popravki: predogled pred uveljavitvijo ali stanje uveljavitve */
$id = (int)$d['id'];
$lvTag = fn(string $lv) => $lv === 'block' ? 'down' : ($lv === 'warn' ? 'warn' : 'up');
?>
<div class="page-head">
  <div><h1><?= h(A('AI popravki')) ?></h1><p><a href="/devices/<?= $id ?>?tab=protect#ai-review"><?= h($d['name']) ?></a> · <?= h(A('uveljavitev v Safe Mode prek SSH')) ?></p></div>
</div>

<?php if (isset($ap)): $stl = ['queued' => [A('čaka'), 'info'], 'running' => [A('poteka'), 'info'], 'done' => [A('uveljavljeno'), 'up'], 'reverted' => [A('razveljavljeno'), 'warn'], 'failed' => [A('ni uspelo'), 'down']][$ap['status']]; ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('zap', 18) ?><?= h(A('Uveljavitev #{n}', ['n' => (int)$ap['id']])) ?></h2><span class="tag <?= $stl[1] ?>"><?= h($stl[0]) ?></span></div>
  <div class="panel-body">
    <?php if (in_array($ap['status'], ['queued', 'running'], true)): ?><p style="margin-top:0"><?= icon('clock', 14) ?> <?= h(A('NOC se povezuje z routerjem in izvaja ukaze v Safe Mode. Stran se osveži sama.')) ?></p><?php endif; ?>
    <?php if ($ap['status'] === 'done'): ?><p style="margin-top:0"><?= h(A('Vsi ukazi so uspeli, router je po spremembah še dosegel NOC, Safe Mode je potrjen. Ugotovitve so označene kot urejene, router dela nov backup.')) ?></p><?php endif; ?>
    <?php if ($ap['status'] === 'reverted'): ?><p style="margin-top:0"><?= h(A('Router je vse spremembe te uveljavitve razveljavil (Safe Mode) – konfiguracija je enaka kot prej.')) ?></p><?php endif; ?>
    <?php if ($ap['note'] !== ''): ?><p class="small"><b><?= h($ap['note']) ?></b></p><?php endif; ?>
    <dl class="kv small"><dt><?= h(A('Začetek')) ?></dt><dd><?= h(fmt_dt($ap['started_at'] ?: $ap['created_at'])) ?></dd><?php if ($ap['ended_at']): ?><dt><?= h(A('Konec')) ?></dt><dd><?= h(fmt_dt($ap['ended_at'])) ?></dd><?php endif; ?></dl>
  </div>
  <div class="panel-body" style="border-top:1px solid var(--line-2)"><div class="small muted" style="margin-bottom:6px"><?= h(A('Skripta')) ?></div><pre class="raw small" style="margin:0"><?= h($ap['script']) ?></pre></div>
  <?php if (trim((string)$ap['log']) !== ''): ?><details><summary class="panel-body" style="cursor:pointer;padding:12px 20px"><?= h(A('Izpis seje z routerja')) ?></summary><pre class="raw small" style="border-top:1px solid var(--line-2)"><?= h((string)$ap['log']) ?></pre></details><?php endif; ?>
  <div class="panel-body actions" style="border-top:1px solid var(--line-2)"><a class="btn sm" href="/devices/<?= $id ?>?tab=protect#ai-review"><?= icon('chev', 14, 'flip') ?><?= h(A('Nazaj na AI pregled')) ?></a></div>
</section>

<?php else: $hasBlock = false; $hasWarn = false; foreach ($plan as [, , $rows]) foreach ($rows as [, $lv]) { $hasBlock = $hasBlock || $lv === 'block'; $hasWarn = $hasWarn || $lv === 'warn'; } ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('eye', 18) ?><?= h(A('Predogled')) ?></h2><span class="small muted"><?= h(A('ugotovitev: {n}', ['n' => count($plan)])) ?></span></div>
  <div class="panel-body small muted" style="padding-bottom:0"><?= h(A('NOC se z routerjem poveže prek SSH, vklopi Safe Mode in izvede ukaze enega za drugim. Ob prvi napaki, ali če router po spremembah ne doseže več NOC, se seja prekine in router SAM razveljavi vse spremembe. Šele ko je vse v redu, NOC Safe Mode potrdi.')) ?></div>
  <?php foreach ($plan as [$k, $title, $rows]): ?>
  <div class="panel-body" style="border-top:1px solid var(--line-2)">
    <b><?= h($title) ?></b>
    <div class="apply-lines">
    <?php foreach ($rows as [$l, $lv, $why]): ?><div class="apply-line"><span class="tag <?= $lvTag($lv) ?>"><?= h(['ok' => A('v redu'), 'warn' => A('tvegano'), 'block' => A('ne izvedem')][$lv]) ?></span><code><?= h($l) ?></code><?php if ($why !== ''): ?><span class="small muted"><?= h($why) ?></span><?php endif; ?></div><?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$plan): ?><div class="empty small"><?= h(A('Izbrane ugotovitve nimajo ukazov za popravek.')) ?></div><?php endif; ?>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/ai-apply" style="border-top:1px solid var(--line-2)"><?= Auth::csrf() ?>
    <input type="hidden" name="rid" value="<?= (int)$rv['id'] ?>"><?php foreach ($keys as $k): ?><input type="hidden" name="k[]" value="<?= (int)$k ?>"><?php endforeach; ?>
    <?php if ($blocker !== ''): ?><p class="small" style="margin:0;color:var(--down)"><?= icon('alert', 14) ?> <?= h($blocker) ?></p>
    <?php elseif ($hasBlock): ?><p class="small" style="margin:0;color:var(--down)"><?= icon('alert', 14) ?> <?= h(A('Nekateri ukazi so takšni, da jih NOC ne izvede samodejno. Odkljukaj te ugotovitve ali jih uredi ročno v terminalu.')) ?></p>
    <?php else: ?>
      <?php if ($hasWarn): ?><label class="check"><input type="checkbox" name="warn_ok" value="1" required> <span><?= h(A('Pregledal sem tvegane ukaze (rumeno) in jih želim izvesti.')) ?></span></label><?php endif; ?>
      <div class="actions"><button class="btn primary"><?= icon('zap', 15) ?><?= h(A('Uveljavi v Safe Mode')) ?></button><a class="btn" href="/devices/<?= $id ?>?tab=protect#ai-review"><?= h(A('Prekliči')) ?></a></div>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>
