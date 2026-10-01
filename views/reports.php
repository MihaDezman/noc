<?php $cur = date('Y-m', strtotime('first day of last month')); $isSuper = Auth::isSuper(); ?>
<div class="page-head"><div><h1><?= h(A('Poročila')) ?></h1><p><?= h(A('Mesečno poročilo o omrežju za naročnika: razpoložljivost, promet, latenca, dogodki in največji porabniki.')) ?></p></div></div>

<section class="panel">
  <div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('Odpri poročilo')) ?></h2></div>
  <div class="panel-body">
    <form class="filters" method="get" action="/reports/view" target="_blank">
      <select name="t" required><?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?> (<?= (int)$t['n'] ?>)</option><?php endforeach; ?></select>
      <select name="m"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $cur ? 'selected' : '' ?>><?= h(Report::monthName($m)) ?><?= $m === date('Y-m') ? ' (' . h(A('tekoči')) . ')' : '' ?></option><?php endforeach; ?></select>
      <button class="btn primary"><?= icon('eye', 16) ?><?= h(A('Odpri')) ?></button>
    </form>
    <p class="small muted" style="margin:12px 0 0"><?= h(A('Poročilo se odpre v novem zavihku. PDF narediš z gumbom "Natisni / shrani PDF" (Shrani kot PDF v brskalniku).')) ?></p>
  </div>
</section>

<?php if ($isSuper): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('mail', 18) ?><?= h(A('Samodejno pošiljanje')) ?></h2><a class="small" href="/tenants"><?= h(A('Nastavi pri naročniku')) ?></a></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Naročnik')) ?></th><th><?= h(A('Pošiljanje 1. v mesecu')) ?></th><th><?= h(A('Prejemniki')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($tenants as $t): ?><tr>
      <td><b><?= h($t['name']) ?></b></td>
      <td><?= !empty($t['report_enabled']) ? '<span class="tag up">' . h(A('vklopljeno')) . '</span>' : '<span class="tag">' . h(A('izklopljeno')) . '</span>' ?></td>
      <td class="small muted"><?= h($t['report_emails'] ?: '–') ?></td>
      <td class="num"><div class="actions" style="justify-content:flex-end">
        <form method="post" action="/reports/send"><?= Auth::csrf() ?><input type="hidden" name="t" value="<?= (int)$t['id'] ?>"><input type="hidden" name="m" value="<?= $cur ?>"><input type="hidden" name="to" value="me"><button class="btn sm" title="<?= h(A('Poročilo za {m} pošlji samo sebi za pregled', ['m' => Report::monthName($cur)])) ?>"><?= icon('send', 14) ?><?= h(A('Meni')) ?></button></form>
        <?php if ($t['report_emails']): ?><form method="post" action="/reports/send" data-confirm="<?= h(A('Pošljem poročilo za {m} naročniku?', ['m' => Report::monthName($cur)])) ?>"><?= Auth::csrf() ?><input type="hidden" name="t" value="<?= (int)$t['id'] ?>"><input type="hidden" name="m" value="<?= $cur ?>"><button class="btn sm"><?= icon('mail', 14) ?><?= h(A('Naročniku')) ?></button></form><?php endif; ?>
      </div></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
