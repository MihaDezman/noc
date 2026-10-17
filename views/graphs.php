<?php
/** Grafi vseh routerjev: promet WAN in latenca, dva stolpca, router za routerjem */
$ranges = ['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d'];
$wanRows = Devices::ifacesMany(array_column($devs, 'id'));
?>
<div class="page-head">
  <div><h1><?= h(A('Grafi')) ?></h1><p><?= h(A('Promet na WAN in latenca za vse naprave')) ?> · <?= h(count($devs) . ' ' . plural(count($devs), A('naprava'), A('napravi'), A('naprave'), A('naprav'))) ?></p></div>
  <div class="actions"><?= range_seg($ranges, '24h', 'graphs') ?></div>
</div>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head">
    <form class="filters" method="get">
      <input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h(A('Ime, IP, model …')) ?>">
      <?php if (count($tenants) > 1): ?>
      <select name="t" data-autosubmit><option value=""><?= h(A('Vsi naročniki')) ?></option>
        <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($_GET['t'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn sm"><?= icon('filter', 15) ?><?= h(A('Filtriraj')) ?></button>
    </form>
  </div>
</div>

<?php if (!$devs): ?>
<div class="panel"><div class="empty"><?= empty_art('search') ?><div><?= h(A('Ni naprav, ki bi ustrezale filtru.')) ?></div></div></div>
<?php else: ?>
<div id="graphs" class="graph-list">
<?php foreach ($devs as $d): $id = (int)$d['id']; $hh = $d['status']['h'] ?? [];
  $wan = null; foreach ($wanRows[$id] ?? [] as $r) if ($r['name'] === $d['wan_iface']) $wan = $r; ?>
  <section class="graph-dev">
    <div class="graph-dev-head">
      <a class="dev-link" href="/devices/<?= $id ?>"><?= device_art((string)$d['model'], (string)$d['kind'], 28) ?><b><?= h($d['name']) ?></b></a>
      <span class="small muted"><?= h($d['tenant_name'] ?: '') ?><?= $d['site'] ? ' · ' . h($d['site']) : '' ?></span>
      <?= state_tag($d) ?>
      <span class="graph-now small">
        <?php if ($wan): ?><span class="down-c"><?= icon('arrow-down', 13) ?><?= h(fmt_bps($wan['rx_bps'])) ?></span> <span class="up-c"><?= icon('arrow-up', 13) ?><?= h(fmt_bps($wan['tx_bps'])) ?></span><?php endif; ?>
        <?php if (isset($hh['gw_ms']) && $hh['gw_ms'] !== null): ?><span class="muted"><?= icon('ping', 13) ?><?= h(number_format((float)$hh['gw_ms'], 1, ',', '')) ?> ms</span><?php endif; ?>
      </span>
    </div>
    <div class="graph-pair">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('activity', 16) ?><?= h(A('Promet WAN')) ?> <span class="mono small faint"><?= h($d['wan_iface'] ?: '–') ?></span></h3><?php if ($d['wan_down_mbps']): ?><span class="small faint"><?= h(A('Zakup {d}/{u} Mb/s', ['d' => $d['wan_down_mbps'], 'u' => $d['wan_up_mbps'] ?: '?'])) ?></span><?php endif; ?></div>
        <div class="panel-body"><?= $d['wan_iface'] !== '' ? str_replace('data-chart ', 'data-chart data-lazy ', chart($id, 'traffic', '24h', (string)$d['wan_iface'], 190)) : '<div class="empty small">' . h(A('WAN vmesnik ni določen.')) . '</div>' ?>
          <div class="legend small"><span><i style="background:var(--aqua)"></i><?= h(A('Download')) ?></span><span><i style="background:var(--violet)"></i><?= h(A('Upload')) ?></span></div></div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('ping', 16) ?><?= h(A('Latenca in izguba')) ?> <span class="mono small faint"><?= h($d['ping_target'] ?: '') ?></span></h3></div>
        <div class="panel-body"><?= str_replace('data-chart ', 'data-chart data-lazy ', chart($id, 'ping', '24h', '', 190)) ?>
          <div class="legend small"><span><i style="background:var(--violet)"></i><?= h(A('Prehod')) ?></span><span><i style="background:var(--aqua)"></i><?= h(A('Internet')) ?></span><span><i style="background:var(--down)"></i><?= h(A('izguba paketov')) ?></span></div></div>
      </div>
    </div>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
