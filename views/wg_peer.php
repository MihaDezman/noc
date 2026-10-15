<?php /** @var array $d @var array $r @var ?string $conf */
$id = (int)$d['id']; [$sc, $sl] = Wg::statusLabel($r);
$rp = Wg::routerPeers($d)[$r['pubkey']] ?? null; $hs = $rp ? ros_seconds((string)($rp['hs'] ?? '')) : null;
$deleting = in_array($r['status'], ['delete', 'delfail'], true); ?>
<div class="page-head">
  <div><div class="crumbs"><a href="/wireguard">WireGuard</a><?= icon('chev', 14) ?><a href="/devices/<?= $id ?>?tab=wg"><?= h($d['name']) ?></a><?= icon('chev', 14) ?></div>
  <h1><?= h($r['name'] ?: $r['ip']) ?></h1>
  <p><span class="tag <?= $sc ?>"><?= h($sl) ?></span><?= !(int)$r['enabled'] ? ' <span class="tag">' . h(A('izklopljen')) . '</span>' : '' ?>
    · <span class="mono"><?= h($r['ip']) ?></span> · <?= h($r['iface']) ?>
    · <?= $hs === null ? h(A('še ni bil povezan')) : h(A('zadnja povezava pred {t}', ['t' => fmt_uptime($hs)])) ?></p></div>
</div>

<?php if ($r['status'] === 'sync' && $r['old_pubkey']): ?>
<div class="flash warn"><?= icon('clock', 15) ?> <?= h(A('Nov ključ čaka, da ga router prevzame (ob naslednjem pushu). Do takrat deluje še stari config.')) ?></div>
<?php endif; ?>

<div class="grid g2">
<section class="panel">
  <div class="panel-head"><h2><?= icon($r['kind'] === 'pc' ? 'laptop' : 'smartphone', 18) ?><?= h(A('Config za uporabnika')) ?></h2></div>
  <?php if ($conf === null): ?>
  <div class="panel-body">
    <p class="small muted"><?= h(A('Za tega uporabnika NOC nima zasebnega ključa (peer je bil dodan ročno ali pred NOC), zato configa ni mogoče izdelati.')) ?></p>
    <p class="small muted"><?= h(A('Možnosti: uvozi ključe iz WireGuard Generatorja (stran WireGuard) ali spodaj ustvari nov ključ – uporabnik mora nato uvoziti nov config.')) ?></p>
  </div>
  <?php else: ?>
  <div class="panel-body wg-conf">
    <div class="wg-qr" id="wg-qr" data-conf="<?= h($conf) ?>" aria-label="<?= h(A('QR koda za aplikacijo WireGuard')) ?>"></div>
    <p class="small muted"><?= h(A('Telefon: v aplikaciji WireGuard izberi + → Skeniraj QR kodo. Računalnik: prenesi datoteko in jo uvozi (Import tunnel from file).')) ?></p>
    <div class="actions">
      <a class="btn primary" href="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>/conf"><?= icon('download', 15) ?><?= h(A('Prenesi .conf')) ?></a>
      <button type="button" class="btn" data-copy="<?= h($conf) ?>"><?= icon('copy', 15) ?><?= h(A('Kopiraj config')) ?></button>
    </div>
    <details class="wg-text"><summary class="small"><?= h(A('Pokaži besedilo configa (vsebuje zasebni ključ)')) ?></summary><pre class="mono small"><?= h($conf) ?></pre></details>
  </div>
  <script src="/assets/vendor/qrcode.js"></script>
  <script<?= nonce() ?>>(function(){var el=document.getElementById('wg-qr');var q=qrcode(0,'M');q.addData(el.dataset.conf);q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:2});})();</script>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2><?= icon('edit', 18) ?><?= h(A('Nastavitve')) ?></h2></div>
  <?php if ($deleting): ?><div class="panel-body small muted"><?= h(A('Uporabnik se briše – nastavitev ni več mogoče spreminjati.')) ?></div><?php else: ?>
  <form class="panel-body form" method="post" action="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="edit">
    <label class="f"><span><?= h(A('Ime')) ?></span><input type="text" name="name" maxlength="100" value="<?= h($r['name']) ?>" required></label>
    <div class="row">
      <label class="f"><span><?= h(A('IP naslov uporabnika')) ?></span><input type="text" name="ip" class="mono" value="<?= h($r['ip']) ?>" required></label>
      <label class="f"><span><?= h(A('DNS strežniki')) ?></span><input type="text" name="dns" value="<?= h($r['dns'] ?: Wg::DEFAULT_DNS) ?>"></label>
    </div>
    <label class="f"><span><?= h(A('Promet skozi tunel')) ?></span><select name="route">
      <option value="keep"><?= h(A('Brez spremembe ({a})', ['a' => mb_strimwidth($r['client_allowed'], 0, 60, '…')])) ?></option>
      <option value="all"><?= h(A('Ves promet (tudi internet)')) ?></option><option value="lan"><?= h(A('Samo omrežje stranke (LAN)')) ?></option></select>
      <small><?= h(A('Velja za config; uporabnik mora uvoziti novega.')) ?></small></label>
    <div class="actions"><button class="btn primary"><?= icon('check', 15) ?><?= h(A('Shrani')) ?></button></div>
  </form>
  <?php endif; ?>
  <div class="panel-body" style="border-top:1px solid var(--line-2)">
    <dl class="kv small">
      <dt><?= h(A('Javni ključ')) ?></dt><dd class="mono"><?= h($r['pubkey']) ?></dd>
      <dt><?= h(A('Na routerju')) ?></dt><dd class="mono"><?= h($r['allowed'] ?: '–') ?></dd>
      <dt>Endpoint</dt><dd class="mono"><?= h(Wg::endpoint($d, $r['iface']) ?: '–') ?></dd>
      <?php if ($rp): ?><dt><?= h(A('Promet')) ?></dt><dd><?= h(fmt_bytes($rp['rx'] ?? 0)) ?> / <?= h(fmt_bytes($rp['tx'] ?? 0)) ?><?= ($rp['ep'] ?? '') !== '' ? ' · <span class="mono">' . h($rp['ep']) . '</span>' : '' ?></dd><?php endif; ?>
      <dt><?= h(A('Izvor')) ?></dt><dd><?= h(['noc' => A('dodan v NOC'), 'import' => A('uvožen iz generatorja'), 'router' => A('najden na routerju')][$r['source']] ?? $r['source']) ?> · <?= h(fmt_dt($r['created_at'])) ?></dd>
    </dl>
  </div>
  <div class="panel-body actions" style="border-top:1px solid var(--line-2)">
    <?php if (in_array($r['status'], ['missing', 'failed', 'delfail'], true)): ?>
    <form method="post" action="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="restore"><button class="btn"><?= icon('refresh', 15) ?><?= h($r['status'] === 'missing' ? A('Dodaj nazaj na router') : ($r['status'] === 'delfail' ? A('Poskusi znova izbrisati') : A('Poskusi znova'))) ?></button></form>
    <?php endif; ?>
    <?php if (!$deleting): ?>
    <form method="post" action="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="toggle"><button class="btn"><?= icon('power', 15) ?><?= h((int)$r['enabled'] ? A('Izklopi') : A('Vklopi')) ?></button></form>
    <form method="post" action="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>" data-confirm="<?= h(A('Ustvarim nov ključ? Stari config bo prenehal delovati, ko ga router prevzame – uporabnik mora uvoziti novega.')) ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="rotate"><button class="btn"><?= icon('key', 15) ?><?= h(A('Zamenjaj ključ')) ?></button></form>
    <form method="post" action="/devices/<?= $id ?>/wg/<?= (int)$r['id'] ?>" data-confirm="<?= h(A('Izbrišem uporabnika {n}? Router ga odstrani ob naslednjem pushu.', ['n' => $r['name']])) ?>"><?= Auth::csrf() ?><input type="hidden" name="op" value="delete"><button class="btn danger"><?= icon('trash', 15) ?><?= h(A('Izbriši')) ?></button></form>
    <?php endif; ?>
  </div>
</section>
</div>
