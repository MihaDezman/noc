<div class="page-head"><div><h1><?= h(A('Logi')) ?></h1><p><?= h(A('Logi vseh naprav na enem mestu – zadnjih 1000 zadetkov, hramba 90 dni.')) ?></p></div></div>
<section class="panel">
  <div class="panel-head">
    <form class="filters" method="get">
      <input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h(A('Išči v logih …')) ?>">
      <select name="d"><option value=""><?= h(A('Vse naprave')) ?></option><?php foreach ($devs as $dv): ?><option value="<?= (int)$dv['id'] ?>" <?= (int)($_GET['d'] ?? 0) === (int)$dv['id'] ? 'selected' : '' ?>><?= h($dv['name']) ?></option><?php endforeach; ?></select>
      <select name="sev"><option value=""><?= h(A('Vse stopnje')) ?></option><?php foreach (['critical', 'error', 'warning', 'info'] as $sv): ?><option value="<?= $sv ?>" <?= ($_GET['sev'] ?? '') === $sv ? 'selected' : '' ?>><?= h($sv) ?></option><?php endforeach; ?></select>
      <input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>" title="<?= h(A('Od datuma')) ?>">
      <button class="btn sm"><?= icon('search', 15) ?><?= h(A('Išči')) ?></button>
    </form>
  </div>
  <?php if (!$logs): ?><div class="empty"><?= empty_art('search') ?><div><?= h(A('Ni zapisov za izbrane filtre.')) ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl log-tbl"><tbody>
  <?php foreach ($logs as $l): ?><tr>
    <td class="nowrap muted"><?= h(date('d.m. H:i:s', strtotime($l['ts']))) ?></td>
    <td class="nowrap"><a href="/devices/<?= (int)$l['device_id'] ?>?tab=logs"><?= h($l['device_name']) ?></a></td>
    <td><?= sev_tag($l['severity']) ?></td><td class="small muted nowrap"><?= h($l['topics']) ?></td><td class="msg"><?= h($l['message']) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
