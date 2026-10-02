<?php
// Polja naprave. Pričakuje $f (vrednosti) in $tenants; $isNew za nov vnos.
$isNew ??= false;
?>
<div class="row c3">
  <label class="f"><?= h(A('Ime naprave')) ?><input type="text" name="name" required value="<?= h($f['name']) ?>"><small><?= h(A('Kot ga boš videl v NOC, npr. "Office-GW".')) ?></small></label>
  <label class="f"><?= h(A('Lokacija / naslov')) ?><input type="text" name="site" value="<?= h($f['site']) ?>"></label>
</div>
<div class="row c3">
  <label class="f"><?= h(A('Naročnik')) ?>
    <select name="tenant_id"><option value="">–</option>
      <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($f['tenant_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
    </select></label>
  <?php if ($isNew): ?><label class="f"><?= h(A('… ali nov naročnik')) ?><input type="text" name="new_tenant" placeholder="<?= h(A('Ime novega naročnika')) ?>"></label><?php endif; ?>
</div>
<h3 style="margin-top:6px"><?= icon('globe', 16) ?> <?= h(A('Internet (WAN)')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('WAN vmesnik')) ?><input type="text" name="wan_iface" value="<?= h($f['wan_iface']) ?>" class="mono"></label>
  <label class="f"><?= h(A('Javni (statični) IP')) ?><input type="text" name="public_ip" value="<?= h($f['public_ip']) ?>" class="mono"><small><?= h(A('Za ping s strežnika in sprejem traffic-flow.')) ?></small></label>
  <label class="f"><?= h(A('Prehod ponudnika')) ?><input type="text" name="wan_gateway" value="<?= h($f['wan_gateway']) ?>" class="mono"><small><?= h(A('Rezerva – skripta prehod najde sama iz privzete poti.')) ?></small></label>
</div>
<div class="row c3">
  <label class="f"><?= h(A('Zunanji cilj za ping')) ?><input type="text" name="ping_target" value="<?= h($f['ping_target']) ?>" class="mono"></label>
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
<?php if (!$isNew): ?>
<label class="check"><input type="checkbox" name="auto_update" value="1" <?= (int)($f['auto_update'] ?? 1) ? 'checked' : '' ?>> <span><b><?= h(A('Samodejne posodobitve skripte')) ?></b> – <?= h(A('router sam prenese novo skripto, ko se v NOC kaj spremeni (požarni zid vedno samo ročno)')) ?></span></label>
<h3 style="margin-top:6px"><?= icon('scroll', 16) ?> <?= h(A('Logi')) ?></h3>
<div class="row c3">
  <label class="f"><?= h(A('Pošlji teme (regex)')) ?><input type="text" name="log_include" value="<?= h($f['log_include']) ?>" class="mono"></label>
  <label class="f"><?= h(A('Izpusti teme (regex)')) ?><input type="text" name="log_exclude" value="<?= h($f['log_exclude']) ?>" class="mono"></label>
</div>
<?php endif; ?>
<label class="f"><?= h(A('Opombe')) ?><textarea name="notes" rows="3"><?= h($f['notes']) ?></textarea></label>
