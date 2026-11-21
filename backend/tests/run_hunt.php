<?php
declare(strict_types=1);
/**
 * Strategy hunt, January 2025 to 4 November 2026: every research strategy, three new ones and the classic engine,
 * on 19 markets, on 1-hour, 4-hour and daily bars, with three exit styles:
 *   own     - the strategy's own stop, target and exit
 *   80/160  - two halves: TP1 at 2.67x the stop (+80% of the account at 30% risk), TP2 at 5.33x (+160%);
 *             after TP1 the second half's stop goes to entry, halfway to TP2 it goes to TP1
 *   trail   - no target: the stop trails 3 ATR behind the best close since entry
 *   4R/8R   - like 80/160 but TP1 at 4x the stop and TP2 at 8x
 * HUNT_TRADES=1 prints every trade (entry time and net result in stop units) instead of monthly rows.
 * Money: each market starts every month with $100 and risks 30% of its balance per trade, compounding within the
 * month. A trade counts in the month it opens. After spread. Rows per month so 2025 can be used to choose and 2026
 * to judge.
 *
 * Usage: php run_hunt.php DATA_DIR > hunt.json   (DATA_DIR from yahoo_fetch.py)
 */
ini_set('memory_limit', '2G');
$dataDir = rtrim($argv[1] ?? '', '/');
if (!is_dir($dataDir)) { fwrite(STDERR, "usage: php run_hunt.php DATA_DIR\n"); exit(1); }

$dummy = __DIR__ . '/history.csv'; $made = false;
if (!is_file($dummy)) {
    $made = true; $csv = "symbol,time,open,high,low,close\n";
    for ($k = 0; $k < 320; $k++) $csv .= 'EUR/USD,' . gmdate('Y-m-d\TH:00:00\Z', 1767571200 + 3600 * $k) . ",1.1,1.1,1.1,1.1\n";
    file_put_contents($dummy, $csv);
}
ob_start();
try { require __DIR__ . '/pa_run.php'; } finally { ob_end_clean(); if ($made) @unlink($dummy); }
require_once __DIR__ . '/../api/src/Engine.php';
require_once __DIR__ . '/../api/src/TradingPolicy.php';

function rsi_n(array $c, int $n): array {
    $o = []; $g = $l = 0.0;
    foreach ($c as $i => $x) {
        if (!$i) { $o[0] = null; continue; }
        $d = $x - $c[$i - 1]; $u = max($d, 0); $w = max(-$d, 0);
        if ($i <= $n) { $g += $u / $n; $l += $w / $n; } else { $g = ($g * ($n - 1) + $u) / $n; $l = ($l * ($n - 1) + $w) / $n; }
        $o[$i] = $i >= $n ? ($l == 0 ? 100 : 100 - 100 / (1 + $g / $l)) : null;
    }
    return $o;
}
function stdev_n(array $c, int $n): array {
    $o = [];
    foreach ($c as $i => $_) {
        if ($i < $n - 1) { $o[$i] = null; continue; }
        $s = array_slice($c, $i - $n + 1, $n); $m = array_sum($s) / $n;
        $o[$i] = sqrt(array_sum(array_map(fn($v) => ($v - $m) ** 2, $s)) / $n);
    }
    return $o;
}

$strategies = [];
foreach ($T as $name => $fn) $strategies[$name] = $fn;
foreach ($P as $name => $fn) $strategies[$name] = $fn;
$strategies['"90% recipe" (RSI2 pullback, SMA200)'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; if (($x['sma200'][$i] ?? null) === null) return null;
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 4 * $a, 'tp' => $c + 0.25 * $a];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 4 * $a, 'tp' => $c - 0.25 * $a];
    return null;
};
$strategies['Bollinger reversion with trend (20,2), target the middle band'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $m = $x['sma20'][$i]; $sd = $x['sd20'][$i];
    if ($m === null || $sd === null || ($x['sma200'][$i] ?? null) === null) return null;
    if ($c > $x['sma200'][$i] && $c < $m - 2 * $sd) return ['dir' => 1, 'sl' => $c - 2 * $a, 'tp' => $m];
    if ($c < $x['sma200'][$i] && $c > $m + 2 * $sd) return ['dir' => -1, 'sl' => $c + 2 * $a, 'tp' => $m];
    return null;
};
$strategies['Volatility squeeze breakout (tightest bands in 50 bars), 3R'] = function ($b, $x, $i) {
    if ($i < 60 || $x['sd20'][$i - 1] === null) return null;
    $w = $x['sd20'][$i - 1];
    for ($k = $i - 50; $k < $i - 1; $k++) if ($x['sd20'][$k] !== null && $x['sd20'][$k] < $w) return null;
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > hh($b, 20, $i - 1)) return ['dir' => 1, 'sl' => $c - 1.5 * $a, 'tp' => $c + 4.5 * $a];
    if ($c < ll($b, 20, $i - 1)) return ['dir' => -1, 'sl' => $c + 1.5 * $a, 'tp' => $c - 4.5 * $a];
    return null;
};
$strategies['New York open breakout (first hour range, 1-hour bars only)'] = function ($b, $x, $i) {
    if ($i < 2 || ($b[$i]['t'] - $b[$i - 1]['t']) !== 3600) return null;       // 1-hour bars only
    $hr = (int) gmdate('G', $b[$i]['t']); if ($hr < 14 || $hr > 16) return null;
    $k = $i; while ($k > 0 && (int) gmdate('G', $b[$k]['t']) > 13) $k--;
    if ((int) gmdate('G', $b[$k]['t']) !== 13 || gmdate('Ymd', $b[$k]['t']) !== gmdate('Ymd', $b[$i]['t'])) return null;
    $hi = $b[$k]['h']; $lo = $b[$k]['l'];
    for ($j = $k + 1; $j < $i; $j++) if ($b[$j]['c'] > $hi || $b[$j]['c'] < $lo) return null;
    $c = $b[$i]['c']; $end = $i + 8;
    if ($c > $hi) return ['dir' => 1, 'sl' => $lo, 'tp' => $c + 2 * ($c - $lo), 'exit' => fn($b, $x, $j) => $j >= $end];
    if ($c < $lo) return ['dir' => -1, 'sl' => $hi, 'tp' => $c - 2 * ($hi - $c), 'exit' => fn($b, $x, $j) => $j >= $end];
    return null;
};
$strategies['Classic (the app\'s engine)'] = function ($b, $x, $i) {
    if ($i < 300) return null;
    $a = Engine::analyze(array_slice($b, $i - 299, 300), 65);
    if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return null;
    return ['dir' => $a['direction'] === 'BUY' ? 1 : -1, 'sl' => $a['sl'], 'tp' => $a['tp2']];
};

// market => [pip (one "pip" or point in the output), cost per trade in price, or null with pct]
$markets = [
    'XAU/USD' => [0.1, 0.3, null], 'XAG/USD' => [0.01, 0.03, null], 'WTI' => [0.01, 0.03, null],
    'EUR/USD' => [0.0001, 0.00015, null], 'GBP/USD' => [0.0001, 0.00015, null], 'USD/JPY' => [0.01, 0.015, null],
    'AUD/USD' => [0.0001, 0.00015, null], 'USD/CAD' => [0.0001, 0.00015, null], 'USD/CHF' => [0.0001, 0.00015, null],
    'NZD/USD' => [0.0001, 0.00015, null], 'EUR/JPY' => [0.01, 0.02, null], 'GBP/JPY' => [0.01, 0.03, null],
    'AUD/JPY' => [0.01, 0.02, null], 'EUR/GBP' => [0.0001, 0.00015, null],
    'US500' => [1.0, 0.5, null], 'US100' => [1.0, 1.5, null], 'US30' => [1.0, 3.0, null],
    'BTC/USD' => [1.0, null, 0.0005], 'ETH/USD' => [1.0, null, 0.0007],
];

function load_bars(string $file): array {
    $f = fopen($file, 'r'); fgetcsv($f, 0, ',', '"', ''); $b = [];
    while (($r = fgetcsv($f, 0, ',', '"', '')) !== false) $b[] = ['t' => (int) $r[0], 'o' => (float) $r[1], 'h' => (float) $r[2], 'l' => (float) $r[3], 'c' => (float) $r[4]];
    fclose($f);
    return $b;
}

/** Every bar's signal, worked out once and shared by the three exit styles. */
function signals(array $b, array $x, callable $sig): array {
    $out = []; $n = count($b);
    for ($i = 210; $i < $n - 1; $i++) {
        if (($x['atr'][$i] ?? null) === null) continue;
        $s = $sig($b, $x, $i);
        if ($s) $out[$i] = $s;
    }
    return $out;
}

/** One position at a time. Returns trades: [t (entry), r (net, in stop units), pips (net)]. */
function run_trades(array $b, array $x, array $sigs, callable $cost, float $pip, string $mode, int $maxBars): array {
    $tr = []; $n = count($b); $open = null;
    for ($i = 210; $i < $n - 1; $i++) {
        if ($open) {
            $k = $b[$i]; $d = $open['dir']; $done = false; $move = 0.0;
            $hitStop = fn($stop) => ($k['o'] - $stop) * $d <= 0 ? $k['o'] : ((($d > 0 ? $k['l'] : $k['h']) - $stop) * $d <= 0 ? $stop : null);
            $hitTp = fn($tp) => (($d > 0 ? $k['h'] : $k['l']) - $tp) * $d >= 0;
            if ($mode === 'own') {
                $res = $hitStop($open['sl']);
                if ($res === null && $open['tp'] !== null && $hitTp($open['tp'])) $res = $open['tp'];
                if ($res === null && (($open['exit'] && ($open['exit'])($b, $x, $i)) || $i - $open['i'] >= $maxBars)) $res = $k['c'];
                if ($res !== null) { $move = ($res - $open['entry']) * $d; $done = true; }
            } elseif ($mode === 'trail') {
                $res = $hitStop($open['sl']);
                if ($res === null && $i - $open['i'] >= 3 * $maxBars) $res = $k['c'];
                if ($res !== null) { $move = ($res - $open['entry']) * $d; $done = true; }
                else {
                    $open['best'] = $d > 0 ? max($open['best'], $k['c']) : min($open['best'], $k['c']);
                    $trail = $open['best'] - $d * 3 * ($x['atr'][$i] ?? $open['risk']);
                    if (($trail - $open['sl']) * $d > 0) $open['sl'] = $trail;
                }
            } else {
                foreach (['a', 'b'] as $leg) {
                    if ($open[$leg] !== null) continue;
                    $stop = $leg === 'a' ? $open['sl'] : $open['bstop'];
                    $res = $hitStop($stop);
                    if ($res !== null) { $open[$leg] = ($res - $open['entry']) * $d; continue; }
                    $tp = $leg === 'a' ? $open['tp1'] : $open['tp2'];
                    if ($hitTp($tp)) $open[$leg] = ($tp - $open['entry']) * $d;
                }
                if ($open['a'] !== null && $open['b'] === null) {
                    $open['bstop'] = $open['entry'];
                    $half = $open['tp1'] + ($open['tp2'] - $open['tp1']) / 2;
                    if ((($d > 0 ? $k['h'] : $k['l']) - $half) * $d >= 0) $open['bstop'] = $open['tp1'];
                }
                if ($i - $open['i'] >= $maxBars) { foreach (['a', 'b'] as $leg) $open[$leg] ??= ($k['c'] - $open['entry']) * $d; }
                if ($open['a'] !== null && $open['b'] !== null) { $move = ($open['a'] + $open['b']) / 2; $done = true; }
            }
            if ($done) {
                $move -= $cost($open['entry']);
                $tr[] = ['t' => $open['t'], 'r' => $move / $open['risk'], 'pips' => $move / $pip];
                $open = null;
            }
            continue;
        }
        $s = $sigs[$i] ?? null;
        if (!$s) continue;
        $entry = $b[$i + 1]['o']; $shift = $entry - $b[$i]['c'];
        $sl = $s['sl'] + $shift; $risk = ($entry - $sl) * $s['dir'];
        if ($risk <= 0 || $risk < 0.2 * $x['atr'][$i]) continue;
        $tp = isset($s['tp']) ? $s['tp'] + $shift : null;
        if ($mode === 'own' && $tp !== null && ($tp - $entry) * $s['dir'] <= 0) continue;
        $open = ['dir' => $s['dir'], 'entry' => $entry, 'sl' => $sl, 'tp' => $tp, 'exit' => $s['exit'] ?? null, 'risk' => $risk,
            'i' => $i + 1, 't' => $b[$i + 1]['t'], 'a' => null, 'b' => null, 'bstop' => $sl, 'best' => $entry,
            'tp1' => $entry + $s['dir'] * ($mode === '4R/8R' ? 4.0 : 80 / 30) * $risk,
            'tp2' => $entry + $s['dir'] * ($mode === '4R/8R' ? 8.0 : 160 / 30) * $risk];
    }
    return $tr;
}

$months = [];
for ($y = 2025; $y <= 2026; $y++) for ($m = 1; $m <= 12; $m++) { $k = sprintf('%d-%02d', $y, $m); if ($k <= '2026-11') $months[] = $k; }
$rows = []; $tradesOut = []; $wantTrades = (bool) getenv('HUNT_TRADES');
$only = getenv('HUNT_MARKETS') ? explode(',', getenv('HUNT_MARKETS')) : null;
foreach (['H1' => 72, 'H4' => 90, 'D1' => 30] as $tf => $maxBars) {
    foreach ($markets as $mk => [$pip, $fixed, $pct]) {
        if ($only && !in_array($mk, $only, true)) continue;
        $file = "$dataDir/" . str_replace('/', '_', $mk) . ($tf === 'D1' ? '_1d.csv' : '_1h.csv');
        if (!is_file($file)) { fwrite(STDERR, "missing $file\n"); continue; }
        $bars = load_bars($file);
        if ($tf === 'H4') $bars = to4h($bars);
        $x = prep_pa($bars); $x['rsi2'] = rsi_n($x['c'], 2); $x['sma5'] = sma($x['c'], 5);
        $x['sma200'] = $x['sma200'] ?? sma($x['c'], 200); $x['sma20'] = sma($x['c'], 20); $x['sd20'] = stdev_n($x['c'], 20);
        $cost = $pct !== null ? fn(float $p) => $pct * $p : fn(float $p) => $fixed;
        foreach ($strategies as $name => $sig) {
            if ($tf !== 'H1' && str_starts_with($name, 'New York open')) continue;
            $sigs = signals($bars, $x, $sig);
            foreach (['own', '80/160', 'trail', '4R/8R'] as $mode) {
                $byMonth = [];
                $list = run_trades($bars, $x, $sigs, $cost, $pip, $mode, $maxBars);
                if ($wantTrades) {
                    $tradesOut[] = [$tf, $mode, $name, $mk, array_map(fn($t) => [$t['t'], round($t['r'], 3)], array_values(array_filter($list, fn($t) => $t['t'] >= 1738368000)))];
                    continue;
                }
                foreach ($list as $t) $byMonth[gmdate('Y-m', $t['t'])][] = $t;
                foreach ($months as $key) {
                    $bal = 100.0; $wins = 0; $pips = 0.0; $low = 100.0; $list = $byMonth[$key] ?? [];
                    foreach ($list as $t) { $bal = max(0.0, $bal * (1 + 0.30 * $t['r'])); $low = min($low, $bal); $wins += $t['r'] > 0 ? 1 : 0; $pips += $t['pips']; }
                    if (!$list && $key < '2025-02') continue;   // warm-up month
                    $rows[] = [$tf, $mode, $name, $mk, $key, count($list), $wins, round($pips, 1), round($bal - 100, 2), round($low, 2)];
                }
            }
        }
        fwrite(STDERR, "$tf $mk done\n");
    }
}
if ($wantTrades) { echo json_encode(['columns' => ['timeframe', 'targets', 'strategy', 'market', 'trades'], 'rows' => $tradesOut], JSON_UNESCAPED_SLASHES), "\n"; exit; }
echo json_encode(['columns' => ['timeframe', 'targets', 'strategy', 'market', 'month', 'trades', 'wins', 'pips', 'profit', 'lowest'], 'rows' => $rows], JSON_UNESCAPED_SLASHES), "\n";
