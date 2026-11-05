<?php
// Local checks for the strategy layer: sane levels, watch-only markets stay silent, ranking, money maths, API output.
putenv('FX_CONFIG=' . __DIR__ . '/test_config.php');
@unlink(__DIR__ . '/test.db');
$cfg = require __DIR__ . '/test_config.php';
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');
$GLOBALS['__CONFIG']['watch_pairs'] = ['EUR/GBP', 'BTC/USD'];
$GLOBALS['__CONFIG']['default_pairs'] = ['XAU/USD'];
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; echo ($c ? '  PASS ' : '  FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }

// ---- synthetic market with trends and ranges ----
mt_srand(7);
function series(float $p, int $n, int $start, float $vol): array {
    $out = []; $drift = 0.0;
    for ($i = 0; $i < $n; $i++) {
        if ($i % 120 === 0) $drift = (mt_rand() / mt_getrandmax() - 0.5) * $vol * 0.5;
        $g = sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
        $o = $p; $c = $o + $drift + $g * $vol;
        $out[] = ['t' => $start + $i * 3600, 'o' => $o, 'h' => max($o, $c) + mt_rand() / mt_getrandmax() * $vol * .5, 'l' => min($o, $c) - mt_rand() / mt_getrandmax() * $vol * .5, 'c' => $c];
        $p = $c;
    }
    return $out;
}
$start = strtotime('2026-05-04 00:00:00 UTC');
$N = 3000;
$base = ['EUR/USD' => [1.085, .0012], 'XAU/USD' => [2350, 3.5], 'USD/JPY' => [150, .12], 'EUR/GBP' => [.85, .0009], 'BTC/USD' => [65000, 180]];
$data = [];
foreach ($base as $pair => [$p, $v]) $data[$pair] = series($p, $N, $start, $v);

echo "== 1. Every strategy returns sane levels ==\n";
$counts = [];
foreach (Strategies::keys() as $k) {
    $n = 0; $bad = 0;
    foreach (['EUR/USD', 'XAU/USD'] as $pair) {
        for ($i = 1100; $i < $N; $i += 1) {
            $a = Strategies::analyze($k, array_slice($data[$pair], $i - 1100, 1100));
            if (!$a || $a['direction'] === 'WAIT') continue;
            $n++;
            $buy = $a['direction'] === 'BUY';
            $okl = $buy ? ($a['sl'] < $a['entry'] && $a['entry'] < $a['tp1'] && $a['tp1'] < $a['tp2'])
                        : ($a['sl'] > $a['entry'] && $a['entry'] > $a['tp1'] && $a['tp1'] > $a['tp2']);
            if (!$okl) $bad++;
        }
    }
    $counts[$k] = $n;
    ok($bad === 0, "$k: $n setups, every one has stop/TP1/TP2 on the right side");
}
ok(count(array_filter($counts)) >= 4, 'at least four strategies produce setups on test data: ' . json_encode($counts));

echo "\n== 2. Cron runs all strategies; watch markets stay silent ==\n";
$GLOBALS['__CONFIG']['pairs'] = ['EUR/USD', 'XAU/USD', 'USD/JPY'];
$logs = 0;
for ($h = 1200; $h < 2400; $h++) {
    foreach ([10] as $min) {
        $now = $start + $h * 3600 + $min * 60;
        $log = Cron::run($now, function ($pair) use ($data, $h, $min) {
            $c = array_slice($data[$pair], $h - 1199, 1200);
            $last = &$c[count($c) - 1];
            $f = $min / 60; $last['c'] = $last['o'] + ($last['c'] - $last['o']) * $f;
            $last['h'] = max($last['o'], $last['c']) + 0.0; $last['l'] = min($last['o'], $last['c']) - 0.0;
            return $c;
        }, false);
        $logs += count($log);
    }
}
$by = Db::all("SELECT strategy, published, COUNT(*) n, SUM(status='closed') closed FROM signals GROUP BY strategy, published");
echo '  ' . json_encode($by) . "\n";
$strategiesUsed = array_unique(array_column($by, 'strategy'));
ok(count($strategiesUsed) >= 3, 'signals from several strategies were published: ' . implode(',', $strategiesUsed));
$wp = Db::one("SELECT COUNT(*) n FROM signals WHERE pair IN ('EUR/GBP','BTC/USD') AND published = 1")['n'];
$ws = Db::one("SELECT COUNT(*) n FROM signals WHERE pair IN ('EUR/GBP','BTC/USD')")['n'];
ok((int) $wp === 0 && (int) $ws > 0, "watch-only markets are tracked but never published ($ws watch signals recorded, $wp published)");
ok((int) Db::one("SELECT COUNT(*) n FROM email_queue")['n'] === 0, 'no emails or alerts were queued for watch-only markets');
$dupe = Db::one("SELECT COUNT(*) n FROM (SELECT pair, strategy FROM signals WHERE status='running' GROUP BY pair, strategy HAVING COUNT(*) > 1) t")['n'];
ok((int) $dupe === 0, 'never two running signals for the same market and strategy');
$meta = Db::one("SELECT v FROM meta WHERE k='market_rank'");
ok($meta !== null, 'ranking stored in meta');

echo "\n== 3. Ranking and defaults ==\n";
$rank = MarketRank::refresh($start + 2400 * 3600);
ok(isset($rank['markets']) && count($rank['markets']) === 3, 'market table covers every live market');
ok(count($rank['top']) <= 3, 'at most 3 markets are marked top: ' . json_encode($rank['top']));
ok(Strategies::valid(MarketRank::bestStrategy()) && !Strategies::INFO[MarketRank::bestStrategy()]['optin'], 'default strategy is never the 90% opt-in: ' . MarketRank::bestStrategy());
ok(in_array('XAU/USD', default_pairs(), true) || count(default_pairs()) >= 1, 'default markets always exist: ' . json_encode(default_pairs()));

echo "\n== 4. Money maths ==\n";
$sigs = Db::all("SELECT * FROM signals WHERE status='closed' AND published=1 ORDER BY closed_at, id");
$s1 = Perf::simulate($sigs, 20000, 1)['summary'];
$s30 = Perf::simulate($sigs, 20000, 30)['summary'];
ok($s1['trades'] === count($sigs) && $s30['trades'] === count($sigs), 'every closed signal counted: ' . count($sigs));
ok($s30['max_drop_pct'] >= $s1['max_drop_pct'], "30% risk never has a smaller drop than 1% ({$s1['max_drop_pct']}% vs {$s30['max_drop_pct']}%)");
$one = [['id' => 1, 'pair' => 'XAU/USD', 'direction' => 'BUY', 'entry' => 2000.0, 'stop_loss' => 1980.0, 'tp1' => 2020.0, 'tp2' => 2040.0, 'pips' => -200.0, 'result' => 'sl', 'closed_at' => '2026-10-01 10:00:00']];
$r = Perf::simulate($one, 20000, 30)['summary'];
// stop = 200 pips, costs 3 pips -> loses 1.015x the amount risked: 30% of 20,000 = 6,000
ok(abs($r['profit'] - (-6090)) < 1, "a gold stop-out at 30% on 20,000 loses about 6,090 (got {$r['profit']})");
$small = Perf::simulate($one, 100, 30)['summary'];
ok(abs($small['profit'] - (-30.45)) < 0.1, "same trade on a 100 account loses about 30.45 (got {$small['profit']})");

echo "\n== 5. API payloads ==\n";
Db::exec("INSERT INTO users (id, name, email, password_hash, email_verified_at, created_at) VALUES (1,'T','t@x.com','x',?,?)", [now(), now()]);
$_GET = ['account' => '100', 'risk' => '30'];
ob_start();
try { $GLOBALS['__fake_user'] = 1; } catch (Throwable $e) {}
ob_end_clean();
// Results::show needs a logged-in user; check the maths side directly and the formatter
$f = Signals::format(Db::one('SELECT * FROM signals LIMIT 1'));
ok(isset($f['strategy'], $f['stop_pips'], $f['tp1_pips'], $f['tp2_pips'], $f['pip_value_per_lot']), 'signals carry strategy, pip distances and pip value for the money calculator');
ok(pip_value_per_lot('EUR/USD', 1.1) === 10.0 && pip_value_per_lot('XAU/USD', 2000) === 10.0, 'pip values for EUR/USD and gold');
ok(abs(spread_pips('BTC/USD', 60000) - 30.0) < 1e-6, 'BTC cost is 0.05% of price');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
