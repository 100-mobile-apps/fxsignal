<?php
// Gold live test: the gold-only breakout strategy and the /gold-test progress figures.
putenv('FX_CONFIG=' . __DIR__ . '/test_config.php');
ini_set('display_errors', 'stderr');
@unlink(__DIR__ . '/test.db');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; echo ($c ? '  PASS ' : '  FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }

echo "== Gold breakout strategy ==\n";
// 1h candles: a quiet range, then the last full 4-hour block closes well above the range.
$t0 = intdiv(strtotime('2026-09-01 00:00:00 UTC'), 14400) * 14400;
$c = [];
for ($i = 0; $i < 1200; $i++) {
    $p = 2350 + sin($i / 7) * 4;
    $c[] = ['t' => $t0 + $i * 3600, 'o' => $p, 'h' => $p + 2, 'l' => $p - 2, 'c' => $p + 0.5];
}
$flat = Strategies::analyze('turtle', $c);
ok($flat === null, 'no signal inside the range');
for ($k = 0; $k < 4; $k++) { $p = 2365 + $k * 6; $c[] = ['t' => $t0 + (1200 + $k) * 3600, 'o' => $p, 'h' => $p + 6, 'l' => $p - 1, 'c' => $p + 5]; }
$a = Strategies::analyze('turtle', $c);
ok($a !== null && $a['direction'] === 'BUY', 'buys a 4-hour close above the last 20 highs (' . ($a['direction'] ?? 'none') . ')');
if ($a) {
    $risk = $a['entry'] - $a['sl'];
    ok($risk > 0 && abs(($a['tp1'] - $a['entry']) / $risk - 80 / 30) < 1e-6, 'TP1 is 2.67x the stop');
    ok(abs(($a['tp2'] - $a['entry']) / $risk - 160 / 30) < 1e-6, 'TP2 is 5.33x the stop');
}
ok(Strategies::pairs('turtle') === ['XAU/USD'] && Strategies::pairs('steady') === null, 'gold breakout is limited to gold');
$m = new ReflectionMethod('Cron', 'maybeOpen');
$msg = $m->invoke(null, 'EUR/USD', 'turtle', false, '1h', 3600, $c, $c, time());
ok(str_contains($msg, 'not traded on this market'), 'cron skips it on other markets (' . $msg . ')');

echo "== /gold-test figures ==\n";
$ins = function (string $pair, string $strat, string $opened, ?float $pips, string $status = 'closed', int $pub = 1) {
    Db::exec("INSERT INTO signals (pair,timeframe,direction,entry,stop_loss,tp1,tp2,confidence,reasons,status,result,opened_at,expires_at,closed_at,strategy,published,candle_t,updated_at,pips) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)",
        [$pair, '1h', 'BUY', 2350.0, 2340.0, 2376.7, 2403.3, 70, '[]', $status, $status === 'closed' ? 'tp2' : null, $opened, $opened, $status === 'closed' ? $opened : null, $strat, $pub, $opened, $pips]);
};
$ins('XAU/USD', 'steady', '2026-11-05 10:00:00', 200.0);     // +2R before costs
$ins('XAU/USD', 'turtle', '2026-11-06 10:00:00', -100.0);    // -1R
$ins('XAU/USD', 'turtle', '2026-11-07 10:00:00', 400.0);     // +4R
$ins('XAU/USD', 'turtle', '2026-11-08 10:00:00', null, 'running');
$ins('XAU/USD', 'classic', '2026-11-05 10:00:00', 500.0);    // other strategy: not counted
$ins('EUR/USD', 'steady', '2026-11-05 10:00:00', 50.0);      // other market: not counted
$ins('XAU/USD', 'steady', '2026-11-02 10:00:00', -623.0);    // before the start: not counted
$ins('XAU/USD', 'turtle', '2026-11-09 10:00:00', 300.0, 'closed', 0);   // unpublished: not counted
$g = GoldTest::compute(strtotime('2026-11-10 12:00:00 UTC'));
ok($g['trades'] === 3 && $g['running'] === 1, 'counts only gold, test strategies, published, from 4 Nov (' . $g['trades'] . ' closed, ' . $g['running'] . ' running)');
ok($g['day'] === 7 && $g['status'] === 'running', 'day 7, still running');
ok($g['checks'][0]['ok'] && $g['checks'][0]['value'] > 0, 'profit after costs is positive');
$pf = $g['checks'][1]['value'];
ok($pf !== null && $pf > 4 && $pf < 6 && $g['checks'][1]['ok'], "profit factor about 5 after costs ($pf)");
$per = array_column($g['strategies'], null, 'strategy');
ok($per['steady']['closed'] === 1 && $per['turtle']['closed'] === 2 && $per['turtle']['running'] === 1, 'per-strategy counts');
ok($g['rules']['min_trades'] === 60 && $g['rules']['min_days'] === 90 && $g['rules']['max_drop_pct'] == 15.0, 'rules are returned with the figures');

for ($i = 0; $i < 9; $i++) $ins('XAU/USD', 'turtle', sprintf('2026-11-%02d 11:00:00', 11 + $i), -100.0);
$g = GoldTest::compute(strtotime('2026-11-25 12:00:00 UTC'));
ok($g['status'] === 'failed' && !$g['checks'][2]['ok'], 'a drop of 15% or more fails the test at once (drop ' . $g['checks'][2]['value'] . '%)');

echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
