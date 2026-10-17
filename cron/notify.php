<?php
// Pošiljanje obvestil (e-pošta, Telegram) – zažene ga cron/minute.php v ozadju s svojim zaklepom,
// da počasen SMTP ali Telegram ne zamakne alarmov in pinga v minutnem krogu.
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/noc-notify.lock', 'ce');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;   // prejšnje pošiljanje še teče – vrsta počaka na naslednji krog
// teče približno 45 s: pošlje, kar čaka, in pobere še obvestila, ki jih v tem času ustvari minutni krog (izpadi, ping)
$sent = []; $end = time() + 45;
do {
    try { $s = Notify::flush(); } catch (\Throwable $e) { error_log('noc notify: ' . $e->getMessage()); $sent[] = 'napaka: ' . $e->getMessage(); break; }
    if ($s !== '') { $sent[] = $s; continue; }   // morda jih čaka še več (največ 300 naenkrat)
    sleep(3);
} while (time() < $end);
if ($sent) cron_mark('notify', 'zadnjič: ' . implode(' + ', $sent) . ' (' . date('d.m. H:i') . ')');
else db()->exec("INSERT INTO cron_runs (name, last_at, info) VALUES ('notify', NOW(), '') ON DUPLICATE KEY UPDATE last_at=NOW()");   // opis zadnjega pošiljanja ostane
