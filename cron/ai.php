<?php
// AI pomočnik – zažene ga cron/minute.php v ozadju (ne rabi vrstice v crontabu); klici AI ne zadržujejo pingov in obvestil
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-ai.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
if (!Ai::on()) exit;
$out = [];
foreach ([fn() => Ai::explainAlerts(), fn() => Ai::reviewConfigs(), fn() => (int)date('i') % 15 === 7 ? Ai::watch() : '', fn() => Ai::digest(), fn() => Ai::incidents(), fn() => Ai::reviews()] as $job) {
    try { $r = $job(); if ($r !== '') $out[] = $r; }
    catch (\Throwable $e) { error_log('noc ai: ' . $e->getMessage()); $out[] = 'napaka: ' . $e->getMessage(); }
}
cron_mark('ai', implode(', ', $out));
