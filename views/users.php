<div class="page-head"><div><h1><?= h(A('Uporabniki')) ?></h1><p><?= h(A('Superadmin vidi in ureja vse. Bralni uporabnik vidi samo naprave svojega naročnika – brez konfiguracij, ključev in nastavitev.')) ?></p></div></div>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('users', 18) ?><?= h(A('Seznam')) ?></h2></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th><?= h(A('Uporabnik')) ?></th><th><?= h(A('Vloga')) ?></th><th><?= h(A('Naročnik')) ?></th><th><?= h(A('Zadnja prijava')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($users as $x): ?><tr>
        <td><b><?= h($x['name'] ?: '–') ?></b><div class="small muted"><?= h($x['email']) ?></div></td>
        <td><?= $x['role'] === 'superadmin' ? '<span class="tag violet">superadmin</span>' : '<span class="tag">' . h(A('bralni')) . '</span>' ?><?= !$x['active'] ? ' <span class="tag down">' . h(A('onemogočen')) . '</span>' : '' ?></td>
        <td class="muted"><?= h($x['tenant_name'] ?? '–') ?></td>
        <td class="muted nowrap"><?= h(fmt_ago($x['last_login'])) ?></td>
        <td class="num"><a class="btn sm ghost" href="/users?id=<?= (int)$x['id'] ?>"><?= icon('edit', 14) ?></a></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon($edit ? 'edit' : 'plus', 18) ?><?= h($edit ? A('Uredi uporabnika') : A('Nov uporabnik')) ?></h2></div>
    <div class="panel-body">
      <form class="form" method="post" action="/users/save"><?= Auth::csrf() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <label class="f"><?= h(A('Ime')) ?><input type="text" name="name" value="<?= h($edit['name'] ?? '') ?>"></label>
        <label class="f"><?= h(A('E-pošta')) ?><input type="email" name="email" required value="<?= h($edit['email'] ?? '') ?>"></label>
        <label class="f"><?= h($edit ? A('Novo geslo (prazno = ostane)') : A('Geslo (vsaj 10 znakov)')) ?><input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required minlength="10"' ?>></label>
        <div class="row">
          <label class="f"><?= h(A('Vloga')) ?><select name="role"><option value="viewer"><?= h(A('Bralni')) ?></option><option value="superadmin" <?= ($edit['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>Superadmin</option></select></label>
          <label class="f"><?= h(A('Jezik')) ?><select name="lang"><option value="sl">Slovenščina</option><option value="en" <?= ($edit['lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option></select></label>
        </div>
        <label class="f"><?= h(A('Naročnik (za bralnega)')) ?><select name="tenant_id"><option value="">–</option><?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($edit['tenant_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= !$edit || $edit['active'] ? 'checked' : '' ?>> <?= h(A('Aktiven')) ?></label>
        <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani')) ?></button><?php if ($edit): ?><a class="btn ghost" href="/users"><?= h(A('Prekliči')) ?></a><?php endif; ?></div>
      </form>
      <?php if ($edit && (int)$edit['id'] !== (int)Auth::$user['id']): ?><form method="post" action="/users/delete" style="margin-top:16px" data-confirm="<?= h(A('Izbrišem uporabnika?')) ?>"><?= Auth::csrf() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn sm danger"><?= icon('trash', 14) ?><?= h(A('Izbriši')) ?></button></form><?php endif; ?>
    </div>
  </section>
</div>
