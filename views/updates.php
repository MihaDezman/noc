<?php
$need = 0;
foreach ($devs as $d) { $u = $d['status']['upd'] ?? []; if (!empty($u['latest']) && !empty($u['inst']) && version_compare((string)$u['latest'], (string)$u['inst'], '>')) $need++; }
?>
<div class="page-head"><div><h1><?= h(A('Posodobitve')) ?></h1><p><?= h(A('RouterOS, firmware in skripta NOC na vseh napravah. Podatek o novi verziji RouterOS router preveri ob nočnem backupu.')) ?></p></div>
  <?php if ($need): ?><span class="tag info"><?= icon('refresh', 13) ?><?= h(A('{n} za posodobitev', ['n' => $need])) ?></span><?php endif; ?></div>
<section class="panel">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Naprava')) ?></th><th><?= h(A('Model')) ?></th><th>RouterOS</th><th><?= h(A('Na voljo')) ?></th><th><?= h(A('Kanal')) ?></th><th>Firmware</th><th><?= h(A('Arhitektura')) ?></th><th><?= h(A('Skripta NOC')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($devs as $d): $u = $d['status']['upd'] ?? []; $rb = $d['status']['rb'] ?? [];
        $newer = !empty($u['latest']) && !empty($u['inst']) && version_compare((string)$u['latest'], (string)$u['inst'], '>');
        $fwUp = (string)($rb['fwUp'] ?? ''); $fwNeed = $fwUp !== '' && $d['firmware'] !== '' && $fwUp !== $d['firmware']; ?>
      <tr>
        <td class="nowrap"><a class="rowlink" href="/devices/<?= (int)$d['id'] ?>"><?= h($d['name']) ?></a><div class="small muted"><?= h($d['tenant_name'] ?: '–') ?></div></td>
        <td class="nowrap"><?= h($d['model'] ?: '–') ?></td>
        <td class="mono nowrap"><?= h(preg_replace('/\s.*$/', '', (string)$d['os_version']) ?: '–') ?></td>
        <td class="nowrap"><?= $newer ? '<span class="tag info">' . h($u['latest']) . '</span>' : (!empty($u['latest']) ? '<span class="tag up">' . h(A('najnovejša')) . '</span>' : '<span class="faint small">' . h(A('še ni podatka')) . '</span>') ?></td>
        <td class="muted"><?= h($u['ch'] ?? '–') ?></td>
        <td class="mono nowrap"><?= h($d['firmware'] ?: '–') ?><?= $fwNeed ? ' <span class="tag warn" title="' . h(A('Po posodobitvi RouterOS: /system routerboard upgrade in ponovni zagon')) . '">→ ' . h($fwUp) . '</span>' : '' ?></td>
        <td class="muted"><?= h($d['arch'] ?: '–') ?></td>
        <td class="nowrap"><?php [$scCls, $scTxt] = MikrotikScript::scriptState($d); ?><span class="tag <?= $scCls ?>"><?= h($scTxt) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="panel-body small muted" style="border-top:1px solid var(--line-2)"><?= icon('info', 14) ?> <?= h(A('Posodobitev na routerju: /system package update install – nato /system routerboard upgrade in ponovni zagon, da se posodobi še firmware.')) ?></div>
</section>
