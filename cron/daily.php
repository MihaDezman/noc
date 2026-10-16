<?php
// 15 3 * * *  – dnevni povzetki, brisanje starih podatkov, osvežitev varnostnih podatkov
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
Metrics::rollup();
Enroll::cleanup();
$bk = Ingest::stripKeysOnce();
$drop = Filter::updateDropList();
$out = [];
foreach (['threat' => fn() => Threat::update(), 'geo' => fn() => Geo::update(), 'ros' => fn() => Ros::refresh(), 'score' => fn() => Score::refreshAll(), 'wg' => fn() => Wg::weeklyInactive(), 'profile' => fn() => Profile::reclassify(), 'term' => fn() => Term::purge(), 'aiapply' => fn() => AiApply::purge()] as $k => $job) {
    try { $r = $job(); if ($r !== '') $out[] = $r; } catch (\Throwable $e) { error_log("noc daily $k: " . $e->getMessage()); $out[] = "$k: napaka"; }
}
$info = Report::monthlyCron();   // 1. v mesecu: poročila za prejšnji mesec
cron_mark('daily', trim($info . ' ' . $drop . ' ' . $bk . ' ' . implode(', ', $out)));
