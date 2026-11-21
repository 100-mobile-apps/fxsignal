<?php
declare(strict_types=1);
/**
 * Runs the live engine (3 exits) and every strategy from strategies_run.php on extra markets cached by fetch_extra.php,
 * with gold from history.csv as the benchmark.
 * - Costs are a % of price (index CFD ~0.01%, BTC 0.05%, ETH 0.07%); prices are rescaled so that cost = 1.5 "pips",
 *   which leaves every R result unchanged (engine and strategies are scale-free).
 * - ETFs (QQQ/SPY/DIA) only have regular-session bars (~7 a day); sessions are joined back-to-back on a synthetic
 *   hourly clock, so a held trade carries over the overnight gap (a gap through the stop fills at the next open).
 *   London breakout is skipped for them. Months and periods are reported on real dates.
 * Usage: php extra_run.php [--csv=history.csv]
 */
ob_start();
require __DIR__ . '/strategies_run.php';     // $series (FX + gold), $S, run_strategy(), bt_* helpers
ob_end_clean();

$markets = [
    'US100 (QQQ)' => ['file' => 'QQQ', 'cost' => 0.0001, 'session' => true],
    'US500 (SPY)' => ['file' => 'SPY', 'cost' => 0.0001, 'session' => true],
    'US30 (DIA)'  => ['file' => 'DIA', 'cost' => 0.0001, 'session' => true],
    'BTC/USD'     => ['file' => 'BTC_USD', 'cost' => 0.0005, 'session' => false],
    'ETH/USD'     => ['file' => 'ETH_USD', 'cost' => 0.0007, 'session' => false],
];
$recentTs = strtotime('2026-04-18 00:00:00 UTC');
$lateTs = strtotime('2026-08-01 00:00:00 UTC');

$data = [];   // name => ['bars' => [...], 'real' => [synthetic_t => real_t]]
$data['GOLD (XAU/USD)'] = ['bars' => $series['XAU/USD'], 'real' => null, 'session' => false, 'gold' => true];
foreach ($markets as $name => $m) {
    $file = __DIR__ . '/extra/' . $m['file'] . '.json';
    if (!is_file($file)) { echo "$name: missing $file (run fetch_extra.php)\n"; continue; }
    $v = json_decode((string) file_get_contents($file), true)['values'];
    $closes = array_map(fn($x) => (float) $x['close'], $v); sort($closes);
    $s = 0.00015 / ($m['cost'] * $closes[intdiv(count($closes), 2)]);
    $bars = []; $real = [];
    $base = strtotime('2020-01-06 00:00:00 UTC');           // a Monday; synthetic clock for session markets
    foreach ($v as $k => $x) {
        $rt = (int) strtotime($x['datetime'] . ' UTC');
        $t = $m['session'] ? $base + 3600 * $k : intdiv($rt, 3600) * 3600;
        $o = (float) $x['open'] * $s; $c = (float) $x['close'] * $s;
        $h = max((float) $x['high'] * $s, $o, $c); $l = min((float) $x['low'] * $s, $o, $c);
        if ($bars && $t <= end($bars)['t']) continue;
        $bars[] = ['t' => $t, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
        $real[$t] = $rt;
    }
    $data[$name] = ['bars' => $bars, 'real' => $real, 'session' => $m['session'], 'gold' => false];
}

function engine_trades(array $bars, string $name, string $exit, ?float $r, float $cost): array {
    $an = $r === null ? null : static function (array $c) use ($r) {
        $a = Engine::analyze($c, 65);
        if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
        $k = $a['entry'] - $a['sl']; $a['tp1'] = $a['entry'] + $r * $k; $a['tp2'] = $a['entry'] + ($r + 1) * $k; return $a; };
    $o = ['exit' => $exit, 'cost-pips' => $cost, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
    $out = [];
    foreach (bt_simulate([$name => $bars], $o, $an) as $t) if ($t['status'] === 'closed') $out[] = ['t' => $t['entry_t'], 'r' => $t['net_r']];
    return $out;
}

foreach ($data as $name => $d) {
    $bars = $d['bars'];
    $rt = fn(int $t) => $d['real'] === null ? $t : ($d['real'][$t] ?? $t);
    $first = $rt($bars[300]['t']); $last = $rt(end($bars)['t']);
    $sym = $d['gold'] ? 'XAU/USD' : 'MKT/USD';
    $cost = $d['gold'] ? 3.0 : 1.5;
    printf("\n=== %s | %s -> %s | %d bars%s ===\n", $name, gmdate('Y-m-d', $first), gmdate('Y-m-d', $last), count($bars),
        $d['session'] ? ' | regular US session only, ~7 bars/day' : '');
    printf("%-50s %6s %6s %8s %7s %9s %9s %7s\n", 'strategy', 'trades', 'win%', 'net R', 'maxDD', 'since Apr', 'Aug-Oct', '+months');
    $sets = [
        'Your engine - live rules (TP1->entry->TP2)' => engine_trades($bars, $sym, 'tp2-be', null, $cost),
        'Your engine - single 1.5R' => engine_trades($bars, $sym, 'tp1-full', 1.5, $cost),
        'Your engine - single 2R' => engine_trades($bars, $sym, 'tp1-full', 2.0, $cost),
    ];
    foreach ($S as $sn => [$p, $f]) {
        if ($d['session'] && str_starts_with($sn, 'London')) continue;
        $sets[$sn] = run_strategy([$sym => $bars], $p, $f);
    }
    foreach ($sets as $sn => $tr) {
        usort($tr, fn($a, $b) => $a['t'] <=> $b['t']);
        $n = count($tr); $w = 0; $sum = $peak = $dd = 0; $rec = $late = 0; $mon = [];
        foreach ($tr as $t) { $real = $rt($t['t']); $sum += $t['r']; $peak = max($peak, $sum); $dd = max($dd, $peak - $sum);
            if ($t['r'] > 0) $w++;
            if ($real >= $recentTs) $rec += $t['r'];
            if ($real >= $lateTs) $late += $t['r'];
            $m = gmdate('Y-m', $real); $mon[$m] = ($mon[$m] ?? 0) + $t['r']; }
        printf("%-50s %6d %5.1f%% %+8.1f %7.1f %+9.1f %+9.1f %3d/%-3d\n", mb_strimwidth($sn, 0, 50), $n, $n ? 100 * $w / $n : 0, $sum, $dd, $rec, $late,
            count(array_filter($mon, fn($x) => $x > 0)), count($mon));
    }
}
