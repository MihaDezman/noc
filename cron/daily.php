<?php
// 15 3 * * *  – dnevni povzetki, brisanje starih podatkov
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
Metrics::rollup();
cron_mark('daily');
