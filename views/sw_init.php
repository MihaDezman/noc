<?php
// Prva konfiguracija praznega stikala. Pričakuje $d, $blank, $ports (sw_ports), $uplink, $last (zadnja akcija swinit ali null).
$id = (int)$d['id'];
$lw = $last ? (json_decode((string)$last['data'], true) ?: [])['want'] ?? [] : [];
$stName = ['approved' => A('čaka na stikalo'), 'sent' => A('poslano stikalu'), 'done' => A('izvedeno'), 'failed' => A('ni uspelo – stikalo je povrnjeno'), 'cancelled' => A('preklicano')];
?>
<div class="page-head">
  <div><div class="crumbs"><a href="/devices"><?= h(A('Naprave')) ?></a><?= icon('chev', 14) ?><a href="/devices/<?= $id ?>"><?= h($d['name']) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h(A('Prva konfiguracija stikala')) ?></h1><p><?= h($d['model'] ?: 'MikroTik') ?> · <?= h(A('uplink kot trunk, porti kot access VLAN-i – vse naenkrat, z varovalko')) ?></p></div>
</div>

<?php if ($last): ?>
<section class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h2><?= icon('clock', 18) ?><?= h(A('Zadnja prva konfiguracija')) ?></h2><span class="tag<?= $last['status'] === 'done' ? ' up' : ($last['status'] === 'failed' ? ' down' : '') ?>"><?= h($stName[$last['status']] ?? $last['status']) ?></span></div>
  <div class="panel-body">
    <p class="small muted" style="margin-top:0"><?= h(fmt_dt($last['created_at'])) ?> · <?= h($last['decided_by'] ?: $last['source']) ?></p>
    <?php if (in_array($last['status'], ['approved', 'sent', 'done'], true) && !empty($lw['vlans'])): ?>
    <p><?= h(A('Na routerju nastavi tagirane VLAN-e proti stikalu. Predloga (prilagodi in prilepi v terminal routerja):')) ?></p>
    <?php $hint = SwInit::routerHint($d, (array)$lw['vlans']); ?>
    <div class="sw-hint"><pre class="mono sw-cmds"><?= h($hint) ?></pre><button type="button" class="btn sm" data-copy="<?= h($hint) ?>" title="<?= h(A('Kopiraj')) ?>" aria-label="<?= h(A('Kopiraj')) ?>"><?= icon('copy', 14) ?></button></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($last && in_array($last['status'], ['approved', 'sent'], true)): ?>
<div class="note"><?= icon('clock', 18) ?><span><?= h(A('Konfiguracija čaka na stikalo – stran osveži čez minuto.')) ?></span></div>
<?php elseif (!$blank): ?>
<div class="note"><?= icon('info', 18) ?><span><?= h(A('Stikalo ni prazno (VLAN filtering je vklopljen, VLAN-i ali VLAN vmesniki že obstajajo). Porte uredi posamično na plošči stikala.')) ?></span></div>
<?php else: ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('settings', 18) ?><?= h(A('Nastavitev')) ?></h2></div>
  <div class="panel-body">
    <form class="form sw-init" data-sw-init="<?= $id ?>">
      <div class="row c3">
        <label class="f"><?= h(A('Uplink (port proti routerju)')) ?>
          <select name="uplink"><?php foreach ($ports as $p): ?><option value="<?= h($p['port']) ?>"<?= $p['port'] === $uplink ? ' selected' : '' ?>><?= h(SwLayout::label($p['port']) . ' – ' . $p['port']) ?><?= $p['port'] === $uplink ? ' (' . h(A('zaznan')) . ')' : '' ?></option><?php endforeach; ?></select>
          <small><?= h(A('postane trunk: vsi VLAN-i tagirani, upravljanje ostane netagirano')) ?></small></label>
      </div>
      <div class="swi-rows" data-swi-rows>
        <div class="swi-head small muted"><span><?= h(A('Porti')) ?></span><span>VLAN</span><span><?= h(A('Ime (neobvezno)')) ?></span><span></span></div>
        <?php foreach ([['1-5', 10, ''], ['6-10', 20, ''], ['', '', '']] as $r): ?>
        <div class="swi-row" data-swi-row>
          <input type="text" name="ports[]" value="<?= h((string)$r[0]) ?>" placeholder="<?= h(A('npr. 1-5 ali 1,3,7-9')) ?>" class="mono" aria-label="<?= h(A('Porti')) ?>">
          <input type="number" name="vlan[]" value="<?= h((string)$r[1]) ?>" min="2" max="4094" placeholder="VLAN" aria-label="VLAN">
          <input type="text" name="name[]" maxlength="60" placeholder="<?= h(A('npr. pisarna')) ?>" aria-label="<?= h(A('Ime')) ?>">
          <button type="button" class="btn sm ghost" data-swi-del aria-label="<?= h(A('Odstrani vrstico')) ?>"><?= icon('x', 14) ?></button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="actions" style="justify-content:flex-start;margin-top:0"><button type="button" class="btn sm" data-swi-add><?= icon('plus', 14) ?><?= h(A('Vrstica')) ?></button></div>
      <div class="row c3">
        <label class="f"><?= h(A('Dodatni tagirani VLAN-i na uplinku')) ?><input type="text" name="extra" class="mono" placeholder="<?= h(A('npr. 30,40')) ?>"><small><?= h(A('VLAN-i iz vrstic so tagirani samodejno')) ?></small></label>
      </div>
      <p class="small muted"><?= h(A('Oznake portov kot na plošči: 1 = ether1, S1 = sfp-sfpplus1, Q1 = qsfp. Porti, ki jih ne navedeš, ostanejo v VLAN 1 (upravljanje).')) ?></p>
      <div class="actions" style="justify-content:flex-start"><button type="button" class="btn" data-swi-preview><?= icon('eye', 15) ?><?= h(A('Predogled')) ?></button></div>
      <div class="sw-preview" data-sw-out hidden></div>
    </form>
  </div>
</section>
<?php endif; ?>
