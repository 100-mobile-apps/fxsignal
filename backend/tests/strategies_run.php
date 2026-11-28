<?php
declare(strict_types=1);
/**
 * Tests well-known public strategies on history.csv (hourly, UTC) with the same costs as history_run.php.
 * Entry at the next bar's open after a closed-bar signal; one trade per pair at a time; stop checked first
 * inside a bar (conservative); gaps through stops fill at the open; R = initial stop distance.
 * Usage: php strategies_run.php [--csv=history.csv]
 */
$opts = getopt('', ['csv::']);
$csv = $opts['csv'] ?? __DIR__ . '/history.csv';
$_SERVER['SCRIPT_FILENAME'] = '';
require_once __DIR__ . '/backtest.php';
$series = bt_load_csv($csv);

// ---------- indicators (arrays aligned with bars; null until warm) ----------
function closes(array $b): array { return array_map(fn($x) => $x['c'], $b); }
function sma(array $v, int $n): array { $o = []; $s = 0.0; foreach ($v as $i => $x) { $s += $x; if ($i >= $n) $s -= $v[$i - $n]; $o[$i] = $i >= $n - 1 ? $s / $n : null; } return $o; }
function ema(array $v, int $n): array { $o = []; $k = 2 / ($n + 1); $e = null; foreach ($v as $i => $x) { $e = $e === null ? $x : $x * $k + $e * (1 - $k); $o[$i] = $i >= $n - 1 ? $e : null; } return $o; }
function rsi(array $v, int $n): array { $o = []; $g = $l = 0.0; foreach ($v as $i => $x) { if ($i === 0) { $o[$i] = null; continue; } $d = $x - $v[$i - 1]; $up = max($d, 0); $dn = max(-$d, 0);
    if ($i <= $n) { $g += $up / $n; $l += $dn / $n; } else { $g = ($g * ($n - 1) + $up) / $n; $l = ($l * ($n - 1) + $dn) / $n; }
    $o[$i] = $i >= $n ? ($l == 0 ? 100 : 100 - 100 / (1 + $g / $l)) : null; } return $o; }
function atr(array $b, int $n): array { $o = []; $a = null; foreach ($b as $i => $x) { $tr = $i ? max($x['h'] - $x['l'], abs($x['h'] - $b[$i - 1]['c']), abs($x['l'] - $b[$i - 1]['c'])) : $x['h'] - $x['l'];
    $a = $a === null ? $tr : ($a * ($n - 1) + $tr) / $n; $o[$i] = $i >= $n ? $a : null; } return $o; }
function stdev(array $v, int $n): array { $o = []; foreach ($v as $i => $x) { if ($i < $n - 1) { $o[$i] = null; continue; } $w = array_slice($v, $i - $n + 1, $n); $m = array_sum($w) / $n;
    $o[$i] = sqrt(array_sum(array_map(fn($y) => ($y - $m) ** 2, $w)) / $n); } return $o; }
function hh(array $b, int $n, int $i): float { $m = -INF; for ($k = $i - $n + 1; $k <= $i; $k++) $m = max($m, $b[$k]['h']); return $m; }
function ll(array $b, int $n, int $i): float { $m = INF; for ($k = $i - $n + 1; $k <= $i; $k++) $m = min($m, $b[$k]['l']); return $m; }

/**
 * Strategy = fn(array $bars, array $ind, int $i): ?array  -> signal decided on CLOSE of bar $i:
 *   ['dir'=>1|-1, 'sl'=>price, 'tp'=>?price, 'exit'=>?callable(bars,ind,j,trade):bool (checked on close), 'max'=>bars]
 * Prices are relative to the close of bar $i; they are shifted to the actual next-bar open entry.
 */
function run_strategy(array $series, callable $prep, callable $sig): array
{
    $trades = [];
    foreach ($series as $sym => $bars) {
        $pip = bt_pip_size($sym); $cost = str_starts_with($sym, 'XAU/') ? 3.0 : 1.5;
        $ind = $prep($bars); $n = count($bars); $open = null;
        for ($i = 250; $i < $n - 1; $i++) {
            $gap = $bars[$i + 1]['t'] - $bars[$i]['t'] !== 3600;
            if ($open) {
                $b = $bars[$i]; $d = $open['dir']; $res = null;
                if (($b['o'] - $open['sl']) * $d <= 0) $res = $b['o'];                       // gap through stop
                elseif ((($d > 0 ? $b['l'] : $b['h']) - $open['sl']) * $d <= 0) $res = $open['sl']; // stop first
                elseif ($open['tp'] !== null && (($d > 0 ? $b['h'] : $b['l']) - $open['tp']) * $d >= 0) $res = $open['tp'];
                elseif (($open['exit'] && ($open['exit'])($bars, $ind, $i, $open)) || $i - $open['i'] >= $open['max'] || $gap) $res = $b['c'];
                if ($res !== null) {
                    $risk = abs($open['entry'] - $open['sl0']) / $pip;
                    $net = ($res - $open['entry']) * $d / $pip - $cost;
                    $trades[] = ['sym' => $sym, 't' => $open['t'], 'r' => $net / $risk];
                    $open = null;
                }
                continue;
            }
            if ($gap || !TradingPolicy::canPublish($bars[$i + 1]['t'])) continue;
            $s = $sig($bars, $ind, $i);
            if (!$s) continue;
            $entry = $bars[$i + 1]['o']; $shift = $entry - $bars[$i]['c'];
            $sl = $s['sl'] + $shift; $tp = isset($s['tp']) ? $s['tp'] + $shift : null;
            if (($entry - $sl) * $s['dir'] <= 0 || ($tp !== null && ($tp - $entry) * $s['dir'] <= 0)) continue;
            $open = ['dir' => $s['dir'], 'entry' => $entry, 'sl' => $sl, 'sl0' => $sl, 'tp' => $tp, 'exit' => $s['exit'] ?? null,
                     'max' => $s['max'] ?? 72, 'i' => $i + 1, 't' => $bars[$i + 1]['t']];
        }
    }
    return $trades;
}

$prep = function (array $b): array {
    $c = closes($b);
    return ['c' => $c, 'sma5' => sma($c, 5), 'sma20' => sma($c, 20), 'sma50' => sma($c, 50), 'sma200' => sma($c, 200),
            'ema200' => ema($c, 200), 'ema12' => ema($c, 12), 'ema26' => ema($c, 26), 'rsi2' => rsi($c, 2), 'rsi14' => rsi($c, 14),
            'atr' => atr($b, 14), 'sd20' => stdev($c, 20)];
};
// MACD signal line needs the macd series
$prepMacd = function (array $b) use ($prep): array {
    $x = $prep($b); $m = [];
    foreach ($x['c'] as $i => $_) $m[$i] = ($x['ema12'][$i] !== null && $x['ema26'][$i] !== null) ? $x['ema12'][$i] - $x['ema26'][$i] : 0.0;
    $x['macd'] = $m; $x['sig'] = ema($m, 9); return $x;
};

$S = [];
$S['RSI(2) mean reversion (Connors) - exit above SMA5, 3 ATR stop'] = [$prep, function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 3 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > $x['sma5'][$j], 'max' => 48];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 3 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < $x['sma5'][$j], 'max' => 48];
    return null; }];
$S['Bollinger reversion (20,2) - exit at middle band, 2 ATR stop'] = [$prep, function ($b, $x, $i) {
    $c = $x['c'][$i]; $m = $x['sma20'][$i]; $sd = $x['sd20'][$i]; $a = $x['atr'][$i];
    if ($c < $m - 2 * $sd) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] >= $x['sma20'][$j], 'max' => 48];
    if ($c > $m + 2 * $sd) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] <= $x['sma20'][$j], 'max' => 48];
    return null; }];
$S['Turtle / Donchian 20 breakout - 2 ATR stop, exit on 10-bar opposite break'] = [$prep, function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > hh($b, 20, $i - 1)) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < ll($b, 10, $j - 1), 'max' => 240];
    if ($c < ll($b, 20, $i - 1)) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > hh($b, 10, $j - 1), 'max' => 240];
    return null; }];
$S['MA crossover 20/50 with SMA200 filter - 2 ATR stop, exit on opposite cross'] = [$prep, function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $up = $x['sma20'][$i] > $x['sma50'][$i]; $was = $x['sma20'][$i - 1] > $x['sma50'][$i - 1];
    if ($up && !$was && $c > $x['sma200'][$i]) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['sma20'][$j] < $x['sma50'][$j], 'max' => 240];
    if (!$up && $was && $c < $x['sma200'][$i]) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['sma20'][$j] > $x['sma50'][$j], 'max' => 240];
    return null; }];
$S['MACD cross + EMA200 trend - 1.5 ATR stop, 1.5R target'] = [$prepMacd, function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $m = $x['macd']; $s = $x['sig'];
    if ($s[$i] === null || $s[$i - 1] === null) return null;
    if ($m[$i] > $s[$i] && $m[$i - 1] <= $s[$i - 1] && $m[$i] < 0 && $c > $x['ema200'][$i]) return ['dir' => 1, 'sl' => $c - 1.5 * $a, 'tp' => $c + 2.25 * $a];
    if ($m[$i] < $s[$i] && $m[$i - 1] >= $s[$i - 1] && $m[$i] > 0 && $c < $x['ema200'][$i]) return ['dir' => -1, 'sl' => $c + 1.5 * $a, 'tp' => $c - 2.25 * $a];
    return null; }];
$S['London breakout - Asian range 00-07 UTC, stop at other side, 1R target'] = [$prep, function ($b, $x, $i) {
    $hr = (int) gmdate('G', $b[$i]['t']);
    if ($hr < 7 || $hr > 10) return null;
    $k = $i; while ($k > 0 && (int) gmdate('G', $b[$k]['t']) >= 7 && $b[$i]['t'] - $b[$k]['t'] < 12 * 3600) $k--;
    $hi = -INF; $lo = INF; $n = 0;
    for ($j = $k; $j > 0 && (int) gmdate('G', $b[$j]['t']) < 7 && $n < 7; $j--, $n++) { $hi = max($hi, $b[$j]['h']); $lo = min($lo, $b[$j]['l']); }
    if ($n < 5) return null;
    for ($j = $k + 1; $j < $i; $j++) if ($b[$j]['c'] > $hi || $b[$j]['c'] < $lo) return null; // only the first break
    $c = $x['c'][$i]; $rng = $hi - $lo;
    if ($c > $hi) return ['dir' => 1, 'sl' => $lo, 'tp' => $c + ($c - $lo), 'max' => 12];
    if ($c < $lo) return ['dir' => -1, 'sl' => $hi, 'tp' => $c - ($hi - $c), 'max' => 12];
    return null; }];
$S['"90% recipe": RSI(2) entries, tiny target 0.25 ATR, 4 ATR stop'] = [$prep, function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 4 * $a, 'tp' => $c + 0.25 * $a, 'max' => 72];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 4 * $a, 'tp' => $c - 0.25 * $a, 'max' => 72];
    return null; }];

$first = PHP_INT_MAX; $last = 0;
foreach ($series as $bars) { $first = min($first, $bars[250]['t']); $last = max($last, end($bars)['t']); }
printf("Period: %s -> %s UTC, 8 pairs, costs 1.5 pips FX / 3 pips gold per trade\n", gmdate('Y-m-d', $first), gmdate('Y-m-d', $last));

$summary = [];
foreach ($S as $name => [$p, $f]) {
    $tr = run_strategy($series, $p, $f);
    usort($tr, fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($tr); $w = count(array_filter($tr, fn($t) => $t['r'] > 0));
    $sum = 0; $peak = 0; $dd = 0; $worst = 0; $wins = []; $loss = []; $mon = []; $pair = [];
    foreach ($tr as $t) { $sum += $t['r']; $peak = max($peak, $sum); $dd = max($dd, $peak - $sum); $worst = min($worst, $t['r']);
        if ($t['r'] > 0) $wins[] = $t['r']; else $loss[] = $t['r'];
        $m = gmdate('Y-m', $t['t']); $mon[$m] = ($mon[$m] ?? 0) + $t['r']; $pair[$t['sym']] = ($pair[$t['sym']] ?? 0) + $t['r']; }
    ksort($mon); arsort($pair);
    printf("\n== %s ==\n", $name);
    printf("trades %d | win %.1f%% | net %+.1fR | avg win %+.2fR | avg loss %+.2fR | worst %+.2fR | max drawdown %.1fR | profitable months %d/%d\n",
        $n, $n ? 100 * $w / $n : 0, $sum, $wins ? array_sum($wins) / count($wins) : 0, $loss ? array_sum($loss) / count($loss) : 0,
        $worst, $dd, count(array_filter($mon, fn($v) => $v > 0)), count($mon));
    echo 'by pair: ' . implode('  ', array_map(fn($k, $v) => "$k " . sprintf('%+.1f', $v), array_keys($pair), $pair)) . "\n";
    echo 'by month: ' . implode('  ', array_map(fn($k, $v) => "$k " . sprintf('%+.1f', $v), array_keys($mon), $mon)) . "\n";
}
