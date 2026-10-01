<div class="page-head">
  <div><div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?><a href="/devices/new"><?= h(A('Dodaj napravo')) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h(A('Dodaj z ukazom na routerju')) ?></h1><p><?= h(A('Koda velja 30 minut in jo je mogoče uporabiti samo enkrat.')) ?></p></div>
</div>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('list', 18) ?><?= h(A('Postopek')) ?></h2></div>
    <div class="panel-body">
      <ol class="steps">
        <li><div><b><?= h(A('Device-mode')) ?></b><p><?= h(A('Fetch mora biti dovoljen. Preveri:')) ?></p>
          <div class="cmd"><code>/system/device-mode/print</code><button type="button" data-copy="/system/device-mode/print"><?= icon('copy', 14) ?></button></div></div></li>
        <li><div><b><?= h(A('Prilepi ukaz v terminal routerja')) ?></b>
          <div class="cmd"><code><?= h($cmd) ?></code><button type="button" data-copy="<?= h($cmd) ?>" title="<?= h(A('Kopiraj')) ?>"><?= icon('copy', 14) ?></button></div>
          <p><?= h(A('Router izvozi konfiguracijo (brez gesel) in jo pošlje v NOC. Na routerju se ne spremeni nič.')) ?></p></div></li>
        <li><div><b><?= h(A('Potrdi predlog')) ?></b><p><?= h(A('Ta stran se samodejno nadaljuje, ko konfiguracija pride.')) ?></p></div></li>
      </ol>
    </div>
  </section>
  <aside class="panel">
    <div class="panel-head"><h2><?= icon('refresh', 18) ?><?= h(A('Stanje')) ?></h2></div>
    <div class="panel-body enroll-wait" id="enroll" data-id="<?= (int)$eid ?>">
      <div class="pulse"></div>
      <div id="enroll-msg"><b><?= h(A('Čakam na router …')) ?></b><div class="small muted"><?= h(A('Prilepi ukaz na routerju.')) ?></div></div>
    </div>
  </aside>
</div>
<script>
(function () {
  var box = document.getElementById('enroll'), msg = document.getElementById('enroll-msg'), id = box.dataset.id;
  var T = <?= json_encode(['recv' => A('Prejemam konfiguracijo …'), 'exp' => A('Koda je potekla – ustvari novo.'), 'from' => A('z routerja')], JSON_UNESCAPED_UNICODE) ?>;
  function poll() {
    fetch('/devices/enroll-status?id=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.status === 'received') { location.href = '/devices/new?enroll=' + id; return; }
      if (j.status === 'receiving') msg.innerHTML = '<b>' + T.recv + '</b><div class="small muted">' + T.from + ' ' + j.ip + '</div>';
      if (j.expired) { box.classList.add('expired'); msg.innerHTML = '<b>' + T.exp + '</b>'; return; }
      setTimeout(poll, 2500);
    }).catch(function () { setTimeout(poll, 5000); });
  }
  poll();
})();
</script>
