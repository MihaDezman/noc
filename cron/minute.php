<?php
// * * * * *  – tišina naprav, ping s strežnika
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-minute.lock', 'ce');   // e = close-on-exec: proces v ozadju (ai.php) zaklepa ne podeduje
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
$flush = function () { try { return Notify::flush(); } catch (\Throwable $e) { error_log('noc notify: ' . $e->getMessage()); return 'napaka: ' . $e->getMessage(); } };
$sent = $flush();   // obvestila iz pushov zadnje minute
Alerts::evaluateCron();
Ufw::write();   // seznam IP-jev za traffic-flow (ufw-sync.sh ga uveljavi vsakih 5 min)
// varnostna analiza: zapisi požarnega zidu (rsyslog) -> fw_5m, analiza vsakih 5 min
if (db()->query('SELECT COUNT(*) FROM devices WHERE active=1 AND fwlog_enabled=1')->fetchColumn()) {
    try {
        $fw = FwLog::ingest();
        if ((int)date('i') % 5 === 3) $fw .= ', ' . FwLog::inbound();
        cron_mark('fwlog', $fw);
    } catch (\Throwable $e) { error_log('noc fwlog: ' . $e->getMessage()); cron_mark('fwlog', 'napaka: ' . $e->getMessage()); }
}
if ((int)date('i') === 7) { Alerts::backups(); Alerts::serverBackup(); }   // enkrat na uro
// AI pomočnik v ozadju (svoj zaklep) – počasni klici AI ne zamaknejo naslednjega kroga
if (Ai::on()) exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/ai.php') . ' > /dev/null 2>&1 &');
$sent = trim($sent . ' ' . $flush());   // obvestila iz tega kroga (izpadi, ping)
// meni ukazov Telegram bota osveži po posodobitvi NOC (novi ukazi, npr. /povzetek)
if (setting('tg_webhook') === '1' && setting('tg_cmds_ver') !== APP_VERSION) { try { TgBot::commands(); } catch (\Throwable $e) { error_log('noc tg commands: ' . $e->getMessage()); } }
cron_mark('minute', $sent !== '' ? 'obvestila: ' . $sent : '');
