<?php
$need = 0; $ready = 0; $ust = []; $nst = []; $ntpOff = 0;
foreach ($devs as $d) {
    $ust[(int)$d['id']] = $us = (int)($d['active'] ?? 1) ? Actions::upgradeState($d) : ['current', ''];
    if ($us[0] !== 'current') $need++;
    if ($us[0] === 'ok') $ready++;
    $nst[(int)$d['id']] = $ns = (int)($d['active'] ?? 1) ? Actions::ntpState($d) : 'unknown';
    if ($ns === 'off') $ntpOff++;
}
?>
<div class="page-head"><div><h1><?= h(A('Posodobitve')) ?></h1><p><?= h(A('RouterOS, firmware in skripta NOC na vseh napravah. Podatek o novi verziji RouterOS router preveri ob nočnem backupu.')) ?></p></div>
  <div class="actions"><?php if ($need): ?><span class="tag info"><?= icon('refresh', 13) ?><?= h(A('{n} za posodobitev', ['n' => $need])) ?></span><?php endif; ?>
  <?php if ($ready): ?><form method="post" action="/updates/upgrade-all" data-confirm="<?= h(A('Naprave ({n}) ob 03:30 namestijo novo verzijo RouterOS in se ponovno zaženejo (2–5 minut brez interneta). Nadaljujem?', ['n' => $ready])) ?>"><?= Auth::csrf() ?><button class="btn primary sm"><?= icon('clock', 14) ?><?= h(A('Posodobi vse ponoči ob 03:30 ({n})', ['n' => $ready])) ?></button></form><?php endif; ?>
  <?php if ($ntpOff): ?><form method="post" action="/updates/ntp-all" data-confirm="<?= h(A('Na napravah z izklopljenim NTP ({n}) vklopim NTP odjemalca (strežnika si.pool.ntp.org in europe.pool.ntp.org). Nadaljujem?', ['n' => $ntpOff])) ?>"><?= Auth::csrf() ?><button class="btn sm"><?= icon('clock', 14) ?><?= h(A('Vklopi NTP na vseh ({n})', ['n' => $ntpOff])) ?></button></form><?php endif; ?></div></div>
<section class="panel">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><?= h(A('Naprava')) ?></th><th><?= h(A('Model')) ?></th><th>RouterOS</th><th><?= h(A('Na voljo')) ?></th><th><?= h(A('Kanal')) ?></th><th>Firmware</th><th><?= h(A('Arhitektura')) ?></th><th><?= h(A('Skripta NOC')) ?></th><th>NTP</th></tr></thead>
    <tbody>
    <?php foreach ($devs as $d): $u = $d['status']['upd'] ?? []; $rb = $d['status']['rb'] ?? [];
        $newer = !empty($u['latest']) && !empty($u['inst']) && version_compare((string)$u['latest'], (string)$u['inst'], '>');
        $fwUp = (string)($rb['fwUp'] ?? ''); $fwNeed = $fwUp !== '' && $d['firmware'] !== '' && $fwUp !== $d['firmware']; ?>
      <tr>
        <td class="nowrap"><a class="rowlink" href="/devices/<?= (int)$d['id'] ?>"><?= h($d['name']) ?></a><div class="small muted"><?= h($d['tenant_name'] ?: '–') ?></div></td>
        <td class="nowrap"><?= h($d['model'] ?: '–') ?></td>
        <td class="mono nowrap"><?= h(preg_replace('/\s.*$/', '', (string)$d['os_version']) ?: '–') ?></td>
        <td class="nowrap"><?= $newer ? '<span class="tag info">' . h($u['latest']) . '</span>' : (!empty($u['latest']) ? '<span class="tag up">' . h(A('najnovejša')) . '</span>' : '<span class="faint small">' . h(A('še ni podatka')) . '</span>') ?>
          <?php $us = $ust[(int)$d['id']][0] ?? 'current'; if ($us !== 'current'): ?><div class="small <?= $us === 'norbt' ? '' : 'muted' ?>" style="<?= $us === 'norbt' ? 'color:var(--warn)' : '' ?>"><?= h(['ok' => A('pripravljen'), 'pending' => A('načrtovano ob 03:30'), 'norbt' => A('najprej ročna namestitev (pravica reboot)'), 'offline' => A('ni na zvezi')][$us]) ?></div><?php endif; ?></td>
        <td class="muted"><?= h($u['ch'] ?? '–') ?></td>
        <td class="mono nowrap"><?= h($d['firmware'] ?: '–') ?><?= $fwNeed ? ' <span class="tag warn" title="' . h(A('Po posodobitvi RouterOS: /system routerboard upgrade in ponovni zagon')) . '">→ ' . h($fwUp) . '</span>' : '' ?></td>
        <td class="muted"><?= h($d['arch'] ?: '–') ?></td>
        <td class="nowrap"><?php [$scCls, $scTxt] = MikrotikScript::scriptState($d); ?><span class="tag <?= $scCls ?>"><?= h($scTxt) ?></span></td>
        <td class="nowrap"><?php $ns = $nst[(int)$d['id']] ?? 'unknown'; ?><?= $ns === 'unknown' ? '<span class="faint small">' . h(A('še ni podatka')) . '</span>'
          : '<span class="tag ' . ['ok' => 'up', 'off' => 'warn', 'nosync' => 'warn', 'pending' => 'info'][$ns] . '">' . h(['ok' => A('v redu'), 'off' => A('izklopljen'), 'nosync' => A('ni sinhroniziran'), 'pending' => A('čaka na router')][$ns]) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$devs): ?><div class="empty"><?= h(A('Še ni dodanih naprav.')) ?></div><?php endif; ?>
  <div class="panel-body small muted" style="border-top:1px solid var(--line-2)"><?= icon('info', 14) ?> <?= h(A('Posodobitev na routerju: /system package update install – nato /system routerboard upgrade in ponovni zagon, da se posodobi še firmware.')) ?></div>
</section>
