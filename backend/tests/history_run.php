<?php
declare(strict_types=1);
/**
 * One-off history test for the live signal engine.
 * - Reads the Twelve Data key from the live config (the key never leaves the server).
 * - Downloads up to 5000 hourly candles per pair (cached to history.csv next to this file).
 * - Replays the live Engine through backtest.php with several exit rules, net of costs.
 *
 * Usage: php history_run.php [--app=/home/USER/forexsignal.bulksend.top] [--csv=history.csv] [--refresh]
 */

$opts = getopt('', ['app::', 'csv::', 'refresh']);
$app = rtrim($opts['app'] ?? '/home/globacom/forexsignal.bulksend.top', '/');
$csv = $opts['csv'] ?? __DIR__ . '/history.csv';

$_SERVER['SCRIPT_FILENAME'] = '';            // stop backtest.php running its own CLI
require_once __DIR__ . '/backtest.php';

$pairs = ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'XAU/USD'];

if (!is_file($csv) || isset($opts['refresh'])) {
    $cfg = require $app . '/src/config.php';
    $key = (string) ($cfg['twelvedata_key'] ?? '');
    if ($key === '') { fwrite(STDERR, "twelvedata_key missing in config\n"); exit(1); }
    $out = "symbol,time,open,high,low,close\n";
    foreach ($pairs as $i => $pair) {
        if ($i > 0) sleep(10);               // stay well under the free-plan 8 requests/minute
        $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
            'symbol' => $pair, 'interval' => '1h', 'outputsize' => 5000,
            'timezone' => 'UTC', 'order' => 'ASC', 'apikey' => $key,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $data = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (empty($data['values'])) { fwrite(STDERR, "$pair: " . ($data['message'] ?? 'no data') . "\n"); continue; }
        $n = 0;
        foreach ($data['values'] as $v) {
            $t = gmdate('Y-m-d\TH:00:00\Z', (int) strtotime($v['datetime'] . ' UTC'));
            $o = (float) $v['open']; $h = (float) $v['high']; $l = (float) $v['low']; $c = (float) $v['close'];
            $h = max($h, $o, $c); $l = min($l, $o, $c);
            $out .= "$pair,$t,$o,$h,$l,$c\n"; $n++;
        }
        echo "$pair: $n bars {$data['values'][0]['datetime']} -> " . end($data['values'])['datetime'] . "\n";
    }
    file_put_contents($csv, $out);
}

$series = bt_load_csv($csv);
$costs = static fn(string $s): float => str_starts_with($s, 'XAU/') ? 3.0 : 1.5;  // spread + slippage, pips

// Exit rules to compare. "R" targets replace TP1 with entry ± R × stop distance and close the whole trade there.
$rules = [
    'Live app now (TP1 -> move stop to entry, then TP2)' => ['exit' => 'tp2-be'],
    'Half off at TP1, rest to TP2 / entry'               => ['exit' => 'tp1-half'],
    'Single target at TP1'                               => ['exit' => 'tp1-full'],
    'Single target at 1.0R'                              => ['exit' => 'tp1-full', 'r' => 1.0],
    'Single target at 1.5R'                              => ['exit' => 'tp1-full', 'r' => 1.5],
    'Single target at 2.0R'                              => ['exit' => 'tp1-full', 'r' => 2.0],
];

$first = PHP_INT_MAX; $last = 0;
foreach ($series as $bars) { $first = min($first, $bars[300]['t'] ?? PHP_INT_MAX); $last = max($last, end($bars)['t'] + 3600); }
printf("\nPeriod tested: %s -> %s UTC (after 300-bar warm-up)\n", gmdate('Y-m-d', $first), gmdate('Y-m-d', $last));

foreach ($rules as $name => $rule) {
    $analyzer = null;
    if (isset($rule['r'])) {
        $r = $rule['r'];
        $analyzer = static function (array $closed) use ($r) {
            $a = Engine::analyze($closed, 65);
            if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || $a['sl'] === null) return $a;
            $risk = $a['entry'] - $a['sl'];
            $a['tp1'] = $a['entry'] + $r * $risk;
            $a['tp2'] = $a['entry'] + ($r + 1) * $risk;
            return $a;
        };
    }
    $all = []; $rows = [];
    foreach ($series as $sym => $bars) {
        $o = ['exit' => $rule['exit'], 'cost-pips' => $costs($sym), 'min-confidence' => 65,
              'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5];
        $t = bt_simulate([$sym => $bars], $o, $analyzer ? static fn($c) => $analyzer($c) : null);
        $all = array_merge($all, $t);
        $s = bt_summary($t, $first, $last);
        $rows[$sym] = $s;
    }
    $tot = bt_summary($all, $first, $last);
    printf("\n== %s ==\n", $name);
    printf("%-8s %6s %6s %6s %6s %8s %9s %8s\n", 'pair', 'trades', 'wins', 'zero', 'loss', 'win%', 'net R', 'max DD R');
    foreach ($rows + ['TOTAL' => $tot] as $sym => $s) {
        printf("%-8s %6d %6d %6d %6d %7s%% %9.1f %8.1f\n", $sym, $s['resolved'], $s['wins'], $s['zeros'], $s['losses'],
            $s['resolved_trade_win_rate_pct'] ?? '-', $s['closed_trade_net_r_total'], $s['closed_trade_max_drawdown_r']);
    }
    // month-by-month total R, to see consistency
    $m = [];
    foreach ($all as $tr) if (($tr['status'] ?? '') === 'closed') { $k = gmdate('Y-m', $tr['entry_t']); $m[$k] = ($m[$k] ?? 0) + $tr['net_r']; }
    ksort($m);
    echo 'by month (net R): ' . implode('  ', array_map(fn($k, $v) => "$k " . sprintf('%+.1f', $v), array_keys($m), $m)) . "\n";
}
