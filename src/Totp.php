<?php
declare(strict_types=1);

/** TOTP (RFC 6238): 6 mest, 30 s, HMAC-SHA1 – Google Authenticator, Aegis, 2FAS, Microsoft Authenticator … */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $s = ''; foreach (str_split(random_bytes(32)) as $c) $s .= self::B32[ord($c) & 31];
        return $s;   // 32 znakov base32 = 160 bitov
    }

    private static function b32decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = ''; $out = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('N2', 0, $step), self::b32decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Časovni korak, ki se ujema (±1 korak za zamik ure), ali null. $after: zavrni korake <= zadnjega uporabljenega (ponovna uporaba). */
    public static function verify(string $secret, string $code, ?int $after = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return null;
        $now = intdiv(time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            $step = $now + $i;
            if ($after !== null && $step <= $after) continue;
            if (hash_equals(self::code($secret, $step), $code)) return $step;
        }
        return null;
    }

    public static function uri(string $secret, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode('NOC:' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode('NOC dezman.net') . '&digits=6&period=30';
    }
}
