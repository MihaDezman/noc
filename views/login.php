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
    <form method="post" action="/login">
      <?= Auth::csrf() ?>
      <h2><?= h(A('Prijava')) ?></h2>
      <?php if ($error): ?><div class="flash err"><?= icon('alert', 18) ?><span><?= h($error) ?></span></div><?php endif; ?>
      <label class="f"><?= h(A('E-pošta')) ?><input type="email" name="email" required autofocus autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>"></label>
      <label class="f"><?= h(A('Geslo')) ?><input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn primary" style="justify-content:center"><?= icon('lock', 16) ?><?= h(A('Prijava')) ?></button>
      <p class="small faint">Računalniške storitve Miha Dežman s.p.</p>
    </form>
  </section>
</div>
</body>
</html>
