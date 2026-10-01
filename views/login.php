<?php require_once __DIR__ . '/_helpers.php'; global $LANG; ?><!doctype html>
<html lang="<?= h($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>NOC – Network Operations Center</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= APP_VERSION ?>">
<script>try{var t=localStorage.getItem('noc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body>
<div class="login">
  <section class="login-art">
    <div class="brand"><span class="brand-mark"><?= brand_mark(28) ?></span><span><b>NOC – Network Operations Center</b><span>dezman.net</span></span></div>
    <div class="login-visual"><?= racks_svg() ?></div>
    <div class="login-copy">
      <h1><?= h(A('Nadzor MikroTik omrežij v realnem času.')) ?></h1>
      <p class="lead"><?= h(A('Stanje, promet in alarmi vseh routerjev – vsako minuto.')) ?></p>
      <p class="tech"><span><?= icon('activity', 15) ?><?= h(A('Push telemetrija')) ?></span><span><?= icon('waypoints', 15) ?>Traffic-flow</span><span><?= icon('archive', 15) ?><?= h(A('Nočni backup konfiguracij')) ?></span></p>
    </div>
  </section>
  <section class="login-form">
    <?php if (!empty($step2)): ?>
    <form method="post" action="/login/2fa">
      <?= Auth::csrf() ?>
      <h2><?= h(A('Dvostopenjska prijava')) ?></h2>
      <p class="muted" style="margin:0"><?= h(A('Vpiši 6-mestno kodo iz aplikacije za preverjanje (Google Authenticator, Aegis …).')) ?></p>
      <?php if ($error): ?><div class="flash err"><?= icon('alert', 18) ?><span><?= h($error) ?></span></div><?php endif; ?>
      <label class="f"><?= h(A('Koda')) ?><input type="text" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="mono" style="font-size:1.4rem;letter-spacing:.3em;text-align:center"></label>
      <button class="btn primary" style="justify-content:center"><?= icon('shield', 16) ?><?= h(A('Potrdi')) ?></button>
      <a class="small" href="/login"><?= h(A('Nazaj na prijavo')) ?></a>
    </form>
    <?php else: ?>
    <form method="post" action="/login">
      <?= Auth::csrf() ?>
      <h2><?= h(A('Prijava')) ?></h2>
      <?php if ($error): ?><div class="flash err"><?= icon('alert', 18) ?><span><?= h($error) ?></span></div><?php endif; ?>
      <label class="f"><?= h(A('Uporabnik')) ?><input type="text" name="email" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= h($_POST['email'] ?? '') ?>"></label>
      <label class="f"><?= h(A('Geslo')) ?><input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn primary" style="justify-content:center"><?= icon('lock', 16) ?><?= h(A('Prijava')) ?></button>
      <p class="small faint">Računalniške storitve Miha Dežman s.p.</p>
    </form>
    <?php endif; ?>
  </section>
</div>
</body>
</html>
