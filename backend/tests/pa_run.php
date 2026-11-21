<?php
declare(strict_types=1);
/**
 * Price-action strategies on 1h and 4h bars across forex, gold, US index ETFs and crypto.
 * Reuses markets, costs, indicators and the simulator from trend_run.php (same rules: closed-bar signal,
 * next-open entry, stop first, gaps fill at the open, trades held over weekends/overnight).
 * Stops sit just beyond the signal candle/level (0.1 ATR buffer); single target at 2R unless stated.
 * Usage: php pa_run.php [--csv=history.csv]
 */
ob_start();
require __DIR__ . '/trend_run.php';   // $markets, to4h(), prep(), simulate(), hh(), ll(); its own report is discarded
ob_end_clean();

function prep_pa(array $b): array {
    $x = prep($b);
    // previous UTC day's high/low for every bar
    $day = []; foreach ($b as $i => $k) { $d = gmdate('Y-m-d', $k['t']); $day[$d]['h'] = max($day[$d]['h'] ?? -INF, $k['h']); $day[$d]['l'] = min($day[$d]['l'] ?? INF, $k['l']); }
    $keys = array_keys($day); $prev = []; foreach ($keys as $n => $d) $prev[$d] = $n ? $day[$keys[$n - 1]] : null;
    foreach ($b as $i => $k) { $p = $prev[gmdate('Y-m-d', $k['t'])]; $x['pdh'][$i] = $p['h'] ?? null; $x['pdl'][$i] = $p['l'] ?? null; }
    return $x;
}
function up(array $x, int $i): bool { return $x['ema200'][$i] !== null && $x['ema50'][$i] > $x['ema200'][$i] && $x['c'][$i] > $x['ema200'][$i]; }
function dn(array $x, int $i): bool { return $x['ema200'][$i] !== null && $x['ema50'][$i] < $x['ema200'][$i] && $x['c'][$i] < $x['ema200'][$i]; }
function trade(int $d, float $c, float $stop, float $r = 2.0): array { $risk = ($c - $stop) * $d; return ['dir' => $d, 'sl' => $stop, 'tp' => $c + $d * $r * $risk]; }

$P = [];
$P['Pin bar at 10-bar swing, with trend'] = function ($b, $x, $i) {
    $k = $b[$i]; $rng = $k['h'] - $k['l']; $a = $x['atr'][$i];
    if ($rng < 0.8 * $a || $rng <= 0) return null;
    $body = abs($k['c'] - $k['o']); $lw = min($k['o'], $k['c']) - $k['l']; $uw = $k['h'] - max($k['o'], $k['c']);
    if (up($x, $i) && $lw >= 0.6 * $rng && $body <= 0.33 * $rng && $k['l'] <= ll($b, 10, $i)) return trade(1, $k['c'], $k['l'] - 0.1 * $a);
    if (dn($x, $i) && $uw >= 0.6 * $rng && $body <= 0.33 * $rng && $k['h'] >= hh($b, 10, $i)) return trade(-1, $k['c'], $k['h'] + 0.1 * $a);
    return null;
};
$P['Engulfing at 10-bar swing, with trend'] = function ($b, $x, $i) {
    $k = $b[$i]; $p = $b[$i - 1]; $a = $x['atr'][$i];
    if (abs($k['c'] - $k['o']) < 0.5 * $a) return null;
    $lo = min($k['l'], $p['l']); $hi = max($k['h'], $p['h']);
    if (up($x, $i) && $p['c'] < $p['o'] && $k['c'] > $k['o'] && $k['c'] >= $p['o'] && $k['o'] <= $p['c'] && $lo <= ll($b, 10, $i)) return trade(1, $k['c'], $lo - 0.1 * $a);
    if (dn($x, $i) && $p['c'] > $p['o'] && $k['c'] < $k['o'] && $k['c'] <= $p['o'] && $k['o'] >= $p['c'] && $hi >= hh($b, 10, $i)) return trade(-1, $k['c'], $hi + 0.1 * $a);
    return null;
};
$P['Inside bar breakout, with trend'] = function ($b, $x, $i) {
    $m = $b[$i - 2]; $in = $b[$i - 1]; $k = $b[$i]; $a = $x['atr'][$i];
    if (!($in['h'] <= $m['h'] && $in['l'] >= $m['l'])) return null;
    if (up($x, $i) && $k['c'] > $m['h']) return trade(1, $k['c'], min($m['l'], $k['l']) - 0.1 * $a);
    if (dn($x, $i) && $k['c'] < $m['l']) return trade(-1, $k['c'], max($m['h'], $k['h']) + 0.1 * $a);
    return null;
};
$P['Break and retest of 20-bar high/low'] = function ($b, $x, $i) {
    $a = $x['atr'][$i]; $k = $b[$i];
    for ($j = $i - 2; $j >= $i - 10 && $j > 21; $j--) {
        $L = hh($b, 20, $j - 1);
        if ($b[$j]['c'] > $L) {                                   // upside break at bar j
            for ($q = $j + 1; $q < $i; $q++) if ($b[$q]['c'] < $L) return null;
            if ($k['l'] <= $L + 0.2 * $a && $k['c'] > $L && $k['c'] > $k['o']) return trade(1, $k['c'], min($k['l'], $L) - 0.5 * $a);
            return null;
        }
        $S = ll($b, 20, $j - 1);
        if ($b[$j]['c'] < $S) {                                   // downside break at bar j
            for ($q = $j + 1; $q < $i; $q++) if ($b[$q]['c'] > $S) return null;
            if ($k['h'] >= $S - 0.2 * $a && $k['c'] < $S && $k['c'] < $k['o']) return trade(-1, $k['c'], max($k['h'], $S) + 0.5 * $a);
            return null;
        }
    }
    return null;
};
$P['Failed breakout (2B / fakey) of 20-bar high/low'] = function ($b, $x, $i) {
    $k = $b[$i]; $a = $x['atr'][$i]; $H = hh($b, 20, $i - 1); $L = ll($b, 20, $i - 1);
    if ($k['h'] > $H && $k['c'] < $H && $k['c'] < $k['o']) return trade(-1, $k['c'], $k['h'] + 0.1 * $a);
    if ($k['l'] < $L && $k['c'] > $L && $k['c'] > $k['o']) return trade(1, $k['c'], $k['l'] - 0.1 * $a);
    return null;
};
$P['Previous-day high/low rejection candle'] = function ($b, $x, $i) {
    $k = $b[$i]; $a = $x['atr'][$i]; $H = $x['pdh'][$i]; $L = $x['pdl'][$i];
    if ($H === null) return null;
    $rng = $k['h'] - $k['l']; if ($rng <= 0) return null;
    if ($k['l'] <= $L && $k['c'] > $L && ($k['c'] - $k['l']) >= 0.6 * $rng) return trade(1, $k['c'], $k['l'] - 0.1 * $a);
    if ($k['h'] >= $H && $k['c'] < $H && ($k['h'] - $k['c']) >= 0.6 * $rng) return trade(-1, $k['c'], $k['h'] + 0.1 * $a);
    return null;
};
$P['Pin bar at 10-bar swing, with trend, 1.5R'] = function ($b, $x, $i) use (&$P) {
    $s = ($P['Pin bar at 10-bar swing, with trend'])($b, $x, $i);
    if ($s) { $e = $x['c'][$i]; $s['tp'] = $e + 1.5 * ($e - $s['sl']); }
    return $s;
};

$groups = ['Forex (7)', 'Gold', 'US100', 'US500', 'US30', 'BTC', 'ETH'];
$lateTs = strtotime('2026-08-01 00:00:00 UTC');
foreach (['1h' => 48, '4h' => 30] as $tf => $maxBars) {
    $res = [];
    foreach ($markets as $name => $m) {
        $bars = $tf === '4h' ? to4h($m['bars']) : $m['bars'];
        $x = prep_pa($bars);
        foreach ($P as $sn => $f) foreach (simulate($bars, $x, $f, $m['cost'], $maxBars) as $t) {
            $g = &$res[$sn][$m['group']];
            $g['n'] = ($g['n'] ?? 0) + 1; $g['w'] = ($g['w'] ?? 0) + ($t['r'] > 0 ? 1 : 0); $g['r'] = ($g['r'] ?? 0) + $t['r'];
            if ($t['t'] >= $lateTs) $g['late'] = ($g['late'] ?? 0) + $t['r'];
            $mo = gmdate('Y-m', $t['t']); $g['m'][$mo] = ($g['m'][$mo] ?? 0) + $t['r'];
            unset($g);
        }
    }
    printf("\n######## %s bars ########  cell = net R (win%%) [Aug-Oct R]\n", $tf);
    printf("%-44s", 'strategy'); foreach ($groups as $g) printf(" %21s", $g); printf(" %9s\n", 'ALL');
    foreach ($P as $sn => $_) {
        printf("%-44s", mb_strimwidth($sn, 0, 44)); $all = 0;
        foreach ($groups as $g) { $c = $res[$sn][$g] ?? null; $all += $c['r'] ?? 0;
            printf(" %21s", $c ? sprintf('%+6.1f (%2.0f%%) [%+5.1f]', $c['r'], 100 * $c['w'] / $c['n'], $c['late'] ?? 0) : '-'); }
        printf(" %+9.1f\n", $all);
    }
    echo "profitable months (trades):\n";
    foreach ($P as $sn => $_) {
        printf("%-44s", mb_strimwidth($sn, 0, 44));
        foreach ($groups as $g) { $c = $res[$sn][$g] ?? null; printf(" %21s", $c ? count(array_filter($c['m'], fn($v) => $v > 0)) . '/' . count($c['m']) . ' (' . $c['n'] . ')' : '-'); }
        echo "\n";
    }
}
