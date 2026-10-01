<?php
// * * * * *  – tišina naprav, ping s strežnika
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-minute.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
Alerts::evaluateCron();
cron_mark('minute');
