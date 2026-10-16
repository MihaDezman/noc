<?php
/** Terminal routerja (SSH s strežnika NOC) – samostojna stran čez celo okno */
require_once __DIR__ . '/_helpers.php'; global $LANG;
$id = (int)$d['id']; $port = Term::sshPort($d);
?><!doctype html>
<html lang="<?= h($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="icon" href="data:,">
<title><?= h($title) ?> · NOC</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/vendor/xterm.css?v=<?= APP_VERSION ?>">
<script<?= nonce() ?>>try{var t=localStorage.getItem('noc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="term-page">
<header class="term-bar">
  <a class="btn sm ghost" href="/devices/<?= $id ?>" title="<?= h(A('Nazaj na napravo')) ?>"><?= icon('chev', 15, 'flip') ?><?= h($d['name']) ?></a>
  <span class="mono small faint"><?= h(Term::USER) ?>@<?= h($d['public_ip']) ?>:<?= $port ?></span>
  <span class="tag" id="term-state"><span class="dot"></span><?= h(A('povezujem …')) ?></span>
  <span class="small faint term-note"><?= icon('scroll', 13) ?><?= h(A('Seja se beleži v revizijsko sled.')) ?></span>
  <button class="btn sm" type="button" id="term-again" hidden><?= icon('refresh', 14) ?><?= h(A('Ponovno poveži')) ?></button>
</header>
<main id="term" class="term-box"></main>
<script src="/assets/vendor/xterm.js?v=<?= APP_VERSION ?>"></script>
<script src="/assets/vendor/xterm-addon-fit.js?v=<?= APP_VERSION ?>"></script>
<script<?= nonce() ?>>
(function () {
  var L = <?= json_encode(['on' => A('povezano'), 'off' => A('povezava zaprta'), 'err' => A('napaka povezave')], JSON_UNESCAPED_UNICODE) ?>;
  var st = document.getElementById('term-state'), again = document.getElementById('term-again');
  function state(cls, txt) { st.className = 'tag ' + cls; st.innerHTML = '<span class="dot ' + cls + '"></span>' + txt; }
  var term = new Terminal({ cursorBlink: true, fontFamily: '"IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace', fontSize: 14, scrollback: 5000,
    theme: { background: '#11131c', foreground: '#e6e8f0', cursor: '#e8b100', selectionBackground: '#8b72ff66' } });
  var fit = new FitAddon.FitAddon(); term.loadAddon(fit);
  term.open(document.getElementById('term')); fit.fit(); term.focus();
  var url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/term/ws?t=<?= h($token) ?>&c=' + term.cols + '&r=' + term.rows;
  var ws = new WebSocket(url); ws.binaryType = 'arraybuffer';
  ws.onopen = function () { state('up', L.on); };
  ws.onmessage = function (e) { term.write(typeof e.data === 'string' ? e.data : new Uint8Array(e.data)); };
  ws.onclose = function () { state('down', L.off); again.hidden = false; term.options.cursorBlink = false; };
  ws.onerror = function () { state('down', L.err); };
  term.onData(function (d) { if (ws.readyState === 1) ws.send(JSON.stringify({ t: 'i', d: d })); });
  function resize() { fit.fit(); if (ws.readyState === 1) ws.send(JSON.stringify({ t: 'r', c: term.cols, r: term.rows })); }
  var rt; function later() { clearTimeout(rt); rt = setTimeout(resize, 120); }
  window.addEventListener('resize', later);
  if ('ResizeObserver' in window) new ResizeObserver(later).observe(document.getElementById('term'));
  term.onResize(function () { if (ws.readyState === 1) ws.send(JSON.stringify({ t: 'r', c: term.cols, r: term.rows })); });
  again.addEventListener('click', function () { location.reload(); });
})();
</script>
</body>
</html>
