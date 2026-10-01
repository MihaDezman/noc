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
  <section class="panel" id="tfa">
    <div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Dvostopenjska prijava (2FA)')) ?></h2><?= !empty(Auth::$user['totp_secret']) ? '<span class="tag up">' . h(A('vklopljena')) . '</span>' : '<span class="tag warn">' . h(A('izklopljena')) . '</span>' ?></div>
    <div class="panel-body">
    <?php if (!empty(Auth::$user['totp_secret'])): ?>
      <p class="muted" style="margin-top:0"><?= h(A('Ob prijavi poleg gesla vpišeš še kodo iz aplikacije. Za izklop potrdi z geslom in trenutno kodo.')) ?></p>
      <form class="form" method="post" action="/account/2fa"><?= Auth::csrf() ?><input type="hidden" name="act" value="off">
        <div class="row"><label class="f"><?= h(A('Geslo')) ?><input type="password" name="current" required autocomplete="current-password"></label>
        <label class="f"><?= h(A('Koda')) ?><input type="text" name="code" required inputmode="numeric" maxlength="7" class="mono"></label></div>
        <div class="actions"><button class="btn danger"><?= h(A('Izklopi 2FA')) ?></button></div>
      </form>
    <?php elseif ($newSecret): $uri = Totp::uri($newSecret, Auth::$user['email']); ?>
      <ol class="steps">
        <li><div><b><?= h(A('Skeniraj kodo z aplikacijo')) ?></b><p><?= h(A('Google Authenticator, Aegis, 2FAS ali Microsoft Authenticator.')) ?></p>
          <div id="qr" data-uri="<?= h($uri) ?>" style="background:#fff;padding:12px;border-radius:10px;width:max-content;margin-top:10px"></div>
          <p class="small"><?= h(A('Ali vpiši ključ ročno:')) ?> <code><?= h(implode(' ', str_split($newSecret, 4))) ?></code></p></div></li>
        <li><div><b><?= h(A('Vpiši kodo, ki jo pokaže aplikacija')) ?></b>
          <form class="actions" method="post" action="/account/2fa" style="margin-top:8px"><?= Auth::csrf() ?><input type="hidden" name="act" value="confirm">
            <input type="text" name="code" required inputmode="numeric" maxlength="7" class="mono" style="width:140px;font-size:1.2rem;letter-spacing:.2em;text-align:center" autocomplete="one-time-code">
            <button class="btn primary"><?= icon('check', 16) ?><?= h(A('Vklopi')) ?></button></form>
          <form method="post" action="/account/2fa" style="margin-top:8px"><?= Auth::csrf() ?><input type="hidden" name="act" value="cancel"><button class="btn sm ghost"><?= h(A('Prekliči')) ?></button></form></div></li>
      </ol>
      <script src="/assets/vendor/qrcode.js"></script>
      <script>(function(){var el=document.getElementById('qr');var q=qrcode(0,'M');q.addData(el.dataset.uri);q.make();el.innerHTML=q.createSvgTag({cellSize:5,margin:0});})();</script>
    <?php else: ?>
      <p class="muted" style="margin-top:0"><?= h(A('Poleg gesla ob prijavi zahteva še 6-mestno kodo iz aplikacije na telefonu. Tudi če kdo izve geslo, se brez telefona ne more prijaviti.')) ?></p>
      <form method="post" action="/account/2fa"><?= Auth::csrf() ?><input type="hidden" name="act" value="start"><button class="btn primary"><?= icon('shield', 16) ?><?= h(A('Vklopi 2FA')) ?></button></form>
    <?php endif; ?>
    </div>
  </section>
</div>
