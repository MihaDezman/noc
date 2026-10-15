<?php /** @var array $peers @var array $devs @var string $sq */
$rpCache = [];
$rpOf = function (array $p) use (&$rpCache) { return $rpCache[$p['device_id']] ??= Wg::routerPeers($p); };
$srvDevs = array_filter($devs, fn($x) => Wg::serverIfaces($x)); ?>
<div class="page-head">
  <div><h1>WireGuard</h1><p><?= h(A('Uporabniki VPN na vseh routerjih. Novega dodaš pri napravi (zavihek WireGuard).')) ?></p></div>
  <?php if ($srvDevs): ?><div class="actions"><select data-nav-select aria-label="<?= h(A('Dodaj uporabnika na')) ?>"><option value=""><?= h(A('+ Dodaj uporabnika na …')) ?></option><?php foreach ($srvDevs as $x): ?><option value="/devices/<?= (int)$x['id'] ?>?tab=wg#wg-add"><?= h($x['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
</div>

<section class="panel">
  <div class="panel-head">
    <form class="filters" method="get"><input type="search" name="q" value="<?= h($sq) ?>" placeholder="<?= h(A('Išči ime, IP ali napravo …')) ?>"><button class="btn sm"><?= icon('search', 15) ?><?= h(A('Išči')) ?></button></form>
    <span class="small muted"><?= h(A('{n} uporabnikov', ['n' => count($peers)])) ?></span>
  </div>
  <?php if (!$peers): ?><div class="empty"><?= icon('lock', 28) ?><div><?= h($sq !== '' ? A('Ni zadetkov.') : A('Še ni WireGuard uporabnikov. Pokažejo se, ko routerji pošljejo podatke z novo skripto (samodejno v nekaj minutah).')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Ime')) ?></th><th><?= h(A('Naprava')) ?></th><th>IP</th><th><?= h(A('Stanje')) ?></th><th><?= h(A('Zadnja povezava')) ?></th><th>Config</th></tr></thead>
    <tbody><?php foreach ($peers as $p): [$sc, $sl] = Wg::statusLabel($p); $rp = $rpOf($p)[$p['pubkey']] ?? null; $hs = $rp ? ros_seconds((string)($rp['hs'] ?? '')) : null; ?><tr>
      <td><a class="rowlink" href="/devices/<?= (int)$p['device_id'] ?>/wg/<?= (int)$p['id'] ?>"><?= icon($p['kind'] === 'phone' ? 'smartphone' : ($p['kind'] === 'pc' ? 'laptop' : 'link'), 14, 'faint') ?> <?= h($p['name'] ?: '–') ?></a></td>
      <td><a href="/devices/<?= (int)$p['device_id'] ?>?tab=wg"><?= h($p['device_name']) ?></a> <span class="small faint mono"><?= h($p['iface']) ?></span></td>
      <td class="mono"><?= h($p['ip'] ?: $p['allowed']) ?></td>
      <td class="nowrap"><span class="tag <?= $sc ?>"><?= h($sl) ?></span><?= !(int)$p['enabled'] ? ' <span class="tag">' . h(A('izklopljen')) . '</span>' : '' ?></td>
      <td class="nowrap"><?= $hs === null ? '<span class="faint">' . h(A('nikoli')) . '</span>' : '<span class="tag ' . ($hs < 180 ? 'up' : ($hs < 900 ? 'warn' : '')) . '">' . h(A('pred {t}', ['t' => fmt_uptime($hs)])) . '</span>' ?></td>
      <td><?= $p['priv_enc'] ? '<span class="tag up">' . icon('key', 12) . h(A('na voljo')) . '</span>' : '<span class="tag">' . h(A('ni ključa')) . '</span>' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>

<section class="panel" id="import">
  <div class="panel-head"><h2><?= icon('upload', 18) ?><?= h(A('Uvoz iz WireGuard Generatorja')) ?></h2><span class="small muted"><?= h(A('enkratni prenos obstoječih uporabnikov')) ?></span></div>
  <form class="panel-body form" method="post" action="/wireguard/import" enctype="multipart/form-data"><?= Auth::csrf() ?>
    <p class="small muted" style="margin:0"><?= h(A('Naloži datoteko data/data.json s stare strani. NOC uporabnike poveže z routerji po javnih ključih in pred shranjevanjem pokaže pregled. Datoteka se ne shrani; zasebni ključi se shranijo šifrirano.')) ?></p>
    <label class="f"><span><?= h(A('Datoteka data.json')) ?></span><input type="file" name="file" accept=".json,application/json" required></label>
    <div class="actions"><button class="btn primary"><?= icon('search', 15) ?><?= h(A('Preglej datoteko')) ?></button></div>
  </form>
</section>
