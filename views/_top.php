<?php
require_once __DIR__ . '/_helpers.php';
global $LANG;
$u = Auth::$user; $isSuper = Auth::isSuper();
$nav ??= ''; $title ??= 'NOC';
[$w, $p] = Auth::deviceScope('d');
$st = db()->prepare("SELECT SUM(a.severity='critical') c, SUM(a.severity='warning') w FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.ended_at IS NULL AND a.acked_at IS NULL AND $w");
$st->execute($p); $ac = $st->fetch();
$navItem = function (string $key, string $href, string $ico, string $label, string $extra = '') use ($nav) {
    return '<a href="' . $href . '" class="' . ($nav === $key ? 'on' : '') . '"' . ($nav === $key ? ' aria-current="page"' : '') . '>' . icon($ico, 18) . '<span>' . h($label) . '</span>' . $extra . '</a>';
};
$initials = mb_strtoupper(implode('', array_map(fn($x) => mb_substr($x, 0, 1), array_slice(preg_split('/\s+/', trim($u['name'] ?: $u['email'])), 0, 2))));
$flash = Auth::flash();
?><!doctype html>
<html lang="<?= h($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · NOC – Network Operations Center</title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="#1c1f2e"/><circle cx="12" cy="12" r="3" fill="#e8b100"/><path d="M12 5a7 7 0 0 1 7 7" stroke="#8b72ff" stroke-width="2.4" fill="none" stroke-linecap="round"/><path d="M12 19a7 7 0 0 1-7-7" stroke="#19c0d8" stroke-width="2.4" fill="none" stroke-linecap="round"/></svg>') ?>">
<link rel="stylesheet" href="/assets/vendor/uPlot.min.css">
<link rel="stylesheet" href="/assets/app.css?v=<?= APP_VERSION ?>">
<script>try{var t=localStorage.getItem('noc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body<?= !empty($autorefresh) ? ' data-autorefresh="' . (int)$autorefresh . '"' : '' ?>>
<div class="shell">
<aside class="side" id="side">
  <a class="brand" href="/"><span class="brand-mark"><?= brand_mark(24) ?></span><span><b>NOC</b><span>Network Operations Center</span></span></a>
  <nav class="nav" aria-label="<?= h(A('Glavni meni')) ?>">
    <div class="nav-group">
      <?= $navItem('dash', '/', 'dashboard', A('Pregled')) ?>
      <?= $navItem('devices', '/devices', 'router', A('Naprave')) ?>
      <?= $navItem('alerts', '/alerts', 'bell', A('Alarmi'), ($ac['c'] ?? 0) > 0 ? '<span class="count">' . (int)$ac['c'] . '</span>' : (($ac['w'] ?? 0) > 0 ? '<span class="count warn">' . (int)$ac['w'] . '</span>' : '')) ?>
      <?= $navItem('logs', '/logs', 'scroll', A('Logi')) ?>
      <?= $navItem('reports', '/reports', 'file', A('Poročila')) ?>
      <?php if ($isSuper): ?><?= $navItem('backups', '/backups', 'archive', A('Konfiguracije')) ?><?= $navItem('updates', '/updates', 'refresh', A('Posodobitve')) ?><?php endif; ?>
    </div>
    <?php if ($isSuper): ?>
    <div class="nav-group"><span><?= h(A('Upravljanje')) ?></span>
      <?= $navItem('tenants', '/tenants', 'building', A('Naročniki')) ?>
      <?= $navItem('users', '/users', 'users', A('Uporabniki')) ?>
      <?= $navItem('settings', '/settings', 'sliders', A('Alarmi in obvestila')) ?>
      <?= $navItem('system', '/system', 'server', A('Sistem')) ?>
    </div>
    <?php endif; ?>
  </nav>
  <div class="side-foot">
    <span>v<?= APP_VERSION ?> · NOC</span>
    <form method="post" action="/lang"><?= Auth::csrf() ?><input type="hidden" name="lang" value="<?= $LANG === 'sl' ? 'en' : 'sl' ?>"><button title="<?= h(A('Jezik')) ?>"><?= icon('lang', 16) ?>&nbsp;<?= $LANG === 'sl' ? 'EN' : 'SL' ?></button></form>
  </div>
</aside>
<div class="main">
  <header class="top">
    <button class="icon-btn burger" type="button" data-toggle-nav aria-label="<?= h(A('Meni')) ?>"><?= icon('menu', 20) ?></button>
    <form class="search" action="/devices" role="search"><?= icon('search', 16) ?><input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h(A('Išči napravo, IP, naročnika …')) ?>" aria-label="<?= h(A('Iskanje')) ?>"></form>
    <div class="top-right">
      <button class="icon-btn" type="button" data-theme-toggle title="<?= h(A('Svetla / temna tema')) ?>"><?= icon('moon', 18) ?></button>
      <a class="user-chip" href="/account"><span class="avatar"><?= h($initials) ?></span><span><?= h($u['name'] ?: $u['email']) ?></span></a>
      <form method="post" action="/logout" style="margin:0"><?= Auth::csrf() ?><button class="icon-btn" title="<?= h(A('Odjava')) ?>" aria-label="<?= h(A('Odjava')) ?>"><?= icon('logout', 18) ?></button></form>
    </div>
  </header>
  <main class="content">
<?php if ($flash): ?><div class="flash <?= h($flash[0]) ?>"><?= icon($flash[0] === 'ok' ? 'check' : 'alert', 18) ?><span><?= h($flash[1]) ?></span></div><?php endif; ?>
