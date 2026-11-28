<?php
declare(strict_types=1);
/**
 * 2026 month by month, per market, on 4-hour and daily bars, for every research strategy plus the app's classic engine.
 * Money: each market starts every month with $100 and risks 30% of its balance per trade (compounding within the
 * month); a trade counts in the month it opens. After spread (FX 1.5 pips, gold 3 pips).
 *
 * Two target modes for every strategy:
 *   own     - the strategy's own stop, target and exit, one position (as in the earlier backtests)
 *   80/160  - the strategy's entry and stop, two half positions: TP1 at 2.67x the stop (+80% of the account at 30% risk),
 *             TP2 at 5.33x (+160%); after TP1 the second half's stop goes to entry, halfway to TP2 it goes to TP1.
 *
 * Usage: php run_2026.php DATA_DIR > results_2026.json   (DATA_DIR from yahoo_fetch.py)
 */
$dataDir = rtrim($argv[1] ?? '', '/');
if (!is_dir($dataDir)) { fwrite(STDERR, "usage: php run_2026.php DATA_DIR\n"); exit(1); }

// Load the research strategies ($T from trend_run.php, $P from pa_run.php) without running their reports.
// trend_run.php reads tests/history.csv by default; give it a flat 320-bar placeholder if none exists, then remove it.
$dummy = __DIR__ . '/history.csv'; $made = false;
if (!is_file($dummy)) {
    $made = true; $csv = "symbol,time,open,high,low,close\n";
    for ($k = 0; $k < 320; $k++) $csv .= 'EUR/USD,' . gmdate('Y-m-d\\TH:00:00\\Z', 1767571200 + 3600 * $k) . ",1.1,1.1,1.1,1.1\n";
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

$strategies = [];
foreach ($T as $name => $fn) $strategies[$name] = $fn;
foreach ($P as $name => $fn) $strategies[$name] = $fn;
$strategies['"90% recipe" (RSI2 pullback, SMA200)'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; if (($x['sma200'][$i] ?? null) === null) return null;
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 4 * $a, 'tp' => $c + 0.25 * $a];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 4 * $a, 'tp' => $c - 0.25 * $a];
    return null;
};
$strategies['Classic (the app\'s engine)'] = function ($b, $x, $i) {
    if ($i < 300) return null;
    $a = Engine::analyze(array_slice($b, $i - 299, 300), 65);
    if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return null;
    return ['dir' => $a['direction'] === 'BUY' ? 1 : -1, 'sl' => $a['sl'], 'tp' => $a['tp2'], 'tp1' => $a['tp1']];
};

function load_bars(string $file): array {
    $f = fopen($file, 'r'); fgetcsv($f, 0, ',', '"', ''); $b = [];
    while (($r = fgetcsv($f, 0, ',', '"', '')) !== false) $b[] = ['t' => (int) $r[0], 'o' => (float) $r[1], 'h' => (float) $r[2], 'l' => (float) $r[3], 'c' => (float) $r[4]];
    fclose($f);
    return $b;
}

/** One position at a time. Returns trades: [t (entry), r (net, in stop units), pips (net)]. */
function run_trades(array $b, array $x, callable $sig, float $cost, float $pip, string $mode, int $maxBars, int $from): array {
    $tr = []; $n = count($b); $open = null;
    for ($i = 210; $i < $n - 1; $i++) {
        if ($open) {
            $k = $b[$i]; $d = $open['dir']; $done = false;
            $hitStop = fn($stop) => ($k['o'] - $stop) * $d <= 0 ? $k['o'] : ((($d > 0 ? $k['l'] : $k['h']) - $stop) * $d <= 0 ? $stop : null);
            $hitTp = fn($tp) => (($d > 0 ? $k['h'] : $k['l']) - $tp) * $d >= 0;
            if ($mode === 'own') {
                $res = $hitStop($open['sl']);
                if ($res === null && $open['tp'] !== null && $hitTp($open['tp'])) $res = $open['tp'];
                if ($res === null && (($open['exit'] && ($open['exit'])($b, $x, $i)) || $i - $open['i'] >= $maxBars)) $res = $k['c'];
                if ($res !== null) { $move = ($res - $open['entry']) * $d; $done = true; }
            } else {
                // two halves: A to TP1, B to TP2 with stop to entry after TP1 and to TP1 halfway
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
                $move -= $cost;
                if ($open['t'] >= $from) $tr[] = ['t' => $open['t'], 'r' => $move / $open['risk'], 'pips' => $move / $pip];
                $open = null;
            }
            continue;
        }
        if (($x['atr'][$i] ?? null) === null) continue;
        $s = $sig($b, $x, $i);
        if (!$s) continue;
        $entry = $b[$i + 1]['o']; $shift = $entry - $b[$i]['c'];
        $sl = $s['sl'] + $shift; $risk = ($entry - $sl) * $s['dir'];
        if ($risk <= 0 || $risk < 0.2 * $x['atr'][$i]) continue;
        $tp = isset($s['tp']) ? $s['tp'] + $shift : null;
        if ($mode === 'own' && $tp !== null && ($tp - $entry) * $s['dir'] <= 0) continue;
        $open = ['dir' => $s['dir'], 'entry' => $entry, 'sl' => $sl, 'tp' => $tp, 'exit' => $s['exit'] ?? null, 'risk' => $risk,
            'i' => $i + 1, 't' => $b[$i + 1]['t'], 'a' => null, 'b' => null, 'bstop' => $sl,
            'tp1' => $entry + $s['dir'] * (80 / 30) * $risk, 'tp2' => $entry + $s['dir'] * (160 / 30) * $risk];
    }
    return $tr;
}

$from = (int) gmmktime(0, 0, 0, 1, 1, 2026);
$pairs = ['XAU/USD', 'EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD'];
$rows = [];
foreach (['H4' => 90, 'D1' => 30] as $tf => $maxBars) {
    foreach ($pairs as $pair) {
        $file = "$dataDir/" . str_replace('/', '_', $pair) . ($tf === 'H4' ? '_1h.csv' : '_1d.csv');
        $bars = load_bars($file);
        if ($tf === 'H4') $bars = to4h($bars);
        $x = prep_pa($bars); $x['rsi2'] = rsi_n($x['c'], 2); $x['sma5'] = sma($x['c'], 5);
        $x['sma200'] = $x['sma200'] ?? sma($x['c'], 200);
        $pip = str_starts_with($pair, 'XAU/') ? 0.1 : (str_contains($pair, 'JPY') ? 0.01 : 0.0001);
        $cost = (str_starts_with($pair, 'XAU/') ? 3.0 : 1.5) * $pip;
        foreach ($strategies as $name => $sig) {
            foreach (['own', '80/160'] as $mode) {
                $byMonth = [];
                foreach (run_trades($bars, $x, $sig, $cost, $pip, $mode, $maxBars, $from) as $t) $byMonth[gmdate('Y-m', $t['t'])][] = $t;
                for ($m = 1; $m <= 11; $m++) {
                    $key = sprintf('2026-%02d', $m);
                    $bal = 100.0; $wins = 0; $pips = 0.0; $list = $byMonth[$key] ?? [];
                    foreach ($list as $t) { $bal = max(0.0, $bal * (1 + 0.30 * $t['r'])); $wins += $t['r'] > 0 ? 1 : 0; $pips += $t['pips']; }
                    $rows[] = ['timeframe' => $tf, 'targets' => $mode, 'strategy' => $name, 'market' => $pair, 'month' => $key,
                        'trades' => count($list), 'wins' => $wins, 'pips' => round($pips, 1), 'end_balance' => round($bal, 2), 'profit' => round($bal - 100, 2)];
                }
            }
        }
        fwrite(STDERR, "$tf $pair done\n");
    }
}
echo json_encode($rows, JSON_UNESCAPED_SLASHES), "\n";
