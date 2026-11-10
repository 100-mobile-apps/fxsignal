<?php
declare(strict_types=1);
// 4-hour backtest: aggregates hourly bars into 00/04/08/12/16/20 UTC candles and reuses run.php's analyzers & stats.
$argv0 = $argv; $argv = [$argv0[0], $argv0[1], '-', '__none__']; ob_start(); 
putenv('EXITS=none'); require __DIR__ . '/run_h1.php'; ob_end_clean(); $argv = $argv0;
$step = 14400;
$s4 = [];
foreach ($series as $sym => $bars) {
    $cur = null;
    foreach ($bars as $b) {
        $k = intdiv($b['t'], $step) * $step;
        if (!$cur || $cur['t'] !== $k) { if ($cur) $s4[$sym][] = $cur; $cur = ['t' => $k, 'o' => $b['o'], 'h' => $b['h'], 'l' => $b['l'], 'c' => $b['c']]; }
        else { $cur['h'] = max($cur['h'], $b['h']); $cur['l'] = min($cur['l'], $b['l']); $cur['c'] = $b['c']; }
    }
    $s4[$sym][] = $cur;
}
function sim(array $series, array $o, callable $an, int $step): array {
    $trades = [];
    foreach ($series as $symbol => $bars) {
        $active = null; $cool = 0; $pip = bt_pip_size($symbol);
        for ($i = 300, $n = count($bars); $i < $n; $i++) {
            $bar = $bars[$i];
            if ($active !== null) {
                if (bt_check_bar($trades[$active], $bar, $o)) { $cool = $trades[$active]['closed_t'] + $o['cooldown-hours'] * 3600; $active = null; }
                continue;
            }
            if ($bar['t'] < $cool || !TradingPolicy::canPublish($bar['t'])) continue;
            $a = $an(array_slice($bars, $i - 300, 300), $symbol);
            if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) continue;
            $dir = $a['direction'] === 'BUY' ? 1 : -1; $entry = $bar['o'];
            if (!TradingPolicy::entryWithinAtr((float) $entry, (float) $a['entry'], (float) $a['atr'], 0.5)) continue;
            $sh = $entry - $a['entry'];
            $t = ['symbol' => $symbol, 'entry_t' => $bar['t'], 'entry' => $entry, 'dir' => $dir, 'sl' => $a['sl'] + $sh, 'tp1' => $a['tp1'] + $sh, 'tp2' => $a['tp2'] + $sh,
                  'pip' => $pip, 'risk_pips' => abs($a['sl'] - $a['entry']) / $pip, 'expires' => $bar['t'] + intdiv($o['expiry-hours'] * 3600, $step) * $step,
                  'tp1_hit' => false, 'exit' => $o['exit'], 'status' => 'open'];
            if (($t['entry'] - $t['sl']) * $dir <= 0 || ($t['tp1'] - $t['entry']) * $dir <= 0) continue;
            $trades[] = $t; $active = count($trades) - 1;
            if (bt_check_bar($trades[$active], $bar, $o)) { $cool = $trades[$active]['closed_t'] + $o['cooldown-hours'] * 3600; $active = null; }
        }
    }
    return $trades;
}
foreach (array_slice($argv, 3) as $v) {
    foreach (explode(',', getenv('EXITS4') ?: 'tp1-full') as $exit) {
        $o = ['cost-pips' => 0.0, 'cooldown-hours' => (int) (getenv('COOL') ?: 4), 'expiry-hours' => (int) (getenv('EXP') ?: 120), 'exit' => $exit];
        $tr = sim($s4, $o, variant($v), $step);
        $a = stats($tr, $IS, $COST); $b = stats($tr, $OOS, $COST);
        printf("H4 %-9s %-34s | IS n=%4d %4.1f/wk tp1=%5.1f%% exp=%+.3fR tot=%+6.1fR dd=%5.1fR | OOS n=%4d %4.1f/wk tp1=%5.1f%% exp=%+.3fR tot=%+6.1fR dd=%5.1fR\n",
            $exit, $v, $a['n'], $a['per_wk'], $a['tp1%'], $a['expR'], $a['totR'], $a['ddR'], $b['n'], $b['per_wk'], $b['tp1%'], $b['expR'], $b['totR'], $b['ddR']);
        if ($argv[2] === 'pairs') echo '   IS ', json_encode($a['pairs']), "\n   OOS ", json_encode($b['pairs']), "\n";
    }
}
