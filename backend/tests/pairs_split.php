<?php
declare(strict_types=1);
/**
 * Honest pair-selection test: pick pairs that were profitable in the first period (train),
 * then measure those same pairs in the later period they never saw (test).
 * Usage: php pairs_split.php [--csv=history.csv] [--split=2026-08-01]
 */
$opts = getopt('', ['csv::', 'split::']);
$splitTs = strtotime(($opts['split'] ?? '2026-08-01') . ' 00:00:00 UTC');
$argvCsv = $opts['csv'] ?? __DIR__ . '/history.csv';

ob_start();                                   // reuse strategies_run.php definitions, discard its report
$_GET = []; $GLOBALS['argv'] = ['x', '--csv=' . $argvCsv];
require __DIR__ . '/strategies_run.php';
ob_end_clean();

// Trade lists: [sym, entry_t, r]
$sets = [];

// 1) Live engine with a single 2R target (as history_run.php)
$an = static function (array $closed) {
    $a = Engine::analyze($closed, 65);
    if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
    $risk = $a['entry'] - $a['sl'];
    $a['tp1'] = $a['entry'] + 2 * $risk; $a['tp2'] = $a['entry'] + 3 * $risk;
    return $a;
};
$eng = [];
foreach ($series as $sym => $bars) {
    $o = ['exit' => 'tp1-full', 'cost-pips' => str_starts_with($sym, 'XAU/') ? 3.0 : 1.5, 'min-confidence' => 65,
          'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
    foreach (bt_simulate([$sym => $bars], $o, $an) as $t) if ($t['status'] === 'closed') $eng[] = ['sym' => $sym, 't' => $t['entry_t'], 'r' => $t['net_r']];
}
$sets['Your engine, single 2R target'] = $eng;

// 2) Public strategies from strategies_run.php
foreach ($S as $name => [$p, $f]) {
    if (!preg_match('/^(London|MACD|RSI\(2\) mean)/', $name)) continue;
    $sets[$name] = run_strategy($series, $p, $f);
}

printf("Train (choose pairs): before %s | Test (unseen): from %s\n", gmdate('Y-m-d', $splitTs), gmdate('Y-m-d', $splitTs));
foreach ($sets as $name => $trades) {
    $agg = [];
    foreach ($trades as $t) {
        $k = $t['t'] < $splitTs ? 'train' : 'test';
        $agg[$t['sym']][$k]['r'] = ($agg[$t['sym']][$k]['r'] ?? 0) + $t['r'];
        $agg[$t['sym']][$k]['n'] = ($agg[$t['sym']][$k]['n'] ?? 0) + 1;
        $agg[$t['sym']][$k]['w'] = ($agg[$t['sym']][$k]['w'] ?? 0) + ($t['r'] > 0 ? 1 : 0);
    }
    ksort($agg);
    $chosen = array_keys(array_filter($agg, fn($a) => ($a['train']['r'] ?? 0) > 0));
    $sum = function (array $syms, string $k) use ($agg) { $r = $n = $w = 0;
        foreach ($syms as $s) { $r += $agg[$s][$k]['r'] ?? 0; $n += $agg[$s][$k]['n'] ?? 0; $w += $agg[$s][$k]['w'] ?? 0; }
        return sprintf('%4d trades, win %4.1f%%, net %+6.1fR', $n, $n ? 100 * $w / $n : 0, $r); };
    printf("\n== %s ==\n", $name);
    foreach ($agg as $s => $a) printf("  %-8s train %+6.1fR (%3d)   test %+6.1fR (%3d)%s\n", $s, $a['train']['r'] ?? 0, $a['train']['n'] ?? 0,
        $a['test']['r'] ?? 0, $a['test']['n'] ?? 0, in_array($s, $chosen, true) ? '   <- chosen' : '');
    printf("  All pairs      test: %s\n", $sum(array_keys($agg), 'test'));
    printf("  Chosen pairs   test: %s   [%s]\n", $sum($chosen, 'test'), implode(', ', $chosen) ?: 'none');
}
