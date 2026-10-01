<div class="page-head">
  <div><div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?><a href="/devices/<?= (int)$d['id'] ?>"><?= h($d['name']) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h(A('Namestitev na router')) ?></h1><p><?= h($d['model'] ?: 'MikroTik') ?> · RouterOS <?= h($d['os_version'] ?: '?') ?></p></div>
  <div class="actions">
    <a class="btn primary" href="/devices/<?= (int)$d['id'] ?>/package"><?= icon('download', 16) ?><?= h(A('Prenesi paket (.zip)')) ?></a>
    <form method="post" action="/devices/<?= (int)$d['id'] ?>/key" data-confirm="<?= h(A('Ustvarim nov API ključ? Stari bo takoj prenehal delovati.')) ?>"><?= Auth::csrf() ?><button class="btn"><?= icon('key', 16) ?><?= h(A('Nov ključ')) ?></button></form>
  </div>
</div>

<?php if ($d['last_seen_at']): ?><div class="note" style="margin-bottom:20px"><?= icon('check', 18) ?><span><?= h(A('Naprava že pošilja podatke (zadnjič {t}). Paket prenesi znova samo, če si spremenil nastavitve.', ['t' => fmt_ago($d['last_seen_at'])])) ?></span></div><?php endif; ?>
<?php foreach ($an['warnings'] ?? [] as $wn): ?><div class="note warn" style="margin-bottom:10px"><?= icon('alert', 18) ?><span><?= h(A($wn)) ?></span></div><?php endforeach; ?>

<div class="grid g-main" style="margin-top:10px">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('list', 18) ?><?= h(A('Postopek')) ?></h2></div>
    <div class="panel-body">
      <ol class="steps">
        <li><div><b><?= h(A('Device-mode')) ?></b><p><?= h(A('Scheduler in fetch morata biti dovoljena. Preveri:')) ?></p>
          <div class="cmd"><code>/system/device-mode/print</code><button type="button" data-copy="/system/device-mode/print"><?= icon('copy', 14) ?></button></div>
          <p><?= h(A('Če nista "yes", poženi spodnji ukaz in ga v 5 minutah potrdi z reset gumbom ali izklopom napajanja:')) ?></p>
          <div class="cmd"><code>/system/device-mode/update scheduler=yes fetch=yes</code><button type="button" data-copy="/system/device-mode/update scheduler=yes fetch=yes"><?= icon('copy', 14) ?></button></div></div></li>
        <li><div><b><?= h(A('Naloži noc-install.rsc')) ?></b><p><?= h(A('Razširi zip in datoteko povleci v Winbox → Files.')) ?></p></div></li>
        <li><div><b><?= h(A('Uvozi')) ?></b>
          <div class="cmd"><code>/import noc-install.rsc</code><button type="button" data-copy="/import noc-install.rsc"><?= icon('copy', 14) ?></button></div>
          <p><?= h(A('Preveri log:')) ?></p><div class="cmd"><code>/log print where message~"noc"</code><button type="button" data-copy='/log print where message~"noc"'><?= icon('copy', 14) ?></button></div></div></li>
        <li><div><b><?= h(A('Počakaj minuto')) ?></b><p><?= h(A('Naprava se v NOC obarva zeleno. Prvi backup konfiguracije sprožiš takoj z:')) ?></p>
          <div class="cmd"><code>/system script run noc-backup</code><button type="button" data-copy="/system script run noc-backup"><?= icon('copy', 14) ?></button></div></div></li>
      </ol>
    </div>
  </section>
  <aside>
    <section class="panel">
      <div class="panel-head"><h2><?= icon('box', 18) ?><?= h(A('Vsebina paketa')) ?></h2></div>
      <div class="panel-body"><dl class="kv">
        <dt class="mono">noc-install.rsc</dt><dd><?= h(A('vse v enem')) ?></dd>
        <dt class="mono">noc-push.rsc</dt><dd><?= h(A('za ročno lepljenje')) ?></dd>
        <dt class="mono">noc-backup.rsc</dt><dd><?= h(A('za ročno lepljenje')) ?></dd>
        <dt class="mono">noc-remove.rsc</dt><dd><?= h(A('odstranitev')) ?></dd>
        <dt class="mono">NAVODILA.txt</dt><dd><?= h(A('ta postopek')) ?></dd>
      </dl></div>
    </section>
    <section class="panel" style="margin-top:20px">
      <div class="panel-head"><h2><?= icon('activity', 18) ?><?= h(A('Kaj bo router pošiljal')) ?></h2></div>
      <div class="panel-body"><dl class="kv">
        <dt><?= h(A('Vsako minuto')) ?></dt><dd><?= h(A('stanje, vmesniki, ping, logi')) ?></dd>
        <dt><?= h(A('Vsakih 5 min')) ?></dt><dd><?= h(A('DHCP najemi')) ?></dd>
        <dt><?= h(A('Enkrat dnevno')) ?></dt><dd><?= h(A('/export, posodobitve')) ?></dd>
        <dt><?= h(A('Neprekinjeno')) ?></dt><dd><?= (int)$d['flow_enabled'] ? h(A('traffic-flow na UDP {p}', ['p' => cfg('flow_port', 2055)])) : h(A('traffic-flow izklopljen')) ?></dd>
        <dt><?= h(A('Ping s strežnika')) ?></dt><dd class="mono"><?= h($d['public_ip'] ?: '–') ?></dd>
      </dl></div>
    </section>
  </aside>
</div>
