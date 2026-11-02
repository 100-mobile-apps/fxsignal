<?php
declare(strict_types=1);
/**
 * The full strategy set from the 3-year gold test, on every data3y/*.csv market (plus gold_3y.csv), year by year.
 * Costs: FX 1.5 pips, gold 3 pips, BTC 0.05%, ETH 0.07% of price.
 * Usage: php multi3y_run.php [--top=8]
 */
ob_start();
require __DIR__ . '/pa_run.php';      // trend_run.php + price action: $T, $P, prep_pa(), simulate(), to4h(), sma(), bt_* ...
ob_end_clean();
$top = (int) (getopt('', ['top::'])['top'] ?? 8);

function rsi_w(array $c, int $n): array { $o = []; $g = $l = 0.0; foreach ($c as $i => $x) { if (!$i) { $o[0] = null; continue; } $d = $x - $c[$i - 1];
    $u = max($d, 0); $w = max(-$d, 0); if ($i <= $n) { $g += $u / $n; $l += $w / $n; } else { $g = ($g * ($n - 1) + $u) / $n; $l = ($l * ($n - 1) + $w) / $n; }
    $o[$i] = $i >= $n ? ($l == 0 ? 100 : 100 - 100 / (1 + $g / $l)) : null; } return $o; }
function stats(array $tr, array $years): array {
    usort($tr, fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($tr); $w = 0; $sum = $peak = $dd = 0; $streak = $worst = 0; $y = array_fill_keys($years, 0.0); $mon = [];
    foreach ($tr as $t) { $sum += $t['r']; $peak = max($peak, $sum); $dd = max($dd, $peak - $sum);
        if ($t['r'] > 0) { $w++; $streak = 0; } else { $streak++; $worst = max($worst, $streak); }
        $y[gmdate('Y', $t['t'])] = ($y[gmdate('Y', $t['t'])] ?? 0) + $t['r']; $m = gmdate('Y-m', $t['t']); $mon[$m] = ($mon[$m] ?? 0) + $t['r']; }
    $allPos = $n > 0; foreach ($y as $v) if ($v <= 0) $allPos = false;
    return ['n' => $n, 'win' => $n ? 100 * $w / $n : 0, 'r' => $sum, 'dd' => $dd, 'streak' => $worst, 'y' => $y, 'all' => $allPos,
            'pm' => count(array_filter($mon, fn($v) => $v > 0)), 'm' => count($mon)];
}

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

function run_market(string $sym, array $bars, array $T, array $P, array $extra): array {
    $pip = bt_pip_size($sym);
    $pct = str_starts_with($sym, 'BTC/') ? 0.0005 : (str_starts_with($sym, 'ETH/') ? 0.0007 : null);
    $closes = array_map(fn($b) => $b['c'], $bars); sort($closes); $median = $closes[intdiv(count($closes), 2)];
    $costPrice = fn(float $p) => $pct !== null ? $pct * $p : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5) * $pip;
    $costPips = $pct !== null ? $pct * $median / $pip : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5);
    $years = array_values(array_unique(array_map(fn($b) => gmdate('Y', $b['t']), $bars)));
    $rows = [];
    foreach ([['Your engine - live rules (TP1->entry->TP2)', 'tp2-be', null], ['Your engine - single 1.5R', 'tp1-full', 1.5], ['Your engine - single 2R', 'tp1-full', 2.0]] as [$name, $exit, $r]) {
        $an = $r === null ? null : static function (array $c) use ($r) {
            $a = Engine::analyze($c, 65);
            if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
            $k = $a['entry'] - $a['sl']; $a['tp1'] = $a['entry'] + $r * $k; $a['tp2'] = $a['entry'] + ($r + 1) * $k; return $a; };
        $o = ['exit' => $exit, 'cost-pips' => $costPips, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
        $tr = []; foreach (bt_simulate([$sym => $bars], $o, $an) as $t) if ($t['status'] === 'closed') $tr[] = ['t' => $t['entry_t'], 'r' => $t['net_r']];
        $rows["1h  $name"] = stats($tr, $years);
    }
    foreach (['1h' => [48, 240], '4h' => [30, 90]] as $tf => [$maxPa, $maxTr]) {
        $b = $tf === '4h' ? to4h($bars) : $bars;
        $x = prep_pa($b); $x['rsi2'] = rsi_w($x['c'], 2); $x['sma5'] = sma($x['c'], 5);
        if ($tf === '1h') foreach ($extra as $sn => $f) $rows["1h  $sn"] = stats(simulate($b, $x, $f, $costPrice, 72), $years);
        foreach ($T as $sn => $f) $rows["$tf  $sn"] = stats(simulate($b, $x, $f, $costPrice, $maxTr), $years);
        foreach ($P as $sn => $f) $rows["$tf  $sn"] = stats(simulate($b, $x, $f, $costPrice, $maxPa), $years);
    }
    return [$rows, $years];
}

$files = glob(__DIR__ . '/data3y/*.csv');
if (is_file(__DIR__ . '/gold_3y.csv')) $files[] = __DIR__ . '/gold_3y.csv';
$matrix = []; $markets = [];
foreach ($files as $f) {
    foreach (bt_load_csv($f) as $sym => $bars) {
        [$rows, $years] = run_market($sym, $bars, $T, $P, $extra);
        $markets[] = $sym;
        uasort($rows, fn($a, $b) => $b['r'] <=> $a['r']);
        printf("\n=== %s  %s -> %s  (%d bars) ===\n", $sym, gmdate('Y-m-d', $bars[0]['t']), gmdate('Y-m-d', end($bars)['t']), count($bars));
        printf("%-54s %6s %6s %8s %6s %5s", 'top strategies', 'trades', 'win%', 'net R', 'maxDD', 'lose');
        foreach ($years as $yr) printf(" %7s", $yr); echo "  +months  allYears+\n";
        $k = 0;
        foreach ($rows as $name => $s) {
            $matrix[$name][$sym] = $s;
            if ($k++ >= $top && !str_contains($name, 'live rules') && !str_contains($name, '90%')) continue;
            printf("%-54s %6d %5.1f%% %+8.1f %6.1f %5d", mb_strimwidth($name, 0, 54), $s['n'], $s['win'], $s['r'], $s['dd'], $s['streak']);
            foreach ($years as $yr) printf(" %+7.1f", $s['y'][$yr] ?? 0);
            printf("  %3d/%-3d  %s\n", $s['pm'], $s['m'], $s['all'] ? 'YES' : '');
        }
    }
}
echo "\n=== SUMMARY: net R per market (* = profitable in every year) ===\n";
printf("%-54s", 'strategy'); foreach ($markets as $m) printf(" %9s", $m); printf(" %8s %5s\n", 'FX total', '#all+');
$fx = array_filter($markets, fn($m) => !preg_match('/^(XAU|BTC|ETH)/', $m));
uksort($matrix, fn($a, $b) => array_sum(array_map(fn($s) => $s['r'], $matrix[$b])) <=> array_sum(array_map(fn($s) => $s['r'], $matrix[$a])));
foreach ($matrix as $name => $per) {
    printf("%-54s", mb_strimwidth($name, 0, 54)); $fxs = 0; $cnt = 0;
    foreach ($markets as $m) { $s = $per[$m] ?? null; printf(" %8s%s", $s ? sprintf('%+.0f', $s['r']) : '-', $s && $s['all'] ? '*' : ' ');
        if ($s && in_array($m, $fx, true)) $fxs += $s['r']; if ($s && $s['all']) $cnt++; }
    printf(" %+8.0f %5d\n", $fxs, $cnt);
}
echo "RUN DONE\n";
