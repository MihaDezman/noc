<?php
// Polja naprave. Pričakuje $f (vrednosti) in $tenants; $isNew za nov vnos.
$isNew ??= false;
$fKind = (string)($f['kind'] ?? 'router');
$routers = array_values(array_filter(Devices::all(), fn($x) => $x['kind'] === 'router'));
?>
<div class="row c3">
  <label class="f"><?= h(A('Vrsta naprave')) ?>
    <?php if ($isNew): ?><select name="kind" data-kind-select><option value="router"<?= $fKind === 'router' ? ' selected' : '' ?>><?= h(A('Router')) ?></option><option value="switch"<?= $fKind === 'switch' ? ' selected' : '' ?>><?= h(A('Stikalo')) ?></option></select>
    <?php else: ?><input type="hidden" name="kind" value="<?= h($fKind) ?>"><input type="text" value="<?= h($fKind === 'switch' ? A('Stikalo') : A('Router')) ?>" disabled><?php endif; ?></label>
  <label class="f" data-kind="switch"><?= h(A('Za routerjem')) ?>
    <select name="parent_id"><option value="">–</option>
      <?php foreach ($routers as $rt): ?><option value="<?= (int)$rt['id'] ?>" <?= (int)($f['parent_id'] ?? 0) === (int)$rt['id'] ? 'selected' : '' ?>><?= h($rt['name'] . ($rt['tenant_name'] ? ' · ' . $rt['tenant_name'] : '')) ?></option><?php endforeach; ?>
    </select><small><?= h(A('Stikalo prevzame naročnika routerja; terminal gre prek tega routerja.')) ?></small></label>
</div>
<div class="row c3">
  <label class="f"><?= h(A('Ime naprave')) ?><input type="text" name="name" required value="<?= h($f['name']) ?>"><small><?= h(A('Kot ga boš videl v NOC, npr. "Office-GW".')) ?></small></label>
  <label class="f"><?= h(A('Lokacija / naslov')) ?><input type="text" name="site" value="<?= h($f['site']) ?>"></label>
</div>
<div class="row c3" data-kind="router">
  <label class="f"><?= h(A('Naročnik')) ?>
    <select name="tenant_id"><option value="">–</option>
      <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($f['tenant_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
    </select></label>
  <?php if ($isNew): ?><label class="f"><?= h(A('… ali nov naročnik')) ?><input type="text" name="new_tenant" placeholder="<?= h(A('Ime novega naročnika')) ?>"></label><?php endif; ?>
</div>
<div data-kind="router">
<h3 style="margin-top:6px"><?= icon('globe', 16) ?> <?= h(A('Internet (WAN)')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('WAN vmesnik')) ?><input type="text" name="wan_iface" value="<?= h($f['wan_iface']) ?>" class="mono"></label>
  <label class="f"><?= h(A('Javni (statični) IP')) ?><input type="text" name="public_ip" value="<?= h($f['public_ip']) ?>" class="mono"><small><?= h(A('Za ping s strežnika in sprejem traffic-flow.')) ?></small></label>
  <label class="f"><?= h(A('Prehod ponudnika')) ?><input type="text" name="wan_gateway" value="<?= h($f['wan_gateway']) ?>" class="mono"><small><?= h(A('Skripta prehod najde sama; ta naslov je rezerva ali cilj pinga (glej Ping prehoda).')) ?></small></label>
</div>
<div class="row c3">
  <?php $gp = (string)($f['gw_ping'] ?? 'auto'); ?>
  <label class="f"><?= h(A('Ping prehoda')) ?><select name="gw_ping"><?php foreach (['auto' => A('Samodejno (prehod iz privzete poti)'), 'fixed' => A('Naslov iz polja Prehod ponudnika'), 'off' => A('Ne pingaj prehoda')] as $k => $l): ?><option value="<?= $k ?>"<?= $gp === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><small><?= h(A('Če prehod ponudnika ne odgovarja na ping, ga izklopi ali vpiši drug naslov.')) ?></small></label>
  <label class="f"><?= h(A('Zunanji cilj za ping')) ?><input type="text" name="ping_target" value="<?= h($f['ping_target']) ?>" class="mono"></label>
</div>
<div class="row c3">
  <label class="f"><?= h(A('Zakupljen download (Mb/s)')) ?><input type="number" min="0" name="wan_down_mbps" value="<?= h($f['wan_down_mbps']) ?>"></label>
  <label class="f"><?= h(A('Zakupljen upload (Mb/s)')) ?><input type="number" min="0" name="wan_up_mbps" value="<?= h($f['wan_up_mbps']) ?>"></label>
</div>
<h3 style="margin-top:6px"><?= icon('net', 16) ?> <?= h(A('LAN in vmesniki')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('LAN omrežja')) ?><input type="text" name="lan_networks" value="<?= h($f['lan_networks']) ?>" class="mono"><small><?= h(A('Za promet po napravah, ločena z vejico.')) ?></small></label>
  <label class="f"><?= h(A('Vmesniki z zgodovino prometa')) ?><input type="text" name="monitor_ifaces" value="<?= h($f['monitor_ifaces']) ?>" class="mono"><small><?= h(A('Prvi naj bo WAN.')) ?></small></label>
  <label class="f"><?= h(A('Alarm, ko pade vmesnik')) ?><input type="text" name="down_ifaces" value="<?= h($f['down_ifaces']) ?>" class="mono"></label>
</div>
<label class="check"><input type="checkbox" name="flow_enabled" value="1" <?= !empty($f['flow_enabled']) ? 'checked' : '' ?>> <?= h(A('Traffic-flow: spremljaj promet po napravah v LAN-u')) ?></label>
</div>
<div data-kind="switch">
<h3 style="margin-top:6px"><?= icon('switch', 16) ?> <?= h(A('Stikalo')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('Upravljalni naslov stikala')) ?><input type="text" name="mgmt_ip" value="<?= h($f['mgmt_ip'] ?? '') ?>" class="mono"><small><?= h(A('Naslov v LAN-u; skripta ga javi sama, ročno samo za terminal.')) ?></small></label>
  <label class="f"><?= h(A('Porti z obvestilom')) ?><input type="text" name="down_ifaces_sw" value="<?= h($f['down_ifaces']) ?>" class="mono"><small><?= h(A('Obvestilo, ko port pade in ko se vrne. Nastaviš tudi s kljukico pri portu.')) ?></small></label>
</div>
</div>
<?php if (!$isNew): ?>
<label class="check"><input type="checkbox" name="auto_update" value="1" <?= (int)($f['auto_update'] ?? 1) ? 'checked' : '' ?>> <span><b><?= h(A('Samodejne posodobitve skripte')) ?></b> – <?= h(A('router sam prenese novo skripto, ko se v NOC kaj spremeni (požarni zid vedno samo ročno)')) ?></span></label>
<h3 style="margin-top:6px"><?= icon('scroll', 16) ?> <?= h(A('Logi')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('Pošlji teme (regex)')) ?><input type="text" name="log_include" value="<?= h($f['log_include']) ?>" class="mono"></label>
  <label class="f"><?= h(A('Izpusti teme (regex)')) ?><input type="text" name="log_exclude" value="<?= h($f['log_exclude']) ?>" class="mono"></label>
</div>
<?php endif; ?>
<label class="f"><?= h(A('Opombe')) ?><textarea name="notes" rows="3"><?= h($f['notes']) ?></textarea></label>
