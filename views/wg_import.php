<?php /** @var array $parsed @var string $tok */
$rows = $parsed['rows']; $st = $parsed['stats'];
$lbl = ['match' => ['up', A('na routerju – uvozi')], 'notonrouter' => ['warn', A('ni na routerju')], 'nodevice' => ['', A('router ni v NOC')], 'badkey' => ['down', A('neveljaven ključ')]];
$order = ['match' => 0, 'notonrouter' => 1, 'nodevice' => 2, 'badkey' => 3];
$idx = array_keys($rows); usort($idx, fn($a, $b) => [$order[$rows[$a]['state']] ?? 9, $rows[$a]['device'], $rows[$a]['ip']] <=> [$order[$rows[$b]['state']] ?? 9, $rows[$b]['device'], $rows[$b]['ip']]); ?>
<div class="page-head">
  <div><div class="crumbs"><a href="/wireguard">WireGuard</a><?= icon('chev', 14) ?></div>
  <h1><?= h(A('Uvoz WireGuard uporabnikov')) ?></h1>
  <p><?= h(A('Pregled pred shranjevanjem – nič še ni shranjeno.')) ?></p></div>
</div>

<div class="grid g4">
  <?php foreach (['match', 'notonrouter', 'nodevice', 'badkey'] as $k): ?>
  <section class="panel"><div class="panel-body"><div class="small muted"><?= h($lbl[$k][1]) ?></div><div style="font-size:1.6rem;font-weight:700"><?= (int)($st[$k] ?? 0) ?></div></div></section>
  <?php endforeach; ?>
</div>

<form method="post" action="/wireguard/import/apply" style="margin-top:20px"><?= Auth::csrf() ?><input type="hidden" name="tok" value="<?= h($tok) ?>">
<section class="panel">
  <div class="panel-head"><h2><?= icon('list', 18) ?><?= h(A('Uporabniki iz datoteke')) ?></h2><span class="small muted"><?= h(A('povezani po javnem ključu routerja in uporabnika')) ?></span></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Ime')) ?></th><th><?= h(A('Router')) ?></th><th>IP</th><th><?= h(A('Ustvarjen')) ?></th><th><?= h(A('Stanje')) ?></th><th><?= h(A('Dodaj na router')) ?></th></tr></thead>
    <tbody><?php foreach ($idx as $i): $r = $rows[$i]; [$c, $l] = $lbl[$r['state']] ?? ['', $r['state']]; ?><tr>
      <td><?= icon($r['kind'] === 'phone' ? 'smartphone' : 'laptop', 14, 'faint') ?> <?= h($r['name'] ?: '–') ?></td>
      <td><?= h($r['device'] ?: '–') ?><?= $r['iface'] !== '' ? ' <span class="small faint mono">' . h($r['iface']) . '</span>' : '' ?></td>
      <td class="mono"><?= h($r['ip']) ?></td>
      <td class="small muted nowrap"><?= $r['ts'] !== '' && strtotime($r['ts']) ? h(fmt_dt(date('Y-m-d H:i:s', strtotime($r['ts'])))) : '–' ?></td>
      <td><span class="tag <?= $c ?>"><?= h($l) ?></span></td>
      <td><?php if ($r['state'] === 'notonrouter'): ?><label class="check"><input type="checkbox" name="add[]" value="<?= (int)$i ?>"> <?= h(A('dodaj')) ?></label><?php else: ?><span class="faint">–</span><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="panel-body small muted" style="border-top:1px solid var(--line-2)">
    <p style="margin:0 0 10px"><b><?= h(A('Uvoz na routerje ne pošlje ničesar')) ?></b> – <?= h(A('obstoječi peerji ostanejo, kot so; NOC si samo zapomni ključe za prikaz configa. Na router gredo samo uporabniki, ki jih označiš z "dodaj", in vsi, ki jih boš odslej dodal v NOC.')) ?></p>
    <p style="margin:0 0 6px"><b><?= h(A('na routerju – uvozi')) ?>:</b> <?= h(A('peer obstaja na routerju; NOC shrani njegov ključ, da lahko kadar koli ponovno izdela config ali QR kodo. Uporabnik ne opazi ničesar.')) ?></p>
    <p style="margin:0 0 6px"><b><?= h(A('ni na routerju')) ?>:</b> <?= h(A('uporabnik je v zgodovini, na routerju pa ga NOC ne vidi. Brez kljukice se na router NE doda – ključ se shrani in poveže sam, ko ga router pokaže. S kljukico "dodaj" ga NOC doda na router.')) ?></p>
    <p style="margin:0"><b><?= h(A('router ni v NOC')) ?>:</b> <?= h(A('router s tem javnim ključem ni v NOC ali še ni poslal podatkov z novo skripto. Ključi se shranijo šifrirano in se uporabnikom samodejno povežejo, ko router dodaš v NOC – na router se nič ne pošlje.')) ?></p>
  </div>
  <div class="panel-body actions" style="border-top:1px solid var(--line-2)">
    <button class="btn primary" <?= ($st['match'] ?? 0) + ($st['notonrouter'] ?? 0) + ($st['nodevice'] ?? 0) ? '' : 'disabled' ?>><?= icon('check', 15) ?><?= h(A('Uvozi')) ?></button>
    <a class="btn" href="/wireguard"><?= h(A('Prekliči')) ?></a>
  </div>
</section>
</form>
