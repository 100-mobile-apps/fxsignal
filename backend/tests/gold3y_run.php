<?php
declare(strict_types=1);
/**
 * Every strategy tested so far, on ~3 years of gold, reported year by year.
 * Run: php gold3y_run.php --csv=gold_3y.csv
 * (pa_run.php/trend_run.php are included for their strategies and simulator; their own reports are discarded.)
 */
ob_start();
require __DIR__ . '/pa_run.php';      // brings trend_run.php: $T, $P, prep(), prep_pa(), simulate(), to4h(), hh(), ll(), sma(), bt_* ...
ob_end_clean();

$csv = getopt('', ['csv::'])['csv'] ?? __DIR__ . '/gold_3y.csv';
$gold = bt_load_csv($csv)['XAU/USD'];
$cost = fn(float $p) => 0.3;                       // 3 pips of $0.10
$years = array_values(array_unique(array_map(fn($b) => gmdate('Y', $b['t']), $gold)));

function rsi_w(array $c, int $n): array { $o = []; $g = $l = 0.0; foreach ($c as $i => $x) { if (!$i) { $o[0] = null; continue; } $d = $x - $c[$i - 1];
    $u = max($d, 0); $w = max(-$d, 0); if ($i <= $n) { $g += $u / $n; $l += $w / $n; } else { $g = ($g * ($n - 1) + $u) / $n; $l = ($l * ($n - 1) + $w) / $n; }
    $o[$i] = $i >= $n ? ($l == 0 ? 100 : 100 - 100 / (1 + $g / $l)) : null; } return $o; }

$extra = [];
$extra['London breakout (Asian range, 1R, 12h max)'] = function ($b, $x, $i) {
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
$extra['RSI(2) Connors (exit above SMA5, 3 ATR stop)'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; if ($x['sma200'][$i] === null) return null;
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 3 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] > $x['sma5'][$j]];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 3 * $a, 'exit' => fn($b, $x, $j) => $x['c'][$j] < $x['sma5'][$j]];
    return null;
};
$extra['"90% recipe" (0.25 ATR target, 4 ATR stop)'] = function ($b, $x, $i) {
    $c = $x['c'][$i]; $a = $x['atr'][$i]; if ($x['sma200'][$i] === null) return null;
    if ($c > $x['sma200'][$i] && $x['rsi2'][$i] < 10) return ['dir' => 1, 'sl' => $c - 4 * $a, 'tp' => $c + 0.25 * $a];
    if ($c < $x['sma200'][$i] && $x['rsi2'][$i] > 90) return ['dir' => -1, 'sl' => $c + 4 * $a, 'tp' => $c - 0.25 * $a];
    return null;
};

function stats(array $tr, array $years): array {
    usort($tr, fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($tr); $w = 0; $sum = $peak = $dd = 0; $streak = $worst = 0; $y = array_fill_keys($years, 0.0); $mon = [];
    foreach ($tr as $t) { $sum += $t['r']; $peak = max($peak, $sum); $dd = max($dd, $peak - $sum);
        if ($t['r'] > 0) { $w++; $streak = 0; } else { $streak++; $worst = max($worst, $streak); }
        $y[gmdate('Y', $t['t'])] = ($y[gmdate('Y', $t['t'])] ?? 0) + $t['r']; $m = gmdate('Y-m', $t['t']); $mon[$m] = ($mon[$m] ?? 0) + $t['r']; }
    return ['n' => $n, 'win' => $n ? 100 * $w / $n : 0, 'r' => $sum, 'dd' => $dd, 'streak' => $worst, 'y' => $y,
            'pm' => count(array_filter($mon, fn($v) => $v > 0)), 'm' => count($mon)];
}

$rows = [];
// Your engine (live code) through backtest.php on 1h bars
foreach ([['Your engine - live rules (TP1->entry->TP2)', 'tp2-be', null], ['Your engine - single 1.5R', 'tp1-full', 1.5], ['Your engine - single 2R', 'tp1-full', 2.0]] as [$name, $exit, $r]) {
    $an = $r === null ? null : static function (array $c) use ($r) {
        $a = Engine::analyze($c, 65);
        if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
        $k = $a['entry'] - $a['sl']; $a['tp1'] = $a['entry'] + $r * $k; $a['tp2'] = $a['entry'] + ($r + 1) * $k; return $a; };
    $o = ['exit' => $exit, 'cost-pips' => 3.0, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
    $tr = []; foreach (bt_simulate(['XAU/USD' => $gold], $o, $an) as $t) if ($t['status'] === 'closed') $tr[] = ['t' => $t['entry_t'], 'r' => $t['net_r']];
    $rows["1h  $name"] = stats($tr, $years);
}
foreach (['1h' => [48, 240], '4h' => [30, 90]] as $tf => [$maxPa, $maxTr]) {
    $bars = $tf === '4h' ? to4h($gold) : $gold;
    $x = prep_pa($bars);
    $c = $x['c']; $x['rsi2'] = rsi_w($c, 2); $x['sma5'] = sma($c, 5);
    if ($tf === '1h') foreach ($extra as $sn => $f) $rows["1h  $sn"] = stats(simulate($bars, $x, $f, $cost, 72), $years);
    foreach ($T as $sn => $f) $rows["$tf  $sn"] = stats(simulate($bars, $x, $f, $cost, $maxTr), $years);
    foreach ($P as $sn => $f) $rows["$tf  $sn"] = stats(simulate($bars, $x, $f, $cost, $maxPa), $years);
}

printf("GOLD (XAU/USD) %s -> %s, %d hourly bars, cost 3 pips/trade. R = multiples of risk per trade.\n",
    gmdate('Y-m-d', $gold[0]['t']), gmdate('Y-m-d', end($gold)['t']), count($gold));
uasort($rows, fn($a, $b) => $b['r'] <=> $a['r']);
printf("\n%-58s %6s %6s %8s %6s %6s", 'strategy (sorted by net R)', 'trades', 'win%', 'net R', 'maxDD', 'lose#');
foreach ($years as $yr) printf(" %7s", $yr); printf(" %8s\n", '+months');
foreach ($rows as $name => $s) {
    printf("%-58s %6d %5.1f%% %+8.1f %6.1f %6d", mb_strimwidth($name, 0, 58), $s['n'], $s['win'], $s['r'], $s['dd'], $s['streak']);
    foreach ($years as $yr) printf(" %+7.1f", $s['y'][$yr] ?? 0);
    printf(" %4d/%-3d\n", $s['pm'], $s['m']);
}
