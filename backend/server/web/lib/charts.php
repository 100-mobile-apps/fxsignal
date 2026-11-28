<?php
declare(strict_types=1);

/* Server-rendered SVG charts (no JavaScript libraries needed). */

function svg_num(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); }

/**
 * Candlestick chart for a signal, with entry/stop/target lines and risk/reward zones.
 * $candles: [[t, o, h, l, c], ...]. $levels: null when the signal is locked.
 */
function candle_chart(array $candles, string $pair, ?array $levels, ?float $current = null): string
{
    $candles = array_values(array_slice($candles, -70));
    if (!$candles) return '<div class="empty">Chart not available for this signal.</div>';
    $W = 760; $H = 340; $padR = 92; $padT = 14; $padB = 22;
    $lo = INF; $hi = -INF;
    foreach ($candles as $c) { $lo = min($lo, (float) $c[3]); $hi = max($hi, (float) $c[2]); }
    if ($levels) foreach ($levels as $v) { if ($v !== null) { $lo = min($lo, (float) $v); $hi = max($hi, (float) $v); } }
    if ($current !== null) { $lo = min($lo, $current); $hi = max($hi, $current); }
    $span = max($hi - $lo, 1e-9);
    $lo -= $span * 0.06; $hi += $span * 0.06; $span = $hi - $lo;
    $plotW = $W - $padR - 6;
    $y = fn(float $v) => $padT + ($hi - $v) / $span * ($H - $padT - $padB);
    $n = count($candles);
    $step = $plotW / max($n, 1);
    $bw = max(2.0, $step * 0.62);
    $out = '<svg class="chart candles" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($pair) . ' price chart">';
    for ($i = 0; $i <= 4; $i++) {
        $gy = $padT + $i * ($H - $padT - $padB) / 4;
        $out .= '<line x1="0" x2="' . $plotW . '" y1="' . svg_num($gy) . '" y2="' . svg_num($gy) . '" class="grid"/>';
    }
    if ($levels) {
        $e = (float) $levels['entry']; $sl = (float) $levels['stop_loss']; $tp2 = (float) $levels['tp2'];
        $x0 = $plotW * 0.72;
        $out .= '<rect x="' . svg_num($x0) . '" width="' . svg_num($plotW - $x0) . '" y="' . svg_num(min($y($e), $y($sl))) . '" height="' . svg_num(abs($y($e) - $y($sl))) . '" class="zone-risk"/>';
        $out .= '<rect x="' . svg_num($x0) . '" width="' . svg_num($plotW - $x0) . '" y="' . svg_num(min($y($e), $y($tp2))) . '" height="' . svg_num(abs($y($e) - $y($tp2))) . '" class="zone-reward"/>';
    }
    foreach ($candles as $i => $c) {
        [$t, $o, $h, $l, $cl] = array_map('floatval', $c);
        $cx = $i * $step + $step / 2;
        $up = $cl >= $o;
        $cls = $up ? 'cu' : 'cd';
        $top = $y(max($o, $cl)); $bot = $y(min($o, $cl));
        $out .= '<line x1="' . svg_num($cx) . '" x2="' . svg_num($cx) . '" y1="' . svg_num($y($h)) . '" y2="' . svg_num($y($l)) . '" class="' . $cls . '"/>';
        $out .= '<rect x="' . svg_num($cx - $bw / 2) . '" y="' . svg_num($top) . '" width="' . svg_num($bw) . '" height="' . svg_num(max(1.0, $bot - $top)) . '" class="' . $cls . '"/>';
    }
    $tag = function (float $v, string $label, string $cls) use ($y, $plotW, $pair, $W): string {
        $yy = $y($v);
        return '<line x1="0" x2="' . svg_num($plotW) . '" y1="' . svg_num($yy) . '" y2="' . svg_num($yy) . '" class="lvl ' . $cls . '"/>'
            . '<rect x="' . svg_num($plotW + 4) . '" y="' . svg_num($yy - 9) . '" width="' . ($W - $plotW - 4) . '" height="18" rx="4" class="tag ' . $cls . '"/>'
            . '<text x="' . svg_num($plotW + 8) . '" y="' . svg_num($yy + 4) . '" class="tagt">' . e($label . ' ' . fmt_price($pair, $v)) . '</text>';
    };
    if ($levels) {
        $out .= $tag((float) $levels['stop_loss'], 'SL', 'sl') . $tag((float) $levels['tp1'], 'TP1', 'tp') . $tag((float) $levels['tp2'], 'TP2', 'tp') . $tag((float) $levels['entry'], 'E', 'en');
    }
    if ($current !== null) $out .= $tag($current, 'Now', 'now');
    return $out . '</svg>';
}

/** Area chart of cumulative pips. $points: [['t' => iso, 'pips' => float], ...] */
function equity_chart(array $points, int $height = 220): string
{
    if (count($points) < 2) return '<div class="empty">Not enough closed signals yet.</div>';
    $W = 760; $H = $height; $pad = 10;
    $vals = array_map(fn($p) => (float) $p['pips'], $points);
    array_unshift($vals, 0.0);
    $lo = min(0.0, min($vals)); $hi = max(0.0, max($vals));
    $span = max($hi - $lo, 1.0);
    $n = count($vals) - 1;
    $x = fn(int $i) => $pad + $i / max($n, 1) * ($W - 2 * $pad);
    $y = fn(float $v) => $pad + ($hi - $v) / $span * ($H - 2 * $pad);
    $d = '';
    foreach ($vals as $i => $v) $d .= ($i ? 'L' : 'M') . svg_num($x($i)) . ' ' . svg_num($y($v));
    $area = $d . 'L' . svg_num($x($n)) . ' ' . svg_num($y($lo)) . 'L' . svg_num($x(0)) . ' ' . svg_num($y($lo)) . 'Z';
    $last = end($vals);
    $cls = $last >= 0 ? 'eq-up' : 'eq-down';
    return '<svg class="chart equity ' . $cls . '" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="Cumulative pips">'
        . '<defs><linearGradient id="eqg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" class="eq-s1"/><stop offset="1" class="eq-s2"/></linearGradient></defs>'
        . '<line x1="0" x2="' . $W . '" y1="' . svg_num($y(0)) . '" y2="' . svg_num($y(0)) . '" class="zero"/>'
        . '<path d="' . $area . '" fill="url(#eqg)"/><path d="' . $d . '" class="eq-line"/></svg>';
}

/** Bars of pips per month: [['month' => 'YYYY-MM', 'pips' => x, 'total' => n, 'wins' => n], ...] */
function month_bars(array $months): string
{
    $months = array_slice($months, -12);
    if (!$months) return '<div class="empty">No months yet.</div>';
    $W = 760; $H = 200; $pad = 24;
    $max = max(1.0, max(array_map(fn($m) => abs((float) $m['pips']), $months)));
    $n = count($months);
    $bw = ($W - 20) / $n;
    $zero = ($H - $pad) / 2 + 6;
    $out = '<svg class="chart bars" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Pips per month"><line x1="0" x2="' . $W . '" y1="' . $zero . '" y2="' . $zero . '" class="zero"/>';
    foreach ($months as $i => $m) {
        $v = (float) $m['pips'];
        $h = abs($v) / $max * ($zero - 14);
        $x = 10 + $i * $bw + $bw * 0.18;
        $yy = $v >= 0 ? $zero - $h : $zero;
        $out .= '<rect x="' . svg_num($x) . '" y="' . svg_num($yy) . '" width="' . svg_num($bw * 0.64) . '" height="' . svg_num(max(1.0, $h)) . '" rx="3" class="' . ($v >= 0 ? 'bu' : 'bd') . '"><title>' . e($m['month'] . ': ' . fmt_pips($v, true)) . '</title></rect>';
        $label = date('M', strtotime($m['month'] . '-01'));
        $out .= '<text x="' . svg_num($x + $bw * 0.32) . '" y="' . ($H - 4) . '" class="bl">' . e($label) . '</text>';
    }
    return $out . '</svg>';
}

function sparkline(array $vals, bool $up): string
{
    $vals = array_values(array_map('floatval', $vals));
    if (count($vals) < 2) return '';
    $W = 120; $H = 36;
    $lo = min($vals); $hi = max($vals); $span = max($hi - $lo, 1e-9);
    $n = count($vals) - 1;
    $d = '';
    foreach ($vals as $i => $v) $d .= ($i ? 'L' : 'M') . svg_num($i / $n * $W) . ' ' . svg_num(2 + ($hi - $v) / $span * ($H - 4));
    return '<svg class="spark ' . ($up ? 'up' : 'down') . '" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Win-rate ring. */
function ring(float $pct, string $label = 'Win rate'): string
{
    $r = 52; $c = 2 * M_PI * $r;
    $dash = svg_num($c * max(0, min(100, $pct)) / 100);
    return '<div class="ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="' . $r . '" class="ring-bg"/>'
        . '<circle cx="60" cy="60" r="' . $r . '" class="ring-fg" stroke-dasharray="' . $dash . ' ' . svg_num($c) . '" transform="rotate(-90 60 60)"/></svg>'
        . '<div class="ring-t"><b>' . e(number_format($pct, $pct == floor($pct) ? 0 : 1)) . '%</b><span>' . e($label) . '</span></div></div>';
}
