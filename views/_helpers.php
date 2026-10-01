<?php
// Pomožne funkcije za prikaz (vključi _top.php in login.php)

function state_label(string $s): string {
    return match ($s) { 'up' => A('Deluje'), 'warn' => A('Opozorilo'), 'down' => A('Ne deluje'), default => A('Čaka na prvi push') };
}
function state_tag(array $d): string {
    $s = $d['state'];
    return '<span class="tag ' . h($s) . '"><span class="dot ' . h($s) . '"></span>' . state_label($s) . '</span>' . (!empty($d['muted']) ? ' <span class="tag" title="' . h(A('Obvestila utišana')) . '">' . icon('bell-off', 13) . '</span>' : '');
}
function sev_tag(string $sev): string {
    $l = ['critical' => A('Kritično'), 'warning' => A('Opozorilo'), 'error' => A('Napaka'), 'info' => A('Info')][$sev] ?? $sev;
    return '<span class="tag ' . h($sev) . '">' . h($l) . '</span>';
}
function kind_icon(string $kind): string { return match ($kind) { 'switch' => 'switch', 'ap' => 'wifi', 'other' => 'box', default => 'router' }; }

/** Vrata za sprednjo ploščo: [name, on, dis, sfp, wan] */
function face_ports(array $ifaces, string $wan, int $max = 26): array {
    $out = [];
    foreach (Devices::ports($ifaces) as $i) {
        $out[] = ['name' => $i['name'], 'on' => (bool)$i['running'], 'dis' => (bool)$i['disabled'], 'sfp' => str_contains($i['type'] . $i['name'], 'sfp'), 'wan' => $i['name'] === $wan, 'rate' => $i['rate'], 'comment' => $i['comment']];
        if (count($out) >= $max) break;
    }
    return $out;
}
function port_html(array $p): string {
    $cls = 'port' . ($p['on'] ? ' on' : '') . ($p['dis'] ? ' dis' : '') . ($p['sfp'] ? ' sfp' : '') . ($p['wan'] ? ' wan' : '');
    $t = $p['name'] . ($p['wan'] ? ' (WAN)' : '') . ($p['comment'] ? ' – ' . $p['comment'] : '') . ' · ' . ($p['dis'] ? A('izklopljen') : ($p['on'] ? ($p['rate'] ?: A('povezan')) : A('brez povezave')));
    return '<i class="' . $cls . '" title="' . h($t) . '"></i>';
}

/** Krožni merilnik (SVG) */
function gauge(?float $val, string $label, string $unit = '%', float $max = 100, float $warn = 75, float $crit = 90): string {
    $r = 30; $c = 2 * M_PI * $r; $p = $val === null ? 0 : max(0, min(1, $val / $max));
    $col = $val === null ? 'var(--line)' : ($val >= $crit ? 'var(--down)' : ($val >= $warn ? 'var(--warn)' : 'var(--violet)'));
    $txt = $val === null ? '–' : (fmod($val, 1.0) == 0.0 ? (int)$val : number_format($val, 1, ',', '')) . $unit;
    return '<div class="gauge"><svg width="76" height="76" viewBox="0 0 76 76" role="img" aria-label="' . h($label . ' ' . $txt) . '">'
        . '<circle cx="38" cy="38" r="' . $r . '" fill="none" stroke="var(--line-2)" stroke-width="7"/>'
        . '<circle cx="38" cy="38" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="7" stroke-linecap="round" stroke-dasharray="' . round($c * $p, 1) . ' ' . round($c, 1) . '" transform="rotate(-90 38 38)"/>'
        . '<text x="38" y="43" text-anchor="middle" font-size="14" font-weight="600" fill="var(--text)" font-family="Plex, sans-serif">' . h($txt) . '</text></svg>'
        . '<span>' . h($label) . '</span></div>';
}

/** Okrasne "optične" krivulje v barvah patch kablov */
function fibres_svg(string $class, int $w = 420, int $h = 240): string {
    $cols = ['#6b4fd8', '#0f9fb5', '#e8b100', '#d98200', '#6b4fd8'];
    $s = '<svg class="' . $class . '" width="' . $w . '" height="' . $h . '" viewBox="0 0 420 240" preserveAspectRatio="xMaxYMax slice" aria-hidden="true" fill="none">';
    foreach ($cols as $i => $c) {
        $y = 40 + $i * 34; $o = $i * 18;
        $s .= '<path d="M' . (-20) . ' ' . ($y + 120) . ' C ' . (120 + $o) . ' ' . ($y + 110) . ', ' . (170 - $o) . ' ' . ($y - 30) . ', ' . (440) . ' ' . ($y - 20) . '" stroke="' . $c . '" stroke-width="' . ($i === 2 ? 2.5 : 1.6) . '" stroke-opacity="' . (0.35 + $i * 0.1) . '"/>';
        $s .= '<circle cx="' . (440 - 4) . '" cy="' . ($y - 20) . '" r="3" fill="' . $c . '"/>';
    }
    return $s . '</svg>';
}

function brand_mark(int $s = 22): string {
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="2.6" fill="#e8b100"/>'
        . '<path d="M12 4.5a7.5 7.5 0 0 1 7.5 7.5" stroke="#6b4fd8" stroke-width="2.2" stroke-linecap="round"/>'
        . '<path d="M12 19.5A7.5 7.5 0 0 1 4.5 12" stroke="#0f9fb5" stroke-width="2.2" stroke-linecap="round"/>'
        . '<path d="M12 1.5A10.5 10.5 0 0 1 22.5 12M12 22.5A10.5 10.5 0 0 1 1.5 12" stroke="#b9bdd3" stroke-opacity=".35" stroke-width="1.4" stroke-linecap="round"/></svg>';
}

/** Graf: div, ki ga app.js napolni preko /ui/chart */
function chart(int $deviceId, string $kind, string $range = '24h', string $iface = '', int $height = 230): string {
    return '<div class="chart-box" data-chart data-d="' . $deviceId . '" data-k="' . h($kind) . '" data-r="' . h($range) . '" data-i="' . h($iface) . '" data-h="' . $height . '"><div class="chart-empty">' . h(A('Nalagam …')) . '</div></div>';
}
function range_seg(array $ranges, string $cur, string $target): string {
    $s = '<div class="seg" data-range-for="' . h($target) . '">';
    foreach ($ranges as $r => $l) $s .= '<button type="button" data-r="' . h($r) . '" class="' . ($r === $cur ? 'on' : '') . '">' . h($l) . '</button>';
    return $s . '</div>';
}
function pct_meter(?float $v, float $warn = 75, float $crit = 90): string {
    if ($v === null) return '<span class="faint">–</span>';
    $cls = $v >= $crit ? 'down' : ($v >= $warn ? 'warn' : '');
    return '<div class="meter ' . $cls . '" title="' . round($v) . ' %"><i style="width:' . max(2, min(100, $v)) . '%"></i></div>';
}

/** Ilustracija: tri rack omare z napravami, lučkami in patch kabli (prijavna stran) */
function racks_svg(): string {
    mt_srand(7);
    $W = 640; $H = 470;
    $s = '<svg class="racks-art" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Rack omare" xmlns="http://www.w3.org/2000/svg">';
    $s .= '<defs><linearGradient id="rkFloor" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6b4fd8" stop-opacity=".18"/><stop offset="1" stop-color="#6b4fd8" stop-opacity="0"/></linearGradient>'
        . '<linearGradient id="rkSide" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#20243a"/><stop offset="1" stop-color="#151827"/></linearGradient>'
        . '<radialGradient id="rkGlow" cx=".5" cy=".55" r=".6"><stop offset="0" stop-color="#6b4fd8" stop-opacity=".22"/><stop offset="1" stop-color="#6b4fd8" stop-opacity="0"/></radialGradient></defs>';
    $s .= '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="url(#rkGlow)"/>';
    $s .= '<ellipse cx="320" cy="438" rx="300" ry="26" fill="url(#rkFloor)"/>';
    $leds = ['#2ee59d', '#2ee59d', '#2ee59d', '#2ee59d', '#19c0d8', '#8b72ff', '#e8b100', '#2ee59d'];
    $cabs = [[60, 150], [235, 170], [410, 150]];   // x, širina
    foreach ($cabs as $ci => [$x, $w]) {
        $top = 40; $bot = 430; $d = 22;                                   // globina stranice
        $s .= '<polygon points="' . ($x + $w) . ',' . $top . ' ' . ($x + $w + $d) . ',' . ($top - 14) . ' ' . ($x + $w + $d) . ',' . ($bot - 14) . ' ' . ($x + $w) . ',' . $bot . '" fill="url(#rkSide)"/>';
        $s .= '<polygon points="' . $x . ',' . $top . ' ' . ($x + $d) . ',' . ($top - 14) . ' ' . ($x + $w + $d) . ',' . ($top - 14) . ' ' . ($x + $w) . ',' . $top . '" fill="#2b3048"/>';
        $s .= '<rect x="' . $x . '" y="' . $top . '" width="' . $w . '" height="' . ($bot - $top) . '" rx="3" fill="#232740" stroke="#343953"/>';
        $s .= '<rect x="' . ($x + 9) . '" y="' . ($top + 10) . '" width="' . ($w - 18) . '" height="' . ($bot - $top - 20) . '" fill="#141724"/>';
        // nosilni stebrički z luknjicami
        for ($y = $top + 16; $y < $bot - 14; $y += 9) { $s .= '<rect x="' . ($x + 11) . '" y="' . $y . '" width="3" height="3" fill="#2b3048"/><rect x="' . ($x + $w - 14) . '" y="' . $y . '" width="3" height="3" fill="#2b3048"/>'; }
        // naprave
        $y = $top + 16;
        while ($y < $bot - 40) {
            $u = [1, 1, 1, 2, 1, 2, 4][mt_rand(0, 6)]; $hgt = $u * 12 - 2;
            if ($y + $hgt > $bot - 18) break;
            $dx = $x + 18; $dw = $w - 36;
            $kind = mt_rand(0, 9);
            if ($kind < 2 && $u === 1) { $y += 12; continue; }          // prazna enota
            $s .= '<rect x="' . $dx . '" y="' . $y . '" width="' . $dw . '" height="' . $hgt . '" rx="1.5" fill="' . ($u >= 2 ? '#22263a' : '#1d2133') . '" stroke="#2f3450" stroke-width=".8"/>';
            if ($u === 1 && $kind >= 5) {                                    // stikalo: vrsta vrat
                $n = intdiv($dw - 30, 7);
                for ($p = 0; $p < $n; $p++) { $on = mt_rand(0, 3) > 0; $s .= '<rect x="' . ($dx + 6 + $p * 7) . '" y="' . ($y + 3) . '" width="5" height="4" fill="#0e101a" stroke="#3a3f58" stroke-width=".5"/>' . ($on ? '<circle cx="' . ($dx + 7.2 + $p * 7) . '" cy="' . ($y + 4.3) . '" r=".9" fill="' . $leds[mt_rand(0, 7)] . '"/>' : ''); }
            } else {                                                          // strežnik: reže diskov
                $bays = $u >= 2 ? 6 : 4;
                for ($b = 0; $b < $bays; $b++) $s .= '<rect x="' . ($dx + 6 + $b * 13) . '" y="' . ($y + 2.5) . '" width="11" height="' . ($hgt - 5) . '" rx="1" fill="#171a29" stroke="#30354e" stroke-width=".6"/><circle cx="' . ($dx + 9 + $b * 13) . '" cy="' . ($y + $hgt - 4.5) . '" r="1" fill="' . $leds[mt_rand(0, 7)] . '"/>';
            }
            $s .= '<circle cx="' . ($dx + $dw - 7) . '" cy="' . ($y + 5) . '" r="1.6" fill="' . ($kind === 9 ? '#e8b100' : '#2ee59d') . '"/>';
            $y += $u * 12;
        }
        // ime omare
        $s .= '<text x="' . ($x + $w / 2) . '" y="' . ($bot - 4) . '" fill="#5d627e" font-size="8" text-anchor="middle" font-family="PlexMono, monospace" letter-spacing="1">R' . ($ci + 1) . '</text>';
    }
    // patch kabli med omarami (barve optičnih kablov)
    foreach ([['#e8b100', 205, 70, 245, 92, 2], ['#19c0d8', 205, 118, 245, 160, 1.6], ['#8b72ff', 400, 84, 420, 120, 1.8], ['#e8b100', 400, 200, 420, 230, 1.6], ['#8b72ff', 205, 250, 245, 300, 1.6]] as [$c, $x1, $y1, $x2, $y2, $sw]) {
        $s .= '<path d="M' . $x1 . ' ' . $y1 . ' C ' . ($x1 + 24) . ' ' . ($y1 + 40) . ', ' . ($x2 - 24) . ' ' . ($y2 + 40) . ', ' . $x2 . ' ' . $y2 . '" fill="none" stroke="' . $c . '" stroke-width="' . $sw . '" stroke-opacity=".85" stroke-linecap="round"/>';
    }
    // kabelska polica nad omarami
    $s .= '<path d="M60 16 H 590" stroke="#343953" stroke-width="6" stroke-linecap="round"/>';
    foreach ([['#e8b100', 110], ['#19c0d8', 300], ['#8b72ff', 480]] as [$c, $cx]) $s .= '<path d="M' . $cx . ' 19 C ' . $cx . ' 30, ' . ($cx + 10) . ' 28, ' . ($cx + 14) . ' 38" fill="none" stroke="' . $c . '" stroke-width="1.6" stroke-opacity=".8"/>';
    mt_srand();
    return $s . '</svg>';
}
