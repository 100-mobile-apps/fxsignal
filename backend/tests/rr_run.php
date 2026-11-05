<?php
declare(strict_types=1);
/**
 * Month-by-month pips and R for the key strategies on every 3-year market (data3y/*.csv + gold_3y.csv),
 * written as compact JSON for the results dashboard.
 * Pips: FX 0.0001 (JPY 0.01), gold $0.10; crypto is reported in $ of price per coin ("points"). All net of costs.
 * Usage: php export_monthly.php > monthly.json
 */
ob_start();
require __DIR__ . '/pa_run.php';      // trend_run.php: $T, prep_pa(), to4h(), sma(), bt_* ...
ob_end_clean();

function rsi_w(array $c, int $n): array { $o = []; $g = $l = 0.0; foreach ($c as $i => $x) { if (!$i) { $o[0] = null; continue; } $d = $x - $c[$i - 1];
    $u = max($d, 0); $w = max(-$d, 0); if ($i <= $n) { $g += $u / $n; $l += $w / $n; } else { $g = ($g * ($n - 1) + $u) / $n; $l = ($l * ($n - 1) + $w) / $n; }
    $o[$i] = $i >= $n ? ($l == 0 ? 100 : 100 - 100 / (1 + $g / $l)) : null; } return $o; }

// same rules as simulate() in trend_run.php, but also returns the net price move per trade
function simulate_p(array $b, array $x, callable $sig, callable $cost, int $maxBars): array {
    $tr = []; $open = null; $n = count($b);
    for ($i = 210; $i < $n - 1; $i++) {
        if ($open) {
            $k = $b[$i]; $d = $open['dir']; $res = null;
            if (($k['o'] - $open['sl']) * $d <= 0) $res = $k['o'];
            elseif ((($d > 0 ? $k['l'] : $k['h']) - $open['sl']) * $d <= 0) $res = $open['sl'];
            elseif ($open['tp'] !== null && (($d > 0 ? $k['h'] : $k['l']) - $open['tp']) * $d >= 0) $res = $open['tp'];
            elseif (($open['exit'] && ($open['exit'])($b, $x, $i)) || $i - $open['i'] >= $maxBars) $res = $k['c'];
            if ($res === null) continue;
            $move = ($res - $open['entry']) * $d - $cost($open['entry']);
            $tr[] = ['t' => $open['t'], 'r' => $move / $open['risk'], 'move' => $move];
            $open = null; continue;
        }
        if ($x['atr'][$i] === null) continue;
        $s = $sig($b, $x, $i);
        if (!$s) continue;
        $entry = $b[$i + 1]['o']; $shift = $entry - $b[$i]['c'];
        $sl = $s['sl'] + $shift; $tp = isset($s['tp']) ? $s['tp'] + $shift : null;
        $risk = ($entry - $sl) * $s['dir'];
        if ($risk <= 0 || $risk < 0.2 * $x['atr'][$i] || ($tp !== null && ($tp - $entry) * $s['dir'] <= 0)) continue;
        $open = ['dir' => $s['dir'], 'entry' => $entry, 'sl' => $sl, 'tp' => $tp, 'exit' => $s['exit'] ?? null, 'risk' => $risk, 'i' => $i + 1, 't' => $b[$i + 1]['t']];
    }
    return $tr;
}

$london = function ($b, $x, $i) {
    $hr = (int) gmdate('G', $b[$i]['t']); if ($hr < 7 || $hr > 10) return null;
    $k = $i; while ($k > 0 && (int) gmdate('G', $b[$k]['t']) >= 7 && $b[$i]['t'] - $b[$k]['t'] < 12 * 3600) $k--;
    $hi = -INF; $lo = INF; $n = 0;
    for ($j = $k; $j > 0 && (int) gmdate('G', $b[$j]['t']) < 7 && $n < 7; $j--, $n++) { $hi = max($hi, $b[$j]['h']); $lo = min($lo, $b[$j]['l']); }
    if ($n < 5) return null;
    for ($j = $k + 1; $j < $i; $j++) if ($b[$j]['c'] > $hi || $b[$j]['c'] < $lo) return null;
    $c = $b[$i]['c']; $end = $i + 13;
    if ($c > $hi) return ['dir' => 1, 'sl' => $lo, 'tp' => $c + ($c - $lo), 'exit' => fn($b, $x, $j) => $j >= $end];
    if ($c < $lo) return ['dir' => -1, 'sl' => $hi, 'tp' => $c - ($hi - $c), 'exit' => fn($b, $x, $j) => $j >= $end];
    return null;
};
$ninety = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; if ($x['sma200'][$i] === null) return null;
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 4 * $a, 'tp' => $c + 0.25 * $a];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 4 * $a, 'tp' => $c - 0.25 * $a];
    return null;
};
$pick = fn(string $prefix) => current(array_filter($T, fn($k) => str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY));

// [label, timeframe, kind, signal or engine params, max bars]
$strategies = [
    ['Parabolic SAR + EMA200', '4h', 'sim', $pick('Parabolic SAR'), 90],
    ['Ichimoku', '4h', 'sim', $pick('Ichimoku'), 90],
    ['Supertrend', '4h', 'sim', $pick('Supertrend'), 90],
    ['Keltner breakout', '4h', 'sim', $pick('Keltner'), 90],
    ['MA crossover 20/50', '1h', 'sim', $pick('MA crossover'), 240],
    ['Turtle 20-bar breakout', '1h', 'sim', $pick('Turtle System 1'), 240],
    ['London breakout', '1h', 'sim', $london, 72],
    ['"90% recipe"', '1h', 'sim', $ninety, 72],
    ['Your engine, single 1.5R', '1h', 'engine', ['tp1-full', 1.5], 0],
    ['Your engine, live rules', '1h', 'engine', ['tp2-be', null], 0],
];

$files = glob(__DIR__ . '/data3y/*.csv');
if (is_file(__DIR__ . '/gold_3y.csv')) $files[] = __DIR__ . '/gold_3y.csv';
echo "strategy|market|trades|win%|avgWinR|avgLossR|RR|expectancyR|planned\n";
foreach ($files as $f) foreach (bt_load_csv($f) as $sym => $bars) {
    $pip = bt_pip_size($sym);
    $pct = str_starts_with($sym, 'BTC/') ? 0.0005 : (str_starts_with($sym, 'ETH/') ? 0.0007 : null);
    $closes = array_map(fn($b) => $b['c'], $bars); sort($closes); $median = $closes[intdiv(count($closes), 2)];
    $costPrice = fn(float $p) => $pct !== null ? $pct * $p : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5) * $pip;
    $costPips = $pct !== null ? $pct * $median / $pip : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5);
    $prepped = [];
    foreach ($strategies as $si => [$label, $tf, $kind, $sig, $max]) {
        $rs = [];
        if ($kind === 'engine') {
            [$exit, $r] = $sig;
            $an = $r === null ? null : static function (array $c) use ($r) {
                $a = Engine::analyze($c, 65);
                if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
                $k = $a['entry'] - $a['sl']; $a['tp1'] = $a['entry'] + $r * $k; $a['tp2'] = $a['entry'] + ($r + 1) * $k; return $a; };
            $o = ['exit' => $exit, 'cost-pips' => $costPips, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
            foreach (bt_simulate([$sym => $bars], $o, $an) as $t) if ($t['status'] === 'closed') $rs[] = $t['net_r'];
        } else {
            if (!isset($prepped[$tf])) { $b = $tf === '4h' ? to4h($bars) : $bars; $x = prep_pa($b); $x['rsi2'] = rsi_w($x['c'], 2); $x['sma5'] = sma($x['c'], 5); $prepped[$tf] = [$b, $x]; }
            [$b, $x] = $prepped[$tf];
            foreach (simulate_p($b, $x, $sig, $costPrice, $max) as $t) $rs[] = $t['r'];
        }
        $w = array_values(array_filter($rs, fn($v) => $v > 0)); $l = array_values(array_filter($rs, fn($v) => $v <= 0));
        $aw = $w ? array_sum($w) / count($w) : 0; $al = $l ? array_sum($l) / count($l) : 0; $n = count($rs);
        printf("%s (%s)|%s|%d|%.0f|%.2f|%.2f|%s|%.3f\n", $label, $tf, $sym, $n, $n ? 100 * count($w) / $n : 0, $aw, $al, $al < 0 ? sprintf('1:%.2f', $aw / -$al) : '-', $n ? array_sum($rs) / $n : 0);
    }
}
