<?php
// */5 * * * *  – traffic-flow (nfcapd datoteke) -> promet po napravah v LAN-u
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-flows.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
sleep(20);   // nfcapd zapre datoteko ob polni petminutki
cron_mark('flows', Flows::process());
