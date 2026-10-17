<?php
// * * * * *  – tišina naprav, ping s strežnika
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-minute.lock', 'ce');   // e = close-on-exec: proces v ozadju (ai.php) zaklepa ne podeduje
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
// obvestila pošilja cron/notify.php v ozadju (svoj zaklep) – počasen SMTP ali Telegram ne zamakne tega kroga
// (pošlje obvestila iz pushov in sproti še ta, ki jih ustvari ta krog – izpadi, ping)
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/notify.php') . ' > /dev/null 2>&1 &');
try { Metrics::clockGuard(); } catch (\Throwable $e) { error_log('noc clock: ' . $e->getMessage()); }   // premik ure nazaj (konec poletnega časa)
try { Alerts::evaluateCron(); } catch (\Throwable $e) { error_log('noc evaluateCron: ' . $e->getMessage()); }
try { $inc = Incidents::sync(); } catch (\Throwable $e) { error_log('noc incidents: ' . $e->getMessage()); $inc = ''; }   // povezani alarmi -> incident
try { Ufw::write(); } catch (\Throwable $e) { error_log('noc ufw: ' . $e->getMessage()); }   // seznam IP-jev za traffic-flow (ufw-sync.sh ga uveljavi vsakih 5 min)
// varnostna analiza: zapisi požarnega zidu (rsyslog) -> fw_5m, analiza vsakih 5 min
if (db()->query('SELECT COUNT(*) FROM devices WHERE active=1 AND fwlog_enabled=1')->fetchColumn()) {
    try {
        $fw = FwLog::ingest();
        if ((int)date('i') % 5 === 3) $fw .= ', ' . FwLog::inbound();
        cron_mark('fwlog', $fw);
    } catch (\Throwable $e) { error_log('noc fwlog: ' . $e->getMessage()); cron_mark('fwlog', 'napaka: ' . $e->getMessage()); }
}
if ((int)date('i') === 7) { try { Alerts::backups(); Alerts::serverBackup(); } catch (\Throwable $e) { error_log('noc backups: ' . $e->getMessage()); } }   // enkrat na uro
try { Speed::nightly(); } catch (\Throwable $e) { error_log('noc speed: ' . $e->getMessage()); }   // nočne meritve hitrosti (04:00–05:59)
// AI pomočnik v ozadju (svoj zaklep) – počasni klici AI ne zamaknejo naslednjega kroga
if (Ai::on()) exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/ai.php') . ' > /dev/null 2>&1 &');
// meni ukazov Telegram bota osveži po posodobitvi NOC (novi ukazi, npr. /povzetek)
if (setting('tg_webhook') === '1' && setting('tg_cmds_ver') !== APP_VERSION) { try { TgBot::commands(); } catch (\Throwable $e) { error_log('noc tg commands: ' . $e->getMessage()); } }
cron_mark('minute');
