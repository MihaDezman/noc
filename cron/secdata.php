<?php
// Ročni prvi prenos varnostnih podatkov (sicer jih osveži cron/daily.php):
//   sudo -u www-data php /var/www/noc/cron/secdata.php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
@set_time_limit(0);
foreach (['Varnostne liste' => fn() => Threat::update(), 'Države, omrežja, MAC' => fn() => Geo::update(true), 'RouterOS' => fn() => Ros::refresh(), 'Ocene' => fn() => Score::refreshAll()] as $k => $job) {
    echo str_pad($k, 24) . ' ... '; flush();
    try { echo $job(), "\n"; } catch (\Throwable $e) { echo 'NAPAKA: ', $e->getMessage(), "\n"; }
}
