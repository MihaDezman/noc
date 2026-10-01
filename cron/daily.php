<?php
// 15 3 * * *  – dnevni povzetki, brisanje starih podatkov
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
Metrics::rollup();
Enroll::cleanup();
$drop = Filter::updateDropList();
$info = Report::monthlyCron();   // 1. v mesecu: poročila za prejšnji mesec
cron_mark('daily', trim($info . ' ' . $drop));
