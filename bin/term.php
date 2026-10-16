<?php
// Pomočnik za most terminala (bin/noc-term.py) – samo CLI:
//   php term.php check <žeton> <ip-brskalnika>   -> JSON s podatki za ssh ali {"error": …}
//   php term.php end <seja> <bajtov> <razlog>
//   php term.php keys                            -> ustvari ključa NOC (če ju ni)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
$cmd = $argv[1] ?? '';
try {
    if ($cmd === 'check') { echo json_encode(Term::check((string)($argv[2] ?? ''), (string)($argv[3] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit(0); }
    if ($cmd === 'end') { Term::end((int)($argv[2] ?? 0), (int)($argv[3] ?? 0), (string)($argv[4] ?? '')); exit(0); }
    if ($cmd === 'keys') { $e = Term::ensureKeys(); echo $e === '' ? "ok\n" . Term::fingerprint('ed') . "\n" . Term::fingerprint('rsa') . "\n" : "$e\n"; exit($e === '' ? 0 : 1); }
} catch (\Throwable $e) { echo json_encode(['error' => 'NOC: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE); exit(0); }
fwrite(STDERR, "uporaba: term.php check|end|keys\n"); exit(2);
