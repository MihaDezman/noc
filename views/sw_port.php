<?php
// Panel porta stikala (fragment za stranski panel). Pričakuje $d, $id, $port, $pif (device_ifaces), $isSuper.
$st = db()->prepare('SELECT * FROM sw_ports WHERE device_id=? AND port=?'); $st->execute([$id, $port]); $sp = $st->fetch() ?: null;
$st = db()->prepare('SELECT * FROM sfp_state WHERE device_id=? AND iface=?'); $st->execute([$id, $port]); $sf = $st->fetch() ?: null;
$st = db()->prepare('SELECT vlan_id, comment FROM sw_vlans WHERE device_id=? ORDER BY vlan_id'); $st->execute([$id]); $vl = $st->fetchAll();
$vn = []; foreach ($vl as $v) $vn[(int)$v['vlan_id']] = $v['comment'];
$host = SwitchIngest::hostOn($d, $port);
$uplink = SwitchIngest::uplink($d);
$watch = in_array($port, Devices::list((string)$d['down_ifaces']), true);
$vf = SwitchIngest::vlanFiltering($d);
$up = (int)$pif['running'] === 1; $dis = (int)$pif['disabled'] === 1;
$ut = $sp ? array_map('intval', array_filter(explode(',', (string)$sp['untagged']))) : [];
$tg = $sp ? array_map('intval', array_filter(explode(',', (string)$sp['tagged']))) : [];
$mode = $sp['mode'] ?? 'none';
$vid = $ut[0] ?? (int)($sp['pvid'] ?? 1);
$vtxt = fn(int $v) => 'VLAN ' . $v . (($vn[$v] ?? '') !== '' ? ' – ' . $vn[$v] : '');
$st = db()->prepare('SELECT * FROM iface_events WHERE device_id=? AND iface=? ORDER BY ts DESC LIMIT 10'); $st->execute([$id, $port]); $evs = $st->fetchAll();
$st = db()->prepare("SELECT * FROM noc_actions WHERE device_id=? AND kind='swport' AND ip=? ORDER BY id DESC LIMIT 5"); $st->execute([$id, $port]); $acts = $st->fetchAll();
$pending = array_values(array_filter($acts, fn($a) => in_array($a['status'], ['approved', 'sent'], true)));
$evName = ['down' => A('padel'), 'up' => A('vzpostavljen'), 'speed' => A('sprememba hitrosti'), 'sfp_in' => A('SFP vstavljen'), 'sfp_out' => A('SFP odstranjen'), 'sfp_swap' => A('SFP zamenjan')];
$poeCap = $sp && $sp['poe_mode'] !== '';
?>
<div class="drw-head">
  <div><h2><?= h(A('Port {n}', ['n' => SwLayout::label($port)])) ?> <span class="mono faint small"><?= h($port) ?></span></h2>
    <div class="small muted"><?= h($pif['comment'] ?: ($host['name'] ?: '')) ?></div></div>
  <?= $dis ? '<span class="tag">' . h(A('izklopljen')) . '</span>' : ($up ? '<span class="tag up">up ' . h($pif['rate']) . '</span>' : '<span class="tag down">' . h(A('brez povezave')) . '</span>') ?>
  <?= $port === $uplink ? '<span class="tag violet">uplink</span>' : '' ?>
  <button type="button" class="drw-x" data-drw-close aria-label="<?= h(A('Zapri')) ?>"><?= icon('x', 16) ?></button>
</div>
<div class="drw-body" data-port="<?= h($port) ?>" data-dev="<?= $id ?>">
  <section>
    <h3><?= h(A('Stanje')) ?></h3>
    <dl class="kv">
      <dt><?= h(A('Naprava')) ?></dt><dd><?= $host['n'] ? h($host['name'] ?: $host['mac']) . ($host['ip'] ? ' <span class="mono faint">' . h($host['ip']) . '</span>' : '') . ($host['n'] > 1 ? '<div class="small muted">' . h(A('na portu je {n} naprav (MAC naslovov)', ['n' => $host['n']])) . '</div>' : '') : '–' ?></dd>
      <?php if ($host['mac']): ?><dt>MAC</dt><dd class="mono"><?= h($host['mac']) ?></dd><?php endif; ?>
      <dt><?= h(A('Hitrost')) ?></dt><dd><?= $up ? h($pif['rate'] ?: A('povezan')) : '–' ?></dd>
      <dt>VLAN</dt><dd><?php if (!$sp): ?>–<?php elseif ($mode === 'trunk'): ?><span class="tag violet">trunk</span> <?= h(implode(', ', $tg)) ?><?= $ut ? ' <span class="faint">(native ' . (int)$ut[0] . ')</span>' : '' ?><?php else: ?><i class="sw-dot" style="background:<?= SwLayout::vlanColor($vid) ?>"></i><?= h($vtxt($vid)) ?><?php endif; ?></dd>
      <?php if ($poeCap): ?><dt>PoE</dt><dd><?= h($sp['poe_mode']) ?><?= $sp['poe_w'] !== null ? ' · ' . h(number_format((float)$sp['poe_w'], 1, ',', '') . ' W') : '' ?><?= $sp['poe_status'] ? ' <span class="faint small">' . h($sp['poe_status']) . '</span>' : '' ?></dd><?php endif; ?>
      <dt><?= h(A('Napake')) ?></dt><dd><?= ((int)$pif['rx_error'] + (int)$pif['tx_error']) > 0 ? '<b style="color:var(--warn)">RX ' . (int)$pif['rx_error'] . ' · TX ' . (int)$pif['tx_error'] . '</b> <span class="faint small">' . h(A('od zagona – preveri kabel')) . '</span>' : '0' ?></dd>
      <dt><?= h(A('Zadnja sprememba')) ?></dt><dd class="small"><?= h($up ? ($pif['last_up'] ? A('gor od {t}', ['t' => $pif['last_up']]) : '–') : ($pif['last_down'] ? A('dol od {t}', ['t' => $pif['last_down']]) : '–')) ?></dd>
      <?php if ($sp && $sp['stp_role'] !== ''): ?><dt>STP</dt><dd class="small"><?= h($sp['stp_role'] . ($sp['edge'] ? ', edge' : '')) ?></dd><?php endif; ?>
      <?php if ($sf && (int)$sf['present']): ?><dt>SFP</dt><dd class="small"><?= h(trim($sf['vendor'] . ' ' . $sf['part'])) ?><?= $sf['rx'] !== null ? ' · RX ' . h(number_format((float)$sf['rx'], 2, ',', '') . ' dBm') : '' ?><?= $sf['tx'] !== null ? ' · TX ' . h(number_format((float)$sf['tx'], 2, ',', '') . ' dBm') : '' ?><?= $sf['temp'] !== null ? ' · ' . h(number_format((float)$sf['temp'], 0) . ' °C') : '' ?></dd><?php endif; ?>
    </dl>
  </section>

  <section>
    <div class="drw-h3"><h3><?= h(A('Promet')) ?></h3><?= range_seg(['6h' => '6 h', '24h' => '24 h', '7d' => '7 d', '30d' => '30 d'], '24h', 'swc') ?></div>
    <div id="swc" data-chart-group><?= chart($id, 'traffic', '24h', $port, 170) ?></div>
    <div class="legend" style="margin-top:6px"><span><i style="background:var(--aqua)"></i><?= h(A('prejeto')) ?></span><span><i style="background:var(--violet)"></i><?= h(A('poslano')) ?></span></div>
  </section>

  <?php if ($isSuper): ?>
  <section>
    <h3><?= h(A('Obvestila')) ?></h3>
    <label class="check"><input type="checkbox" data-sw-alert <?= $watch ? 'checked' : '' ?>> <span><?= h(A('Obvesti, ko port pade in ko se vrne')) ?></span></label>
    <?php if ($port === $uplink): ?><p class="small muted" style="margin:6px 0 0"><?= h(A('Izpad uplinka javi NOC kot »stikalo ne javlja« – stikalo brez uplinka ne more poslati podatkov.')) ?></p><?php endif; ?>
  </section>

  <section>
    <h3><?= h(A('Nastavitev porta')) ?></h3>
    <?php if ($pending): ?><div class="note" style="margin-bottom:10px"><?= icon('clock', 16) ?><span><?= h(A('Sprememba čaka na stikalo (v minuti).')) ?></span></div><?php endif; ?>
    <form class="form sw-form" data-sw-form>
      <input type="hidden" name="p" value="<?= h($port) ?>">
      <div class="row c2">
        <label class="f"><?= h(A('Ime porta')) ?><input type="text" name="comment" maxlength="80" value="<?= h($pif['comment']) ?>"></label>
        <label class="f"><?= h(A('Port')) ?><select name="disabled"><option value="0"<?= $dis ? '' : ' selected' ?>><?= h(A('vklopljen')) ?></option><option value="1"<?= $dis ? ' selected' : '' ?>><?= h(A('izklopljen')) ?></option></select></label>
      </div>
      <?php if ($vf && $sp): ?>
      <div class="row c2">
        <label class="f"><?= h(A('Način')) ?><select name="mode" data-sw-mode><option value="access"<?= $mode !== 'trunk' ? ' selected' : '' ?>>Access</option><option value="trunk"<?= $mode === 'trunk' ? ' selected' : '' ?>><?= h(A('Trunk (uplink)')) ?></option></select></label>
        <label class="f" data-sw-show="access">VLAN<input type="number" name="vlan" min="1" max="4094" value="<?= $vid ?>" list="swvl-<?= $id ?>"></label>
        <label class="f" data-sw-show="trunk"><?= h(A('Netagiran (native)')) ?><input type="number" name="native" min="0" max="4094" value="<?= $mode === 'trunk' ? (int)($ut[0] ?? 0) : 1 ?>" list="swvl-<?= $id ?>"><small><?= h(A('0 = samo tagiran promet')) ?></small></label>
      </div>
      <datalist id="swvl-<?= $id ?>"><?php foreach ($vl as $v): ?><option value="<?= (int)$v['vlan_id'] ?>"><?= h($vtxt((int)$v['vlan_id'])) ?></option><?php endforeach; ?></datalist>
      <div class="f" data-sw-show="trunk"><span class="small"><?= h(A('Tagirani VLAN-i')) ?></span>
        <div class="chk-row"><?php foreach ($vl as $v): $vv = (int)$v['vlan_id']; if ($vv === 1) continue; ?><label class="check"><input type="checkbox" name="tagged[]" value="<?= $vv ?>" <?= in_array($vv, $tg, true) ? 'checked' : '' ?>> <span><?= h($vtxt($vv)) ?></span></label><?php endforeach; ?></div>
        <input type="text" name="tagged_more" placeholder="<?= h(A('dodatni, npr. 30,40')) ?>" class="mono" style="margin-top:6px"></div>
      <?php else: ?>
      <p class="small muted"><?= h($sp ? A('Stikalo nima vklopljenega bridge VLAN filteringa – urejanje VLAN-ov ni mogoče.') : A('Port ni v bridgu – VLAN-a ni mogoče nastaviti.')) ?></p>
      <?php endif; ?>
      <?php if ($poeCap): ?>
      <div class="row c2">
        <label class="f">PoE<select name="poe"><option value=""><?= h(A('brez spremembe')) ?></option><option value="auto-on"<?= $sp['poe_mode'] === 'auto-on' ? ' selected' : '' ?>><?= h(A('samodejno')) ?></option><option value="off"<?= $sp['poe_mode'] === 'off' ? ' selected' : '' ?>><?= h(A('izklopljeno')) ?></option></select></label>
        <label class="check" style="align-self:end;padding-bottom:10px"><input type="checkbox" name="poe_reset" value="1"> <span><?= h(A('Ponastavi PoE (5 s brez napajanja)')) ?></span></label>
      </div>
      <?php endif; ?>
      <div class="actions"><button type="button" class="btn" data-sw-preview><?= icon('eye', 15) ?><?= h(A('Predogled')) ?></button></div>
      <div class="sw-preview" data-sw-out hidden></div>
    </form>
  </section>
  <?php endif; ?>

  <section>
    <h3><?= h(A('Zadnji dogodki')) ?></h3>
    <?php if (!$evs && !$acts): ?><p class="small muted"><?= h(A('Ni dogodkov.')) ?></p><?php else: ?>
    <ul class="feed compact">
      <?php foreach ($acts as $a): $w = json_decode((string)$a['data'], true) ?: []; ?><li><span class="sev-bar info"></span><div class="what small"><?= h(A('Sprememba iz NOC')) ?>: <?= h((string)($w['summary'] ?? '')) ?> <span class="faint">(<?= h($a['decided_by'] ?: $a['source']) ?>, <?= h(['approved' => A('čaka'), 'sent' => A('poslano'), 'done' => A('izvedeno'), 'failed' => A('ni uspelo'), 'cancelled' => A('preklicano')][$a['status']] ?? $a['status']) ?>)</span></div><span class="when"><?= h(fmt_dt($a['created_at'])) ?></span></li><?php endforeach; ?>
      <?php foreach ($evs as $e): ?><li><span class="sev-bar <?= $e['kind'] === 'down' ? 'critical' : ($e['kind'] === 'up' ? 'ok' : 'info') ?>"></span><div class="what small"><?= h($evName[$e['kind']] ?? $e['kind']) ?> <span class="faint"><?= h($e['kind'] === 'down' ? '' : $e['detail']) ?></span></div><span class="when"><?= h(fmt_dt($e['ts'])) ?></span></li><?php endforeach; ?>
    </ul><?php endif; ?>
  </section>
</div>
