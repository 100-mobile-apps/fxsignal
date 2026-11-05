<?php
declare(strict_types=1);
/**
 * Every strategy from strategies_run.php plus the live engine (several exits), on one symbol only.
 * Usage: php gold_only.php [--csv=history.csv] [--symbol=XAU/USD] [--split=2026-08-01]
 */
$opts = getopt('', ['csv::', 'symbol::', 'split::']);
$sym = $opts['symbol'] ?? 'XAU/USD';
$splitTs = strtotime(($opts['split'] ?? '2026-08-01') . ' 00:00:00 UTC');

ob_start();
require __DIR__ . '/strategies_run.php';     // defines $series, $S, run_strategy(); its own report is discarded
ob_end_clean();
$one = [$sym => $series[$sym]];
$cost = str_starts_with($sym, 'XAU/') ? 3.0 : 1.5;

$sets = [];
foreach ([['Your engine - live rules (TP1 -> entry -> TP2)', 'tp2-be', null], ['Your engine - single 1R', 'tp1-full', null],
          ['Your engine - single 1.5R', 'tp1-full', 1.5], ['Your engine - single 2R', 'tp1-full', 2.0]] as [$name, $exit, $r]) {
    $an = $r === null ? null : static function (array $c) use ($r) {
        $a = Engine::analyze($c, 65);
        if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
        $k = $a['entry'] - $a['sl']; $a['tp1'] = $a['entry'] + $r * $k; $a['tp2'] = $a['entry'] + ($r + 1) * $k; return $a; };
    $o = ['exit' => $exit, 'cost-pips' => $cost, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
    $sets[$name] = [];
    foreach (bt_simulate($one, $o, $an) as $t) if ($t['status'] === 'closed') $sets[$name][] = ['t' => $t['entry_t'], 'r' => $t['net_r']];
}
foreach ($S as $name => [$p, $f]) $sets[$name] = run_strategy($one, $p, $f);

printf("%s only | %s -> %s | cost %.1f pips/trade | early = before %s, late = after\n", $sym,
    gmdate('Y-m-d', $series[$sym][250]['t']), gmdate('Y-m-d', end($series[$sym])['t']), $cost, gmdate('Y-m-d', $splitTs));
printf("\n%-62s %6s %6s %8s %7s %7s %8s %8s %6s\n", 'strategy', 'trades', 'win%', 'net R', 'avgWin', 'avgLoss', 'maxDD R', 'early/late', '+mon');
$monthly = [];
foreach ($sets as $name => $tr) {
    usort($tr, fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($tr); $w = 0; $sum = $peak = $dd = 0; $aw = $al = []; $e = $l = 0; $mon = [];
    foreach ($tr as $t) { $sum += $t['r']; $peak = max($peak, $sum); $dd = max($dd, $peak - $sum);
        if ($t['r'] > 0) { $w++; $aw[] = $t['r']; } else $al[] = $t['r'];
        if ($t['t'] < $splitTs) $e += $t['r']; else $l += $t['r'];
        $m = gmdate('Y-m', $t['t']); $mon[$m] = ($mon[$m] ?? 0) + $t['r']; }
    ksort($mon); $monthly[$name] = $mon;
    printf("%-62s %6d %5.1f%% %+8.1f %+7.2f %+7.2f %8.1f %+5.1f/%+5.1f %3d/%d\n", mb_strimwidth($name, 0, 62), $n, $n ? 100 * $w / $n : 0, $sum,
        $aw ? array_sum($aw) / count($aw) : 0, $al ? array_sum($al) / count($al) : 0, $dd, $e, $l,
        count(array_filter($mon, fn($v) => $v > 0)), count($mon));
}
echo "\nNet R by month\n";
$months = array_unique(array_merge(...array_map('array_keys', array_values($monthly)))); sort($months);
printf("%-34s", ''); foreach ($months as $m) printf(" %7s", $m); echo "\n";
foreach ($monthly as $name => $mon) { printf("%-34s", mb_strimwidth($name, 0, 34)); foreach ($months as $m) printf(" %+7.1f", $mon[$m] ?? 0); echo "\n"; }
