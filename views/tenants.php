<div class="page-head"><div><h1><?= h(A('Naročniki')) ?></h1><p><?= h(A('Stranke, pri katerih so naprave. Bralni uporabnik vidi samo naprave svojega naročnika.')) ?></p></div></div>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('building', 18) ?><?= h(A('Seznam')) ?></h2></div>
    <?php if (!$tenants): ?><div class="empty"><?= h(A('Še ni naročnikov.')) ?></div><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th><?= h(A('Naziv')) ?></th><th><?= h(A('Kontakt')) ?></th><th><?= h(A('Poročilo')) ?></th><th class="num"><?= h(A('Naprav')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($tenants as $t): ?><tr>
        <td><b><?= h($t['name']) ?></b><?php if ($t['notes']): ?><div class="small muted"><?= h(mb_strimwidth($t['notes'], 0, 90, '…')) ?></div><?php endif; ?></td>
        <td class="muted"><?= h($t['contact']) ?></td>
        <td><?= !empty($t['report_enabled']) ? '<span class="tag up">' . icon('mail', 12) . h(A('mesečno')) . '</span>' : '<span class="faint">–</span>' ?></td>
        <td class="num"><a href="/devices?t=<?= (int)$t['id'] ?>"><?= (int)$t['n'] ?></a></td>
        <td class="num"><a class="btn sm ghost" href="/tenants?id=<?= (int)$t['id'] ?>"><?= icon('edit', 14) ?></a></td>
      </tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon($edit ? 'edit' : 'plus', 18) ?><?= h($edit ? A('Uredi naročnika') : A('Nov naročnik')) ?></h2></div>
    <div class="panel-body">
      <form class="form" method="post" action="/tenants/save"><?= Auth::csrf() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <label class="f"><?= h(A('Naziv')) ?><input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>"></label>
        <label class="f"><?= h(A('Kontakt')) ?><input type="text" name="contact" value="<?= h($edit['contact'] ?? '') ?>" placeholder="<?= h(A('ime, telefon, e-pošta')) ?>"></label>
        <label class="f"><?= h(A('Opombe')) ?><textarea name="notes"><?= h($edit['notes'] ?? '') ?></textarea></label>
        <label class="f"><?= h(A('E-naslovi za mesečno poročilo')) ?><input type="text" name="report_emails" value="<?= h($edit['report_emails'] ?? '') ?>" placeholder="info@stranka.si, it@stranka.si"></label>
        <label class="check"><input type="checkbox" name="report_enabled" value="1" <?= !empty($edit['report_enabled']) ? 'checked' : '' ?>> <?= h(A('Samodejno pošlji poročilo za prejšnji mesec 1. v mesecu')) ?></label>
        <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani')) ?></button><?php if ($edit): ?><a class="btn ghost" href="/tenants"><?= h(A('Prekliči')) ?></a><?php endif; ?></div>
      </form>
      <?php if ($edit): ?><form method="post" action="/tenants/delete" style="margin-top:16px" data-confirm="<?= h(A('Izbrišem naročnika?')) ?>"><?= Auth::csrf() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn sm danger"><?= icon('trash', 14) ?><?= h(A('Izbriši')) ?></button></form><?php endif; ?>
    </div>
  </section>
</div>
