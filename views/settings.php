<?php
$lv = ['info' => A('vse (tudi info)'), 'warning' => A('opozorila in kritični'), 'critical' => A('samo kritični'), 'off' => A('izklopljeno')];
$tgSet = setting('tg_token_enc') !== '';
$num = function (string $k, string $label, string $unit, string $hint = '') {
    return '<label class="f"><span>' . h($label) . ' <small>(' . h($unit) . ')</small></span><input type="number" step="any" min="0" name="' . $k . '" value="' . h(setting($k)) . '">' . ($hint ? '<small>' . h($hint) . '</small>' : '') . '</label>';
};
?>
<div class="page-head"><div><h1><?= h(A('Alarmi in obvestila')) ?></h1><p><?= h(A('Globalni pragovi veljajo za vse naprave; pri posamezni napravi jih lahko povoziš.')) ?></p></div></div>
<form class="form" method="post">
  <?= Auth::csrf() ?>
  <div class="grid g2">
    <section class="panel"><div class="panel-head"><h2><?= icon('cpu', 18) ?><?= h(A('Naprava')) ?></h2></div><div class="panel-body form">
      <div class="row"><?= $num('th_cpu', 'CPU', '%') ?><?= $num('th_cpu_min', A('CPU vztrajno'), 'min') ?></div>
      <div class="row"><?= $num('th_temp', A('Temperatura'), '°C', A('+10 °C nad pragom = kritično')) ?><?= $num('th_mem', A('Pomnilnik'), '%') ?><?= $num('th_hdd', A('Disk'), '%') ?></div>
      <div class="row"><?= $num('th_offline_min', A('Nedosegljiva po'), 'min', A('Brez pusha – nato preveri ping s strežnika')) ?></div>
    </div></section>
    <section class="panel"><div class="panel-head"><h2><?= icon('ping', 18) ?><?= h(A('Povezava')) ?></h2></div><div class="panel-body form">
      <div class="row"><?= $num('th_gw_loss', A('Izguba do prehoda'), '%') ?><?= $num('th_gw_ms', A('Latenca do prehoda'), 'ms') ?></div>
      <div class="row"><?= $num('th_ext_loss', A('Izguba do interneta'), '%') ?><?= $num('th_ext_ms', A('Latenca do interneta'), 'ms') ?></div>
      <div class="row"><?= $num('th_ping_min', A('Vztrajno'), 'min', A('Koliko minut zapored mora prag veljati')) ?></div>
    </div></section>
  </div>
  <div class="grid g2">
  <section class="panel"><div class="panel-head"><h2><?= icon('laptop', 18) ?><?= h(A('Naprave v LAN-u (traffic-flow)')) ?></h2></div><div class="panel-body form">
    <div class="row"><?= $num('th_host_gb_h', A('Prenos v zadnji uri'), 'GB') ?><?= $num('th_host_mbps', A('Povprečna hitrost'), 'Mb/s') ?><?= $num('th_host_min', A('… v zadnjih'), 'min') ?></div>
  </div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('shield', 18) ?><?= h(A('Varnost in vzdrževanje')) ?></h2></div><div class="panel-body form">
    <div class="row"><?= $num('th_attack', A('Napad: neuspelih prijav'), A('št.'), A('z istega IP-ja')) ?><?= $num('th_attack_min', A('… v'), 'min') ?></div>
    <div class="row"><?= $num('th_pool', A('Zasedenost IP poola'), '%', A('poln pool = kritično')) ?><?= $num('th_backup_days', A('Brez backupa konfiguracije'), A('dni')) ?></div>
  </div></section>
  </div>

  <div class="grid g2">
    <section class="panel"><div class="panel-head"><h2><?= icon('mail', 18) ?><?= h(A('E-pošta')) ?></h2></div><div class="panel-body form">
      <label class="f"><?= h(A('Prejemniki')) ?><input type="text" name="notify_emails" value="<?= h(setting('notify_emails')) ?>" placeholder="miha@dezman.net"><small><?= h(A('Več naslovov loči z vejico. SMTP je v config.php.')) ?></small></label>
      <label class="f"><?= h(A('Pošlji')) ?><select name="notify_mail_min"><?php foreach ($lv as $k => $l): ?><option value="<?= $k ?>" <?= setting('notify_mail_min', 'warning') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
    </div></section>
    <section class="panel" id="telegram"><div class="panel-head"><h2><?= icon('send', 18) ?>Telegram</h2><?= $tgSet ? '<span class="tag up">' . h(A('nastavljen')) . '</span>' : '<span class="tag">' . h(A('ni nastavljen')) . '</span>' ?></div><div class="panel-body form">
      <label class="f"><?= h(A('Token bota')) ?><input type="password" name="tg_token" autocomplete="off" placeholder="<?= $tgSet ? h(A('shranjen – vpiši samo za zamenjavo')) : '123456789:AA…' ?>"><small><?= h(A('Od @BotFather. Shranjen je šifriran.')) ?></small></label>
      <label class="f"><?= h(A('Chat ID')) ?><input type="text" name="tg_chat_id" value="<?= h(setting('tg_chat_id')) ?>" class="mono">
        <small><?= h(A('Botu pošlji sporočilo, shrani token, nato klikni "Poišči chat ID".')) ?></small></label>
      <?php if ($chats): ?><div class="note"><?= icon('info', 18) ?><div><?php foreach ($chats as $cid => $cn): ?><div><code><?= h($cid) ?></code> – <?= h($cn) ?> <button type="button" class="btn sm ghost" data-fill="tg_chat_id" data-value="<?= h($cid) ?>"><?= h(A('Uporabi')) ?></button></div><?php endforeach; ?></div></div><?php endif; ?>
      <label class="f"><?= h(A('Pošlji')) ?><select name="notify_tg_min"><?php foreach ($lv as $k => $l): ?><option value="<?= $k ?>" <?= setting('notify_tg_min', 'warning') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <?php if ($tgSet): ?><label class="check"><input type="checkbox" name="tg_token_clear" value="1"> <?= h(A('Odstrani token')) ?></label><?php endif; ?>
      <div class="note" style="margin-top:4px"><?= icon('send', 18) ?><div>
        <b><?= h(A('Ukazi v Telegramu')) ?></b> <?= setting('tg_webhook') === '1' ? '<span class="tag up">' . h(A('vklopljeni')) . '</span>' : '<span class="tag">' . h(A('izklopljeni')) . '</span>' ?>
        <div class="small muted"><?= h(A('/stanje, /alarmi, /naprava, /promet, /utisaj, /porocilo ter gumba Potrdi in Utišaj pri alarmih. Bot upošteva samo sporočila iz zgornjega chat ID-ja.')) ?></div>
      </div></div>
    </div></section>
  </div>
  <label class="check"><input type="checkbox" name="notify_resolved" value="1" <?= setting('notify_resolved', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Obvesti tudi, ko se težava razreši')) ?></label>
  <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani nastavitve')) ?></button></div>
</form>

<section class="panel" style="margin-top:20px"><div class="panel-head"><h2><?= icon('zap', 18) ?><?= h(A('Preizkus')) ?></h2></div><div class="panel-body actions">
  <form method="post" action="/settings/test"><?= Auth::csrf() ?><input type="hidden" name="what" value="mail"><button class="btn"><?= icon('mail', 16) ?><?= h(A('Testni e-mail')) ?></button></form>
  <form method="post" action="/settings/test"><?= Auth::csrf() ?><input type="hidden" name="what" value="tg_find"><button class="btn"><?= icon('search', 16) ?><?= h(A('Poišči chat ID')) ?></button></form>
  <form method="post" action="/settings/test"><?= Auth::csrf() ?><input type="hidden" name="what" value="tg"><button class="btn"><?= icon('send', 16) ?><?= h(A('Testno Telegram sporočilo')) ?></button></form>
  <?php if (setting('tg_webhook') === '1'): ?>
  <form method="post" action="/settings/test"><?= Auth::csrf() ?><input type="hidden" name="what" value="tg_hook_off"><button class="btn"><?= icon('x', 16) ?><?= h(A('Izklopi ukaze')) ?></button></form>
  <?php else: ?>
  <form method="post" action="/settings/test"><?= Auth::csrf() ?><input type="hidden" name="what" value="tg_hook_on"><button class="btn primary"><?= icon('zap', 16) ?><?= h(A('Vklopi ukaze v Telegramu')) ?></button></form>
  <?php endif; ?>
</div></section>
