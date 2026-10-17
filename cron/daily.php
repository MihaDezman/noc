<?php
// 15 3 * * *  – dnevni povzetki, brisanje starih podatkov, osvežitev varnostnih podatkov
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
// vsak korak posebej: napaka v enem ne prepreči ostalih (in mesečnih poročil)
$step = function (string $k, callable $fn) { try { return (string)($fn() ?? ''); } catch (\Throwable $e) { error_log("noc daily $k: " . $e->getMessage()); return "$k: napaka"; } };
$step('rollup', fn() => Metrics::rollup());
$step('enroll', fn() => Enroll::cleanup());
$bk = $step('keys', fn() => Ingest::stripKeysOnce());
$drop = $step('drop', fn() => Filter::updateDropList());
$out = [];
foreach (['threat' => fn() => Threat::update(), 'geo' => fn() => Geo::update(), 'ros' => fn() => Ros::refresh(), 'score' => fn() => Score::refreshAll(), 'wg' => fn() => Wg::weeklyInactive(), 'profile' => fn() => Profile::reclassify(), 'term' => fn() => Term::purge(), 'aiapply' => fn() => AiApply::purge()] as $k => $job) {
    try { $r = $job(); if ($r !== '') $out[] = $r; } catch (\Throwable $e) { error_log("noc daily $k: " . $e->getMessage()); $out[] = "$k: napaka"; }
}
$info = $step('report', fn() => Report::monthlyCron());   // od 1. do 5. v mesecu: poročila za prejšnji mesec (če še niso poslana)
cron_mark('daily', trim($info . ' ' . $drop . ' ' . $bk . ' ' . implode(', ', $out)));
