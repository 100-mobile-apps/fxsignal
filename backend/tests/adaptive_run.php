<?php
declare(strict_types=1);
/**
 * Adaptive breakout: a 1h 20-bar breakout with the EMA200 trend, scored 0-4 for opportunity
 * (ADX>25, 4h EMA50/200 agrees, breaking out of a quiet range, London/NY hours).
 *   score <=1: no trade; score 2 ("normal"): 1.5 ATR stop, 2R target;
 *   score >=3 ("high"): 1.5 ATR stop, half off at 1R, stop to entry, rest trails 3 ATR from the best price.
 * Two sizings: equal risk; half risk on normal, full on high. Rules fixed before testing.
 * Same conventions as the other tests: next-open entry, stop first inside a bar, gaps fill at the open, costs per trade.
 * Output: compact JSON (monthly series + per-tier stats) for the dashboard.
 * Usage: php adaptive_run.php > adaptive.json
 */
ob_start();
require __DIR__ . '/pa_run.php';      // trend_run.php helpers: to4h(), ema(), atr(), adx(), hh(), ll(), sma(), bt_* ...
ob_end_clean();

function bw20(array $c): array {     // Bollinger band width (4 sd / mean) over 20 closes
    $o = []; $s = $s2 = 0.0;
    foreach ($c as $i => $x) { $s += $x; $s2 += $x * $x; if ($i >= 20) { $s -= $c[$i - 20]; $s2 -= $c[$i - 20] ** 2; }
        if ($i < 19) { $o[$i] = null; continue; } $m = $s / 20; $v = max(0, $s2 / 20 - $m * $m); $o[$i] = $m > 0 ? 4 * sqrt($v) / $m : null; }
    return $o;
}

function adaptive(array $b, callable $cost, float $unit): array {
    $n = count($b); $c = array_map(fn($x) => $x['c'], $b);
    $e200 = ema($c, 200); $at = atr($b, 14); [$adx] = adx($b); $bw = bw20($c);
    $bwAvg = sma(array_map(fn($v) => $v ?? 0.0, $bw), 200);
    // 4h trend, from completed 4h bars only
    $h4 = to4h($b); $c4 = array_map(fn($x) => $x['c'], $h4); $e50_4 = ema($c4, 50); $e200_4 = ema($c4, 200);
    $trades = []; $open = null; $k4 = -1;
    for ($i = 300; $i < $n - 1; $i++) {
        $closeT = $b[$i]['t'] + 3600;
        while ($k4 + 1 < count($h4) && $h4[$k4 + 1]['t'] + 14400 <= $closeT) $k4++;
        if ($open) {
            $k = $b[$i]; $d = $open['dir']; $res = null;
            $stopHit = (($k['o'] - $open['stop']) * $d <= 0) ? $k['o'] : (((($d > 0 ? $k['l'] : $k['h']) - $open['stop']) * $d <= 0) ? $open['stop'] : null);
            if ($stopHit !== null) $res = $stopHit;
            elseif ($open['tier'] === 'normal' && ((($d > 0 ? $k['h'] : $k['l']) - $open['tp']) * $d >= 0)) $res = $open['tp'];
            elseif ($open['tier'] === 'high' && !$open['half'] && ((($d > 0 ? $k['h'] : $k['l']) - $open['tp1']) * $d >= 0)) {
                $open['half'] = true; $open['stop'] = $open['entry'];
            }
            if ($res === null) {
                if ($open['tier'] === 'high') {
                    $open['best'] = $d > 0 ? max($open['best'], $k['h']) : min($open['best'], $k['l']);
                    $trail = $open['best'] - $d * 3 * $at[$i];
                    if ($open['half'] && ($trail - $open['stop']) * $d > 0) $open['stop'] = $trail;
                }
                if ($i - $open['i'] >= ($open['tier'] === 'high' ? 240 : 72)) $res = $k['c'];
            }
            if ($res !== null) {
                $move = $open['half'] ? 0.5 * ($open['tp1'] - $open['entry']) * $d + 0.5 * ($res - $open['entry']) * $d : ($res - $open['entry']) * $d;
                $move -= $cost($open['entry']);
                $trades[] = ['t' => $open['t'], 'tier' => $open['tier'], 'r' => $move / $open['risk'], 'pips' => $move / $unit];
                $open = null;
            }
            continue;
        }
        if ($e200[$i] === null || $at[$i] === null || $adx[$i] === null || $k4 < 200 || $bwAvg[$i] === null) continue;
        $cl = $c[$i];
        $dir = 0;
        if ($cl > $e200[$i] && $cl > hh($b, 20, $i - 1)) $dir = 1;
        elseif ($cl < $e200[$i] && $cl < ll($b, 20, $i - 1)) $dir = -1;
        if (!$dir) continue;
        $quiet = false; for ($j = $i - 20; $j < $i; $j++) if ($bw[$j] !== null && $bw[$j] < 0.7 * $bwAvg[$i]) { $quiet = true; break; }
        $hr = (int) gmdate('G', $b[$i + 1]['t']);
        $score = ($adx[$i] > 25 ? 1 : 0) + ((($e50_4[$k4] - $e200_4[$k4]) * $dir > 0) ? 1 : 0) + ($quiet ? 1 : 0) + (($hr >= 7 && $hr <= 16) ? 1 : 0);
        if ($score <= 1) continue;
        $tier = $score >= 3 ? 'high' : 'normal';
        $entry = $b[$i + 1]['o']; $risk = 1.5 * $at[$i];
        $open = ['dir' => $dir, 'entry' => $entry, 'risk' => $risk, 'stop' => $entry - $dir * $risk, 'tp' => $entry + $dir * 2 * $risk,
                 'tp1' => $entry + $dir * $risk, 'best' => $entry, 'half' => false, 'tier' => $tier, 'i' => $i + 1, 't' => $b[$i + 1]['t']];
    }
    return $trades;
}

$files = glob(__DIR__ . '/data3y/*.csv');
if (is_file(__DIR__ . '/gold_3y.csv')) $files[] = __DIR__ . '/gold_3y.csv';
$names = ['Adaptive breakout, equal size', 'Adaptive breakout, size up on high'];
$markets = []; $months = []; $agg = []; $tiers = [];
$cut = strtotime('2026-01-01 00:00:00 UTC');
foreach ($files as $f) foreach (bt_load_csv($f) as $sym => $bars) {
    $mi = count($markets); $markets[] = $sym;
    $pip = bt_pip_size($sym); $crypto = (bool) preg_match('/^(BTC|ETH)\//', $sym);
    $pct = str_starts_with($sym, 'BTC/') ? 0.0005 : (str_starts_with($sym, 'ETH/') ? 0.0007 : null);
    $cost = fn(float $p) => $pct !== null ? $pct * $p : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5) * $pip;
    $trades = adaptive($bars, $cost, $crypto ? 1.0 : $pip);
    foreach ($trades as $t) {
        $m = gmdate('Y-m', $t['t']); $months[$m] = true;
        foreach ([0, 1] as $si) {
            $w = $si === 1 && $t['tier'] === 'normal' ? 0.5 : 1.0;
            $a = &$agg[$si][$mi][$m];
            $a['n'] = ($a['n'] ?? 0) + 1; $a['w'] = ($a['w'] ?? 0) + ($t['r'] > 0 ? 1 : 0);
            $a['p'] = ($a['p'] ?? 0) + $w * $t['pips']; $a['r'] = ($a['r'] ?? 0) + $w * $t['r'];
            unset($a);
        }
        $g = &$tiers[$sym][$t['tier']];
        $g['n'] = ($g['n'] ?? 0) + 1;
        if ($t['r'] > 0) { $g['w'] = ($g['w'] ?? 0) + 1; $g['sw'] = ($g['sw'] ?? 0) + $t['r']; } else { $g['sl'] = ($g['sl'] ?? 0) + $t['r']; }
        $g['r'] = ($g['r'] ?? 0) + $t['r'];
        if ($t['t'] < $cut) $g['r_early'] = ($g['r_early'] ?? 0) + $t['r']; else $g['r_2026'] = ($g['r_2026'] ?? 0) + $t['r'];
        unset($g);
    }
}
ksort($months); $months = array_keys($months);
$out = ['months' => $months, 'markets' => $markets, 'strategies' => $names, 'series' => [], 'tiers' => []];
foreach ($agg as $si => $byM) foreach ($byM as $mi => $mm) {
    $n = $w = $p = $r = [];
    foreach ($months as $m) { $a = $mm[$m] ?? null; $n[] = $a['n'] ?? 0; $w[] = $a['w'] ?? 0; $p[] = (int) round($a['p'] ?? 0); $r[] = round($a['r'] ?? 0, 1); }
    $out['series'][] = [$si, $mi, implode(',', $n), implode(',', $w), implode(',', $p), implode(',', $r)];
}
foreach ($tiers as $sym => $tt) foreach ($tt as $tier => $g) {
    $wins = $g['w'] ?? 0; $loss = $g['n'] - $wins;
    $out['tiers'][] = [$sym, $tier, $g['n'], $wins, round($g['r'], 1), $wins ? round($g['sw'] / $wins, 2) : 0, $loss ? round(-($g['sl'] ?? 0) / $loss, 2) : 0, round($g['r_early'] ?? 0, 1), round($g['r_2026'] ?? 0, 1)];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
