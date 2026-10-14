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

  <?php $fwAll = db()->query('SELECT COUNT(*) n, COALESCE(SUM(fwlog_enabled),0) on_ FROM devices WHERE active=1')->fetch(); ?>
  <section class="panel" id="fwlog"><div class="panel-head"><h2><?= icon('scroll', 18) ?><?= h(A('Varnostna analiza')) ?></h2>
    <div class="actions"><span class="small muted"><?= h(A('vklopljena pri {a} od {n} naprav', ['a' => (int)$fwAll['on_'], 'n' => (int)$fwAll['n']])) ?></span>
      <?php if ((int)$fwAll['on_'] < (int)$fwAll['n']): ?><button class="btn sm primary" form="fwlog-on" type="submit"><?= icon('zap', 14) ?><?= h(A('Vklopi pri vseh')) ?></button><?php endif; ?>
      <?php if ((int)$fwAll['on_'] > 0): ?><button class="btn sm" form="fwlog-off" type="submit"><?= icon('x', 14) ?><?= h(A('Izklopi pri vseh')) ?></button><?php endif; ?></div></div>
    <div class="panel-body form">
      <div class="row c3"><?= $num('th_fw_ports', A('Skeniranje: različnih portov'), A('v 15 min'), A('z istega naslova – dogodek (info)')) ?><?= $num('th_fw_hits', A('Vztrajni poskusi'), A('v 15 min'), A('z istega naslova – dogodek (info)')) ?><?= $num('th_fw_spike', A('Porast poskusov'), A('× običajno'), A('v zadnji uri glede na prejšnje dni – opozorilo')) ?></div>
      <div class="row c3"><?= $num('th_fw_spike_min', A('… vsaj'), A('poskusov/h'), A('pod tem porast ni alarm')) ?><?= $num('th_out_ips', A('Okužena naprava: ciljev na en port'), A('v 5 min'), A('TCP, brez 80/443 – opozorilo')) ?><?= $num('th_out_smtp', A('Spam: SMTP strežnikov'), A('v 5 min'), A('port 25 – opozorilo')) ?></div>
      <div class="row c3"><?= $num('th_out_bl', A('Varnostna lista: tokov'), A('v 15 min'), A('naprava v LAN-u – opozorilo')) ?></div>
      <p class="small muted" style="margin:0"><?= h(A('Skeniranje z interneta je vsakdanje, zato je samo dogodek (info) – na Telegram pride, če je tam nastavljeno "vse". Porast in sumljiva naprava v LAN-u sta opozorili. Isti napadalec ali naprava se javi največ enkrat na 6 ur.')) ?></p>
    </div></section>

  <?php $tlsAll = db()->query('SELECT COUNT(*) n, COALESCE(SUM(tls_check),0) on_, COALESCE(SUM(tls_check=1 AND tls_ok=0),0) bad FROM devices WHERE active=1')->fetch(); ?>
  <section class="panel" id="tls"><div class="panel-head"><h2><?= icon('lock', 18) ?><?= h(A('Preverjanje certifikata NOC')) ?></h2>
    <div class="actions"><span class="small muted"><?= h(A('vklopljeno pri {a} od {n} naprav', ['a' => (int)$tlsAll['on_'], 'n' => (int)$tlsAll['n']])) ?></span>
      <?php if ((int)$tlsAll['bad'] > 0): ?><span class="tag down"><?= h(A('ne zaupa: {n}', ['n' => (int)$tlsAll['bad']])) ?></span><?php endif; ?>
      <?php if ((int)$tlsAll['on_'] < (int)$tlsAll['n']): ?><button class="btn sm primary" form="tls-on" type="submit"><?= icon('lock', 14) ?><?= h(A('Vklopi pri vseh')) ?></button><?php endif; ?>
      <?php if ((int)$tlsAll['on_'] > 0): ?><button class="btn sm" form="tls-off" type="submit"><?= icon('x', 14) ?><?= h(A('Izklopi pri vseh')) ?></button><?php endif; ?></div></div>
    <div class="panel-body"><p class="small muted" style="margin:0"><?= h(A('Routerji prenesejo in uvozijo skripto za samodejno posodobitev samo s preverjenim certifikatom HTTPS. Push gre ob težavi s certifikatom še vedno skozi, router z napako pa je označen na strani Zaščita pri napravi.')) ?></p></div></section>

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
  <?php $aiSet = Ai::key() !== ''; [$aiN, $aiIn, $aiOut] = $aiSet ? Ai::usage() : [0, 0, 0]; ?>
  <section class="panel" id="ai"><div class="panel-head"><h2><?= icon('zap', 18) ?><?= h(A('AI pomočnik')) ?></h2>
    <div class="actions"><?= $aiSet ? '<span class="tag up">' . h(A('vklopljen')) . '</span>' : '<span class="tag">' . h(A('ni nastavljen')) . '</span>' ?>
      <?php if ($aiSet): ?><button class="btn sm" form="ai-test" type="submit"><?= icon('send', 14) ?><?= h(A('Preizkus')) ?></button><?php endif; ?></div></div>
    <div class="panel-body form">
      <p class="small muted" style="margin:0"><?= h(A('Claude (Anthropic) razloži alarme, pregleda nočne spremembe konfiguracije in bdi nad samim NOC. Samo bere in svetuje – na routerjih ali v NOC ne spremeni ničesar. Gesla in ključi se pred pošiljanjem odstranijo.')) ?></p>
      <div class="row c3">
        <label class="f"><span><?= h(A('API ključ')) ?></span><input type="password" name="ai_key" autocomplete="off" placeholder="<?= $aiSet ? h(A('shranjen – vpiši samo za zamenjavo')) : 'sk-ant-…' ?>"><small><?= h(A('console.anthropic.com → API Keys. Shranjen je šifriran.')) ?></small></label>
        <label class="f"><span><?= h(A('Model')) ?></span><select name="ai_model"><?php foreach (Ai::MODELS as $k => $l): ?><option value="<?= h($k) ?>" <?= Ai::model() === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><small><?= h(A('Sonnet: priporočeno · Haiku: cenejši · Opus: najzmogljivejši, najdražji')) ?></small></label>
        <label class="f"><span><?= h(A('Največ klicev na dan')) ?></span><input type="number" min="1" max="5000" name="ai_daily" value="<?= h(setting('ai_daily', '100')) ?>"><small><?= h(A('danes: {n} · 30 dni: {c} klicev, {t} žetonov', ['n' => $aiSet ? Ai::callsToday() : 0, 'c' => $aiN, 't' => number_format($aiIn + $aiOut, 0, ',', '.')])) ?></small></label>
      </div>
      <div class="row c3">
        <label class="f"><span><?= h(A('Ura povzetka')) ?></span><select name="ai_digest_hour"><?php for ($hh = 5; $hh <= 10; $hh++): ?><option value="<?= $hh ?>" <?= (int)setting('ai_digest_hour', '7') === $hh ? 'selected' : '' ?>><?= sprintf('%02d:00', $hh) ?></option><?php endfor; ?></select></label>
      </div>
      <div class="row c3">
        <label class="check"><input type="checkbox" name="ai_alerts" value="1" <?= setting('ai_alerts', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Razlaga alarmov (opozorila in kritični)')) ?></label>
        <label class="check"><input type="checkbox" name="ai_config" value="1" <?= setting('ai_config', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Pregled sprememb konfiguracije')) ?></label>
        <label class="check"><input type="checkbox" name="ai_watch" value="1" <?= setting('ai_watch', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Samonadzor NOC')) ?></label>
      </div>
      <div class="row c3">
        <label class="check"><input type="checkbox" name="ai_chat" value="1" <?= setting('ai_chat', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Asistent v Telegramu')) ?></label>
        <label class="check"><input type="checkbox" name="ai_digest" value="1" <?= setting('ai_digest', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Jutranji povzetek')) ?></label>
        <label class="check"><input type="checkbox" name="ai_tg" value="1" <?= setting('ai_tg', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Razlage pošlji tudi na Telegram')) ?></label>
      </div>
      <div class="row c3">
        <label class="check"><input type="checkbox" name="ai_incidents" value="1" <?= setting('ai_incidents', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Povzetek incidenta po koncu')) ?></label>
        <label class="check"><input type="checkbox" name="ai_report" value="1" <?= setting('ai_report', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Povzetek v mesečnem poročilu')) ?></label>
      </div>
      <div class="row c3">
        <?php if ($aiSet): ?><label class="check"><input type="checkbox" name="ai_key_clear" value="1"> <?= h(A('Odstrani ključ (izklopi AI)')) ?></label><?php endif; ?>
      </div>
    </div></section>
  <section class="panel"><div class="panel-head"><h2><?= icon('key', 18) ?><?= h(A('Upravljalni naslovi')) ?></h2></div><div class="panel-body form">
    <label class="f"><?= h(A('Tvoji statični IP naslovi')) ?><input type="text" name="mgmt_ips" value="<?= h(setting('mgmt_ips')) ?>" class="mono"><small><?= h(A('Pri popravkih požarnega zidu so ti naslovi na routerjih vedno dovoljeni, zato se ne moreš zakleniti. Ločeno z vejico, dovoljeni so tudi obsegi (npr. 109.123.4.0/24).')) ?></small></label>
  </div></section>
  <label class="check"><input type="checkbox" name="notify_resolved" value="1" <?= setting('notify_resolved', '1') === '1' ? 'checked' : '' ?>> <?= h(A('Obvesti tudi, ko se težava razreši')) ?></label>
  <div class="actions"><button class="btn primary"><?= icon('check', 16) ?><?= h(A('Shrani nastavitve')) ?></button></div>
</form>
<form id="fwlog-on" method="post" action="/settings/fwlog-all" data-confirm="<?= h(A('Vklopim varnostno analizo pri vseh napravah? Routerji dobijo pravila za beleženje s samodejno posodobitvijo.')) ?>"><?= Auth::csrf() ?><input type="hidden" name="on" value="1"></form>
<form id="fwlog-off" method="post" action="/settings/fwlog-all" data-confirm="<?= h(A('Izklopim varnostno analizo pri vseh napravah?')) ?>"><?= Auth::csrf() ?><input type="hidden" name="on" value="0"></form>
<form id="ai-test" method="post" action="/settings/ai-test"><?= Auth::csrf() ?></form>
<?php if (Ai::on()): $aiNotes = db()->query('SELECT * FROM ai_notes WHERE device_id IS NULL ORDER BY id')->fetchAll(); ?>
<section class="panel" id="ai-notes" style="margin-top:20px">
  <div class="panel-head"><h2><?= icon('file', 18) ?><?= h(A('Splošni zapiski za AI')) ?></h2><span class="small muted"><?= h(A('veljajo za vse naprave · zapiske posamezne naprave urediš pri napravi → Nastavitve')) ?></span></div>
  <?php if ($aiNotes): ?><ul class="feed"><?php foreach ($aiNotes as $nt): ?><li><span class="sev-bar info"></span><div class="what"><?= h($nt['note']) ?> <span class="small faint">· <?= h($nt['source'] === 'telegram' ? 'Telegram' : A('ročno')) ?>, <?= h(fmt_dt($nt['created_at'])) ?></span></div>
    <form method="post" action="/settings/note-del" class="when"><?= Auth::csrf() ?><input type="hidden" name="nid" value="<?= (int)$nt['id'] ?>"><button class="btn sm ghost" title="<?= h(A('Izbriši')) ?>"><?= icon('trash', 14) ?></button></form></li><?php endforeach; ?></ul><?php endif; ?>
  <form class="panel-body form" method="post" action="/settings/note"><?= Auth::csrf() ?>
    <div class="row" style="grid-template-columns:1fr auto"><label class="f"><span><?= h(A('Nov zapisek')) ?></span><input type="text" name="note" maxlength="500" placeholder="<?= h(A('npr. disk je pri tej napravi vedno okoli 96 % – to je normalno')) ?>"></label>
      <div style="align-self:end"><button class="btn"><?= icon('plus', 15) ?><?= h(A('Dodaj')) ?></button></div></div>
  </form>
</section>
<?php endif; ?>

<form id="tls-on" method="post" action="/settings/tls-all" data-confirm="<?= h(A('Vklopim preverjanje certifikata pri vseh napravah? Routerji dobijo novo skripto s samodejno posodobitvijo.')) ?>"><?= Auth::csrf() ?><input type="hidden" name="on" value="1"></form>
<form id="tls-off" method="post" action="/settings/tls-all" data-confirm="<?= h(A('Izklopim preverjanje certifikata pri vseh napravah?')) ?>"><?= Auth::csrf() ?><input type="hidden" name="on" value="0"></form>

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
