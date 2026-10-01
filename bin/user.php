<?php
// Uporabnik iz ukazne vrstice (prvi superadmin, ponastavitev gesla):
//   php bin/user.php add miha@dezman.net "Miha Dežman"     -> vpraša za geslo, ustvari superadmina
//   php bin/user.php passwd miha@dezman.net                -> novo geslo
//   php bin/user.php list
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/bootstrap.php';

function ask_pass(): string {
    echo 'Geslo (vsaj 10 znakov): '; system('stty -echo'); $p = trim((string)fgets(STDIN)); system('stty echo'); echo "\n";
    echo 'Ponovi geslo: '; system('stty -echo'); $p2 = trim((string)fgets(STDIN)); system('stty echo'); echo "\n";
    if ($p !== $p2) exit("Gesli se ne ujemata.\n");
    if (strlen($p) < 10) exit("Geslo je prekratko.\n");
    return $p;
}
$cmd = $argv[1] ?? ''; $email = mb_strtolower($argv[2] ?? '');
switch ($cmd) {
    case 'add':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Uporaba: php bin/user.php add email \"Ime Priimek\"\n");
        $p = ask_pass();
        db()->prepare('INSERT INTO users (email, name, pass_hash, role, lang) VALUES (?, ?, ?, "superadmin", "sl")')->execute([$email, $argv[3] ?? '', password_hash($p, PASSWORD_DEFAULT)]);
        echo "Superadmin $email ustvarjen.\n"; break;
    case 'passwd':
        $p = ask_pass();
        $st = db()->prepare('UPDATE users SET pass_hash=?, active=1 WHERE email=?'); $st->execute([password_hash($p, PASSWORD_DEFAULT), $email]);
        echo $st->rowCount() ? "Geslo spremenjeno.\n" : "Uporabnik ne obstaja.\n";
        db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]); break;
    case 'list':
        foreach (db()->query('SELECT id, email, name, role, active FROM users') as $u) printf("%3d  %-30s %-25s %-10s %s\n", $u['id'], $u['email'], $u['name'], $u['role'], $u['active'] ? '' : '(onemogočen)');
        break;
    default: echo "Uporaba: php bin/user.php add|passwd|list ...\n";
}
