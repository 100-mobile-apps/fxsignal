<?php
declare(strict_types=1);
/**
 * Classic trend-following strategies on 1h and 4h bars across forex, gold, US index ETFs and crypto.
 * - Signals on a closed bar, entry at the next bar's open, one trade per market at a time.
 * - Stop checked before target inside a bar; a gap through the stop fills at the open.
 * - Trades are held across weekends / overnight sessions (no forced close on market gaps).
 * - Costs per trade: FX 1.5 pips, gold 3 pips, index ETFs 0.01%, BTC 0.05%, ETH 0.07% of price.
 * - R = net result / initial stop distance.
 * Usage: php trend_run.php [--csv=history.csv]
 */
$opts = getopt('', ['csv::']);
$_SERVER['SCRIPT_FILENAME'] = '';
require_once __DIR__ . '/backtest.php';
$fx = bt_load_csv($opts['csv'] ?? __DIR__ . '/history.csv');

// ---------- markets ----------
$markets = [];   // name => ['group'=>..., 'bars'=>[...], 'cost'=>fn(entry)=>price]
foreach ($fx as $sym => $bars) {
    $pip = bt_pip_size($sym);
    $gold = str_starts_with($sym, 'XAU/');
    $markets[$sym] = ['group' => $gold ? 'Gold' : 'Forex (7)', 'bars' => $bars, 'cost' => fn(float $p) => ($gold ? 3.0 : 1.5) * $pip];
}
foreach (['QQQ' => ['US100', 0.0001], 'SPY' => ['US500', 0.0001], 'DIA' => ['US30', 0.0001], 'BTC_USD' => ['BTC', 0.0005], 'ETH_USD' => ['ETH', 0.0007]] as $f => [$g, $pct]) {
    $file = __DIR__ . "/extra/$f.json";
    if (!is_file($file)) continue;
    $bars = [];
    foreach (json_decode((string) file_get_contents($file), true)['values'] as $x) {
        $o = (float) $x['open']; $c = (float) $x['close'];
        $bars[] = ['t' => (int) strtotime($x['datetime'] . ' UTC'), 'o' => $o, 'h' => max((float) $x['high'], $o, $c), 'l' => min((float) $x['low'], $o, $c), 'c' => $c];
    }
    $markets[$g] = ['group' => $g, 'bars' => $bars, 'cost' => fn(float $p) => $pct * $p];
}

function to4h(array $bars): array {
    $out = [];
    foreach ($bars as $b) {
        $k = intdiv($b['t'], 14400);
        if (!isset($out[$k])) $out[$k] = ['t' => $b['t'], 'o' => $b['o'], 'h' => $b['h'], 'l' => $b['l'], 'c' => $b['c']];
        else { $out[$k]['h'] = max($out[$k]['h'], $b['h']); $out[$k]['l'] = min($out[$k]['l'], $b['l']); $out[$k]['c'] = $b['c']; }
    }
    return array_values($out);
}

// ---------- indicators ----------
function col(array $b, string $k): array { return array_map(fn($x) => $x[$k], $b); }
function sma(array $v, int $n): array { $o = []; $s = 0.0; foreach ($v as $i => $x) { $s += $x; if ($i >= $n) $s -= $v[$i - $n]; $o[$i] = $i >= $n - 1 ? $s / $n : null; } return $o; }
function ema(array $v, int $n): array { $o = []; $k = 2 / ($n + 1); $e = null; foreach ($v as $i => $x) { $e = $e === null ? $x : $x * $k + $e * (1 - $k); $o[$i] = $i >= $n - 1 ? $e : null; } return $o; }
function tr(array $b, int $i): float { return $i ? max($b[$i]['h'] - $b[$i]['l'], abs($b[$i]['h'] - $b[$i - 1]['c']), abs($b[$i]['l'] - $b[$i - 1]['c'])) : $b[$i]['h'] - $b[$i]['l']; }
function atr(array $b, int $n): array { $o = []; $a = null; foreach ($b as $i => $_) { $t = tr($b, $i); $a = $a === null ? $t : ($a * ($n - 1) + $t) / $n; $o[$i] = $i >= $n ? $a : null; } return $o; }
function hh(array $b, int $n, int $i): float { $m = -INF; for ($k = max(0, $i - $n + 1); $k <= $i; $k++) $m = max($m, $b[$k]['h']); return $m; }
function ll(array $b, int $n, int $i): float { $m = INF; for ($k = max(0, $i - $n + 1); $k <= $i; $k++) $m = min($m, $b[$k]['l']); return $m; }
function adx(array $b, int $n = 14): array {
    $pdi = $mdi = $adx = []; $sTr = $sP = $sM = 0.0; $dxs = []; $a = null;
    foreach ($b as $i => $x) {
        if ($i === 0) { $pdi[0] = $mdi[0] = $adx[0] = null; continue; }
        $up = $x['h'] - $b[$i - 1]['h']; $dn = $b[$i - 1]['l'] - $x['l'];
        $p = ($up > $dn && $up > 0) ? $up : 0.0; $m = ($dn > $up && $dn > 0) ? $dn : 0.0; $t = tr($b, $i);
        if ($i <= $n) { $sTr += $t; $sP += $p; $sM += $m; } else { $sTr = $sTr - $sTr / $n + $t; $sP = $sP - $sP / $n + $p; $sM = $sM - $sM / $n + $m; }
        if ($i < $n || $sTr == 0) { $pdi[$i] = $mdi[$i] = $adx[$i] = null; continue; }
        $pdi[$i] = 100 * $sP / $sTr; $mdi[$i] = 100 * $sM / $sTr;
        $dx = ($pdi[$i] + $mdi[$i]) > 0 ? 100 * abs($pdi[$i] - $mdi[$i]) / ($pdi[$i] + $mdi[$i]) : 0;
        if ($a === null) { $dxs[] = $dx; if (count($dxs) === $n) $a = array_sum($dxs) / $n; } else $a = ($a * ($n - 1) + $dx) / $n;
        $adx[$i] = $a;
    }
    return [$adx, $pdi, $mdi];
}
function supertrend(array $b, int $n = 10, float $mult = 3.0): array {
    $at = atr($b, $n); $line = $dir = []; $fu = $fl = null; $d = 1;
    foreach ($b as $i => $x) {
        if ($at[$i] === null) { $line[$i] = $dir[$i] = null; continue; }
        $mid = ($x['h'] + $x['l']) / 2; $bu = $mid + $mult * $at[$i]; $bl = $mid - $mult * $at[$i];
        $pc = $b[$i - 1]['c'];
        $fu = ($fu === null || $bu < $fu || $pc > $fu) ? $bu : $fu;
        $fl = ($fl === null || $bl > $fl || $pc < $fl) ? $bl : $fl;
        if ($d === 1 && $x['c'] < $fl) $d = -1; elseif ($d === -1 && $x['c'] > $fu) $d = 1;
        $dir[$i] = $d; $line[$i] = $d === 1 ? $fl : $fu;
    }
    return [$line, $dir];
}
function psar(array $b, float $step = 0.02, float $max = 0.2): array {
    $sar = $dir = []; $d = 1; $af = $step; $ep = $b[0]['h']; $s = $b[0]['l'];
    foreach ($b as $i => $x) {
        if ($i === 0) { $sar[0] = $s; $dir[0] = $d; continue; }
        $s = $s + $af * ($ep - $s);
        if ($d === 1) {
            $s = min($s, $b[$i - 1]['l'], $b[max(0, $i - 2)]['l']);
            if ($x['l'] < $s) { $d = -1; $s = $ep; $ep = $x['l']; $af = $step; }
            elseif ($x['h'] > $ep) { $ep = $x['h']; $af = min($max, $af + $step); }
        } else {
            $s = max($s, $b[$i - 1]['h'], $b[max(0, $i - 2)]['h']);
            if ($x['h'] > $s) { $d = 1; $s = $ep; $ep = $x['h']; $af = $step; }
            elseif ($x['l'] < $ep) { $ep = $x['l']; $af = min($max, $af + $step); }
        }
        $sar[$i] = $s; $dir[$i] = $d;
    }
    return [$sar, $dir];
}
function prep(array $b): array {
    $c = col($b, 'c');
    [$adx, $pdi, $mdi] = adx($b);
    [$st, $stDir] = supertrend($b);
    [$sar, $sarDir] = psar($b);
    $ten = $kij = $spA = $spB = [];
    foreach ($b as $i => $_) {
        $ten[$i] = $i >= 8 ? (hh($b, 9, $i) + ll($b, 9, $i)) / 2 : null;
        $kij[$i] = $i >= 25 ? (hh($b, 26, $i) + ll($b, 26, $i)) / 2 : null;
        $spA[$i] = ($ten[$i] !== null && $kij[$i] !== null) ? ($ten[$i] + $kij[$i]) / 2 : null;
        $spB[$i] = $i >= 51 ? (hh($b, 52, $i) + ll($b, 52, $i)) / 2 : null;
    }
    return ['c' => $c, 'ema20' => ema($c, 20), 'ema50' => ema($c, 50), 'ema200' => ema($c, 200), 'sma20' => sma($c, 20), 'sma50' => sma($c, 50),
            'sma200' => sma($c, 200), 'atr' => atr($b, 14), 'adx' => $adx, 'pdi' => $pdi, 'mdi' => $mdi, 'st' => $st, 'stDir' => $stDir,
            'sar' => $sar, 'sarDir' => $sarDir, 'ten' => $ten, 'kij' => $kij, 'spA' => $spA, 'spB' => $spB];
}

// ---------- strategies: fn(bars, ind, i) -> ?['dir','sl','tp'?,'exit'?] decided on close of bar i ----------
$T = [];
$T['Supertrend (10,3) flip, exit on flip'] = function ($b, $x, $i) {
    if ($x['stDir'][$i] === null || $x['stDir'][$i - 1] === null || $x['stDir'][$i] === $x['stDir'][$i - 1]) return null;
    $d = $x['stDir'][$i];
    return ['dir' => $d, 'sl' => $x['st'][$i], 'exit' => fn($b, $x, $j) => $x['stDir'][$j] !== $d];
};
$T['Ichimoku: above/below cloud + Tenkan/Kijun cross'] = function ($b, $x, $i) {
    if ($i < 27 || $x['spB'][$i - 26] === null || $x['kij'][$i - 1] === null) return null;
    $top = max($x['spA'][$i - 26], $x['spB'][$i - 26]); $bot = min($x['spA'][$i - 26], $x['spB'][$i - 26]); $c = $x['c'][$i]; $a = $x['atr'][$i];
    $up = $x['ten'][$i] > $x['kij'][$i] && $x['ten'][$i - 1] <= $x['kij'][$i - 1];
    $dn = $x['ten'][$i] < $x['kij'][$i] && $x['ten'][$i - 1] >= $x['kij'][$i - 1];
    if ($up && $c > $top) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < $x['kij'][$j]];
    if ($dn && $c < $bot) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > $x['kij'][$j]];
    return null;
};
$T['ADX > 25 + DI cross, 2 ATR stop, exit on DI cross back'] = function ($b, $x, $i) {
    if ($x['adx'][$i] === null || $x['pdi'][$i - 1] === null || $x['adx'][$i] < 25) return null;
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($x['pdi'][$i] > $x['mdi'][$i] && $x['pdi'][$i - 1] <= $x['mdi'][$i - 1]) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['pdi'][$j] < $x['mdi'][$j]];
    if ($x['pdi'][$i] < $x['mdi'][$i] && $x['pdi'][$i - 1] >= $x['mdi'][$i - 1]) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['pdi'][$j] > $x['mdi'][$j]];
    return null;
};
$T['EMA pullback: 50>200 trend, dip to EMA20, 1.5 ATR stop, 2R'] = function ($b, $x, $i) {
    if ($x['ema200'][$i] === null) return null;
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $e = $x['ema20'][$i];
    if ($x['ema50'][$i] > $x['ema200'][$i] && $c > $x['ema50'][$i] && $b[$i]['l'] <= $e && $c > $e) return ['dir' => 1, 'sl' => $c - 1.5 * $a, 'tp' => $c + 3 * $a];
    if ($x['ema50'][$i] < $x['ema200'][$i] && $c < $x['ema50'][$i] && $b[$i]['h'] >= $e && $c < $e) return ['dir' => -1, 'sl' => $c + 1.5 * $a, 'tp' => $c - 3 * $a];
    return null;
};
$T['Turtle System 2: 55-bar breakout, 2 ATR stop, 20-bar exit'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > hh($b, 55, $i - 1)) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < ll($b, 20, $j - 1)];
    if ($c < ll($b, 55, $i - 1)) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > hh($b, 20, $j - 1)];
    return null;
};
$T['Turtle System 1: 20-bar breakout, 2 ATR stop, 10-bar exit'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i];
    if ($c > hh($b, 20, $i - 1)) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < ll($b, 10, $j - 1)];
    if ($c < ll($b, 20, $i - 1)) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > hh($b, 10, $j - 1)];
    return null;
};
$T['Keltner breakout: close beyond EMA20 +/- 2 ATR, exit at EMA20'] = function ($b, $x, $i) {
    if ($x['ema20'][$i - 1] === null) return null;
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $e = $x['ema20'][$i];
    $was = $x['c'][$i - 1] > $x['ema20'][$i - 1] + 2 * $x['atr'][$i - 1]; $wasD = $x['c'][$i - 1] < $x['ema20'][$i - 1] - 2 * $x['atr'][$i - 1];
    if ($c > $e + 2 * $a && !$was) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < $x['ema20'][$j]];
    if ($c < $e - 2 * $a && !$wasD) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > $x['ema20'][$j]];
    return null;
};
$T['Parabolic SAR flip with EMA200 filter, exit on flip'] = function ($b, $x, $i) {
    if ($x['ema200'][$i] === null || $x['sarDir'][$i] === $x['sarDir'][$i - 1]) return null;
    $d = $x['sarDir'][$i]; $c = $x['c'][$i];
    if (($d === 1 && $c < $x['ema200'][$i]) || ($d === -1 && $c > $x['ema200'][$i])) return null;
    return ['dir' => $d, 'sl' => $x['sar'][$i], 'exit' => fn($b, $x, $j) => $x['sarDir'][$j] !== $d];
};
$T['MA crossover 20/50 + SMA200 filter, exit on opposite cross'] = function ($b, $x, $i) {
    if ($x['sma200'][$i] === null) return null;
    $c = $x['c'][$i]; $a = $x['atr'][$i]; $up = $x['sma20'][$i] > $x['sma50'][$i]; $was = $x['sma20'][$i - 1] > $x['sma50'][$i - 1];
    if ($up && !$was && $c > $x['sma200'][$i]) return ['dir' => 1, 'sl' => $c - 2 * $a, 'exit' => fn($b, $x, $j) => $x['sma20'][$j] < $x['sma50'][$j]];
    if (!$up && $was && $c < $x['sma200'][$i]) return ['dir' => -1, 'sl' => $c + 2 * $a, 'exit' => fn($b, $x, $j) => $x['sma20'][$j] > $x['sma50'][$j]];
    return null;
};

function simulate(array $b, array $x, callable $sig, callable $cost, int $maxBars): array {
    $tr = []; $open = null; $n = count($b);
    for ($i = 210; $i < $n - 1; $i++) {
        if ($open) {
            $k = $b[$i]; $d = $open['dir']; $res = null;
            if (($k['o'] - $open['sl']) * $d <= 0) $res = $k['o'];
            elseif ((($d > 0 ? $k['l'] : $k['h']) - $open['sl']) * $d <= 0) $res = $open['sl'];
            elseif ($open['tp'] !== null && (($d > 0 ? $k['h'] : $k['l']) - $open['tp']) * $d >= 0) $res = $open['tp'];
            elseif (($open['exit'] && ($open['exit'])($b, $x, $i)) || $i - $open['i'] >= $maxBars) $res = $k['c'];
            if ($res === null) continue;
            $tr[] = ['t' => $open['t'], 'r' => (($res - $open['entry']) * $d - $cost($open['entry'])) / $open['risk']];
            $open = null;
            continue;
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

$groups = ['Forex (7)', 'Gold', 'US100', 'US500', 'US30', 'BTC', 'ETH'];
$lateTs = strtotime('2026-08-01 00:00:00 UTC');
foreach (['1h' => 240, '4h' => 90] as $tf => $maxBars) {
    $res = [];   // strategy => group => [n, w, r, late, months[]]
    foreach ($markets as $name => $m) {
        $bars = $tf === '4h' ? to4h($m['bars']) : $m['bars'];
        $x = prep($bars);
        foreach ($T as $sn => $f) {
            foreach (simulate($bars, $x, $f, $m['cost'], $maxBars) as $t) {
                $g = &$res[$sn][$m['group']];
                $g['n'] = ($g['n'] ?? 0) + 1; $g['w'] = ($g['w'] ?? 0) + ($t['r'] > 0 ? 1 : 0); $g['r'] = ($g['r'] ?? 0) + $t['r'];
                if ($t['t'] >= $lateTs) $g['late'] = ($g['late'] ?? 0) + $t['r'];
                $mo = gmdate('Y-m', $t['t']); $g['m'][$mo] = ($g['m'][$mo] ?? 0) + $t['r'];
                unset($g);
            }
        }
    }
    printf("\n######## %s bars ########  cell = net R (win%%) [Aug-Oct R]\n", $tf);
    printf("%-46s", 'strategy'); foreach ($groups as $g) printf(" %22s", $g); echo "\n";
    foreach ($T as $sn => $_) {
        printf("%-46s", mb_strimwidth($sn, 0, 46));
        foreach ($groups as $g) {
            $c = $res[$sn][$g] ?? null;
            printf(" %22s", $c ? sprintf('%+6.1f (%2.0f%%) [%+5.1f]', $c['r'], 100 * $c['w'] / $c['n'], $c['late'] ?? 0) : '-');
        }
        echo "\n";
    }
    echo "profitable months:\n";
    foreach ($T as $sn => $_) {
        printf("%-46s", mb_strimwidth($sn, 0, 46));
        foreach ($groups as $g) { $c = $res[$sn][$g] ?? null; printf(" %22s", $c ? count(array_filter($c['m'], fn($v) => $v > 0)) . '/' . count($c['m']) . ' (' . $c['n'] . ' tr)' : '-'); }
        echo "\n";
    }
}
