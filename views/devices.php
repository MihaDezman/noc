<div class="page-head">
  <div><h1><?= h(A('Naprave')) ?></h1><p><?= h(count($devs) . ' ' . plural(count($devs), A('naprava'), A('napravi'), A('naprave'), A('naprav'))) ?></p></div>
  <?php if (Auth::isSuper()): ?><a class="btn primary" href="/devices/new"><?= icon('plus', 16) ?><?= h(A('Dodaj napravo')) ?></a><?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <form class="filters" method="get">
      <input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h(A('Ime, IP, model …')) ?>">
      <?php if (count($tenants) > 1): ?>
      <select name="t" data-autosubmit><option value=""><?= h(A('Vsi naročniki')) ?></option>
        <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($_GET['t'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="s" data-autosubmit><option value=""><?= h(A('Vsa stanja')) ?></option>
        <?php foreach (['up', 'warn', 'down', 'new'] as $s): ?><option value="<?= $s ?>" <?= ($_GET['s'] ?? '') === $s ? 'selected' : '' ?>><?= h(state_label($s)) ?></option><?php endforeach; ?>
      </select>
      <button class="btn sm"><?= icon('filter', 15) ?><?= h(A('Filtriraj')) ?></button>
    </form>
  </div>
  <?php if (!$devs): ?><div class="empty"><?= icon('router', 32) ?><div><?= h(A('Ni naprav, ki bi ustrezale filtru.')) ?></div></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl dev-tbl">
    <thead><tr><th><?= h(A('Naprava')) ?></th><th><?= h(A('Naročnik')) ?></th><th><?= h(A('Stanje')) ?></th><th class="col-model"><?= h(A('Model')) ?></th><th class="col-ip"><?= h(A('Javni IP')) ?></th><th class="num"><?= h(A('Promet WAN')) ?></th><th style="width:96px">CPU</th><th class="num"><?= h(A('Ping')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($devs as $d):
        $hh = $d['status']['h'] ?? [];
        $st = db()->prepare('SELECT rx_bps, tx_bps FROM device_ifaces WHERE device_id=? AND name=?'); $st->execute([$d['id'], $d['wan_iface']]); $wan = $st->fetch();
    ?>
      <tr>
        <td class="nowrap"><a class="rowlink" href="/devices/<?= (int)$d['id'] ?>"><?= icon(kind_icon($d['kind']), 16) ?> <?= h($d['name']) ?></a></td>
        <td><?= h($d['tenant_name'] ?: '–') ?><?php if ($d['site']): ?><div class="small muted"><?= h($d['site']) ?></div><?php endif; ?></td>
        <td class="nowrap"><?= state_tag($d) ?><?php if ($d['alert_count']): ?> <span class="tag"><?= icon('bell', 12) ?><?= (int)$d['alert_count'] ?></span><?php endif; ?><div class="small muted"><?= h(fmt_ago($d['last_seen_at'])) ?></div></td>
        <td class="col-model nowrap"><?= h($d['model'] ?: '–') ?><div class="small muted mono"><?= h($d['os_version'] ? 'RouterOS ' . preg_replace('/\s.*$/', '', $d['os_version']) : '') ?></div></td>
        <td class="col-ip mono nowrap"><?= h($d['public_ip'] ?: '–') ?></td>
        <td class="num nowrap"><?php if ($wan): ?><span class="down-c"><?= icon('arrow-down', 13) ?><?= h(fmt_bps($wan['rx_bps'])) ?></span><div class="up-c small"><?= icon('arrow-up', 12) ?><?= h(fmt_bps($wan['tx_bps'])) ?></div><?php else: ?>–<?php endif; ?></td>
        <td><?= pct_meter(isset($hh['cpu']) ? (float)$hh['cpu'] : null, 60, 85) ?><div class="small muted"><?= isset($hh['cpu']) ? (int)$hh['cpu'] . ' %' : '' ?></div></td>
        <td class="num nowrap"><?= isset($hh['gw_ms']) && $hh['gw_ms'] !== null ? number_format((float)$hh['gw_ms'], 1, ',', '') . ' ms' : '–' ?><div class="small muted"><?= h(fmt_uptime($d['last_uptime'] !== null ? (int)$d['last_uptime'] : null)) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
