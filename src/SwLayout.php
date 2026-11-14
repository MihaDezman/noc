<?php
declare(strict_types=1);

/** Razporeditev portov stikala na sprednji plošči in barve VLAN-ov */
final class SwLayout
{
    private const PALETTE = ['#6b4fd8', '#0f9fb5', '#e8b100', '#d6394a', '#1d9a6c', '#e0679a', '#7a8cff', '#c97a2b', '#9bb33a', '#8a5cc2'];

    /** Stalna barva VLAN-a (enaka številka = enaka barva na vseh stikalih); VLAN 1 siv */
    public static function vlanColor(int $vid): string
    {
        return $vid === 1 ? '#9aa0b6' : self::PALETTE[($vid % 10 + intdiv($vid, 10)) % 10];   // 10, 20, 30 … dobijo različne barve
    }

    /** Kratka oznaka porta na plošči: ether5 -> 5, sfp-sfpplus1 -> S1, qsfp28-1-1 -> Q1, combo1 -> C1 */
    public static function label(string $port): string
    {
        if (preg_match('/^ether(\d+)$/', $port, $m)) return $m[1];
        if (preg_match('/^qsfp\D*(\d+)/', $port, $m)) return 'Q' . $m[1];
        if (preg_match('/^sfp\D*(\d+)$/', $port, $m)) return 'S' . $m[1];
        if (preg_match('/^combo(\d+)$/', $port, $m)) return 'C' . $m[1];
        return $port;
    }

    private static function num(string $p): int { return preg_match('/(\d+)(?!.*\d)/', $p, $m) ? (int)$m[1] : 0; }

    /**
     * Skupine portov: ether po številki v skupinah po 12 (dve vrsti, liho zgoraj), nato combo, SFP in QSFP.
     * Pri do 8 ethernet portih ena skupina; en sam ether (CRS305/309) v eni vrsti.
     * @return list<array{rows:int, ports:list<string>}>
     */
    public static function groups(string $model, array $ports): array
    {
        $eth = []; $combo = []; $sfp = []; $qsfp = []; $other = [];
        foreach ($ports as $p) {
            if (preg_match('/^ether\d+$/', $p)) $eth[] = $p;
            elseif (str_starts_with($p, 'combo')) $combo[] = $p;
            elseif (str_starts_with($p, 'qsfp')) $qsfp[] = $p;
            elseif (str_starts_with($p, 'sfp')) $sfp[] = $p;
            else $other[] = $p;
        }
        $by = fn(array $a) => (usort($a, fn($x, $y) => self::num($x) <=> self::num($y) ?: strcmp($x, $y)) ? $a : $a);
        $eth = $by($eth); $sfp = $by($sfp); $qsfp = $by($qsfp); $combo = $by($combo);
        $g = [];
        if (count($eth) === 1) $g[] = ['rows' => 1, 'ports' => $eth];
        elseif ($eth) foreach (array_chunk($eth, count($eth) <= 8 ? 8 : 12) as $ch) $g[] = ['rows' => 2, 'ports' => $ch];
        foreach ([$combo, $sfp, $qsfp, $other] as $x) if ($x) $g[] = ['rows' => count($x) > 2 ? 2 : 1, 'ports' => $x];
        return $g;
    }
}
