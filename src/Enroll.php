<?php
declare(strict_types=1);

/**
 * Dodajanje routerja z enkratno kodo: v NOC ustvariš kodo (velja 30 min), na routerju en ukaz
 * prenese skripto, ta pošlje /export (v kosih po 30 kB), v NOC potrdiš predlog in naprava je dodana.
 */
final class Enroll
{
    public const TTL_MIN = 30;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';   // brez zamenljivih znakov (0/o, 1/l/i)

    /** [id, koda] */
    public static function create(): array
    {
        $code = '';
        for ($i = 0; $i < 12; $i++) $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        db()->prepare('INSERT INTO enroll_codes (code_hash, created_by, expires_at) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)')
            ->execute([hash('sha256', $code), Auth::$user['id'] ?? null, self::TTL_MIN]);
        return [(int)db()->lastInsertId(), $code];
    }

    /** Veljavna (neporabljena, nepotekla) koda ali null */
    public static function byCode(string $code): ?array
    {
        if (!preg_match('/^[a-z0-9]{12}$/', $code)) return null;
        $st = db()->prepare("SELECT * FROM enroll_codes WHERE code_hash=? AND expires_at > NOW() AND status IN ('new','receiving')");
        $st->execute([hash('sha256', $code)]);
        return $st->fetch() ?: null;
    }

    public static function get(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM enroll_codes WHERE id=?'); $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function oneLiner(string $code): string
    {
        $url = rtrim((string)cfg('base_url'), '/') . '/api/enroll/' . $code;
        return '/tool fetch url="' . $url . '" dst-path=noc-enroll-script.rsc; :delay 1s; /import noc-enroll-script.rsc; /file remove noc-enroll-script.rsc';
    }

    /** Skripta, ki jo router prenese in uvozi: /export -> NOC v kosih */
    public static function script(string $code): string
    {
        $url = rtrim((string)cfg('base_url'), '/') . '/api/enroll/' . $code;
        return <<<ROS
# NOC - posiljanje konfiguracije za dodajanje naprave (enkratna koda, velja 30 min)
:local url "$url"
:local f "noc-enroll.rsc"
:do { /file remove [find name=\$f] } on-error={}
/export terse file=noc-enroll
:delay 3s
:local size [/file get \$f size]
:local uid [:tostr [:rndnum from=100000000 to=999999999]]
:local off 0
:local part 0
:local ok true
:while (\$off < \$size) do={
    :local chunk ([/file read file=\$f offset=\$off chunk-size=30000 as-value]->"data")
    :onerror e in={
        /tool fetch url=\$url http-method=post http-data=\$chunk http-header-field=("Content-Type: text/plain,X-Upload: " . \$uid . ",X-Part: " . \$part . ",X-Size: " . \$size) output=none
    } do={ :log warning ("noc: posiljanje konfiguracije ni uspelo - " . \$e); :set ok false; :set off \$size }
    :set off (\$off + 30000)
    :set part (\$part + 1)
}
/file remove [find name=\$f]
:if (\$ok) do={ :log info "noc: konfiguracija poslana v NOC"; :put "noc: konfiguracija poslana - nadaljuj v brskalniku" }

ROS;
    }

    /** Kos /export z routerja; ko so vsi tu, je koda v stanju 'received' */
    public static function part(array $e, string $upload, int $part, int $size, string $data): array
    {
        $upload = preg_replace('/[^0-9a-zA-Z]/', '', $upload);
        if ($upload === '' || $part < 0 || $part > 500 || $size <= 0 || $size > 8 * 1024 * 1024) return ['error' => 'bad'];
        $pdo = db();
        $pdo->prepare('REPLACE INTO enroll_parts (enroll_id, upload_id, part, data) VALUES (?,?,?,?)')->execute([$e['id'], $upload, $part, $data]);
        $pdo->prepare("UPDATE enroll_codes SET status='receiving', router_ip=? WHERE id=?")->execute([client_ip(), $e['id']]);
        $st = $pdo->prepare('SELECT data FROM enroll_parts WHERE enroll_id=? AND upload_id=? ORDER BY part'); $st->execute([$e['id'], $upload]);
        $all = implode('', $st->fetchAll(PDO::FETCH_COLUMN));
        if (strlen($all) < $size) return ['ok' => true];
        $pdo->prepare("UPDATE enroll_codes SET status='received', export_raw=? WHERE id=?")->execute([str_replace("\r", '', $all), $e['id']]);
        $pdo->prepare('DELETE FROM enroll_parts WHERE enroll_id=?')->execute([$e['id']]);
        return ['ok' => true, 'done' => true];
    }

    public static function markUsed(int $id, int $deviceId): void
    {
        db()->prepare("UPDATE enroll_codes SET status='used', device_id=?, export_raw=NULL WHERE id=?")->execute([$deviceId, $id]);
    }

    public static function cleanup(): void
    {
        db()->exec('DELETE FROM enroll_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');
    }
}
