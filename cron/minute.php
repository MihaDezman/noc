<?php
// * * * * *  – tišina naprav, ping s strežnika
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-minute.lock', 'c');
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
$sent = trim($sent . ' ' . $flush());   // obvestila iz tega kroga (izpadi, ping)
cron_mark('minute', $sent !== '' ? 'obvestila: ' . $sent : '');
