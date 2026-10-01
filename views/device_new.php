<div class="page-head">
  <div><div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?></div><h1><?= h(A('Dodaj napravo')) ?></h1>
  <p><?= h(A('NOC najprej prebere konfiguracijo routerja, nato iz nje pripravi namestitveno skripto.')) ?></p></div>
</div>

<?php if (empty($an)): ?>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('1. Konfiguracija routerja')) ?></h2></div>
    <div class="panel-body">
      <form class="form" method="post" action="/devices/analyze" enctype="multipart/form-data">
        <?= Auth::csrf() ?>
        <label class="f"><?= h(A('Naloži datoteko .rsc')) ?><input type="file" name="file" accept=".rsc,.txt"></label>
        <label class="f"><?= h(A('… ali prilepi izpis')) ?><textarea name="export" class="code" placeholder="# 2026-10-01 10:00:00 by RouterOS 7.16.2&#10;# model = RB5009UG+S+&#10;/interface bridge&#10;add name=bridge …"></textarea></label>
        <div class="actions"><button class="btn primary"><?= icon('search', 16) ?><?= h(A('Analiziraj konfiguracijo')) ?></button></div>
      </form>
    </div>
  </section>
  <aside class="panel">
    <div class="panel-head"><h2><?= icon('help', 18) ?><?= h(A('Kako dobim /export?')) ?></h2></div>
    <div class="panel-body">
      <ol class="steps">
        <li><div><b><?= h(A('Terminal na routerju')) ?></b><div class="cmd"><code>/export file=noc</code><button type="button" data-copy="/export file=noc" title="<?= h(A('Kopiraj')) ?>"><?= icon('copy', 14) ?></button></div></div></li>
        <li><div><b><?= h(A('Prenesi datoteko')) ?></b><p><?= h(A('Winbox → Files → noc.rsc → povleci na računalnik.')) ?></p></div></li>
        <li><div><b><?= h(A('Naloži jo tukaj')) ?></b><p><?= h(A('Gesla v izpisu niso vključena (RouterOS 7 jih privzeto skrije).')) ?></p></div></li>
      </ol>
    </div>
  </aside>
</div>
<?php else:
  $f = ['name' => $an['identity'] ?: 'MikroTik', 'site' => '', 'tenant_id' => 0, 'wan_iface' => $an['wan_iface'], 'public_ip' => $an['public_ip'], 'wan_gateway' => $an['gateway'],
        'ping_target' => '1.1.1.1', 'wan_down_mbps' => '', 'wan_up_mbps' => '', 'lan_networks' => implode(',', $an['lan_networks']), 'monitor_ifaces' => implode(',', $an['monitor_ifaces']),
        'down_ifaces' => $an['wan_iface'], 'flow_enabled' => $an['lan_networks'] ? 1 : 0, 'notes' => '', 'log_include' => '', 'log_exclude' => ''];
?>
<div class="grid g-main">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('router', 18) ?><?= h(A('2. Preveri in dopolni')) ?></h2></div>
    <div class="panel-body">
      <?php foreach ($an['warnings'] as $wn): ?><div class="note warn" style="margin-bottom:10px"><?= icon('alert', 18) ?><span><?= h(A($wn)) ?></span></div><?php endforeach; ?>
      <form class="form" method="post" action="/devices/create">
        <?= Auth::csrf() ?>
        <?php $isNew = true; require __DIR__ . '/_device_form.php'; ?>
        <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Dodaj in pripravi skripto')) ?></button><a class="btn ghost" href="/devices/new"><?= h(A('Naloži drug /export')) ?></a></div>
      </form>
    </div>
  </section>
  <aside class="panel">
    <div class="panel-head"><h2><?= icon('search', 18) ?><?= h(A('Prebrano iz konfiguracije')) ?></h2></div>
    <div class="panel-body"><dl class="kv">
      <?php foreach ($an['facts'] as $k => $v): ?><dt><?= h(A($k)) ?></dt><dd class="<?= in_array($k, ['Javni IP', 'Prehod', 'LAN omrežja', 'WAN'], true) ? 'mono' : '' ?>"><?= h($v) ?></dd><?php endforeach; ?>
    </dl></div>
  </aside>
</div>
<?php endif; ?>
