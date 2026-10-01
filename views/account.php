<div class="page-head"><div><h1><?= h(A('Moj račun')) ?></h1><p><?= h(Auth::$user['email']) ?></p></div></div>
<div class="grid g2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('key', 18) ?><?= h(A('Sprememba gesla')) ?></h2></div>
    <div class="panel-body"><form class="form" method="post"><?= Auth::csrf() ?>
      <label class="f"><?= h(A('Trenutno geslo')) ?><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="f"><?= h(A('Novo geslo (vsaj 10 znakov)')) ?><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Spremeni geslo')) ?></button></div>
    </form></div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Dvostopenjska prijava (2FA)')) ?></h2></div>
    <div class="panel-body"><p class="muted" style="margin:0"><?= h(A('Vklop 2FA z aplikacijo (Google Authenticator, Aegis …) bo dodan v zaključni fazi, ko bo vse stestirano.')) ?></p></div>
  </section>
</div>
