<?php
// Simulates 45 days of hourly cron runs on synthetic prices, then prints stats.
putenv('FX_CONFIG=' . __DIR__ . '/test_config.php');
@unlink(__DIR__ . '/test.db');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');

$base = ['EUR/USD'=>1.085,'GBP/USD'=>1.27,'USD/JPY'=>150,'AUD/USD'=>.66,'USD/CAD'=>1.36,'USD/CHF'=>.88,'NZD/USD'=>.61,'XAU/USD'=>2350];
mt_srand(42);
$start = strtotime('2026-06-01 00:00:00 UTC');
$hours = 24 * 45 + 300;
$series = [];
foreach ($base as $pair => $p) {
  $vol = $p * 0.0015; $drift = 0; $out = [];
  for ($i = 0; $i < $hours; $i++) {
    if ($i % 60 === 0) $drift = (mt_rand() / mt_getrandmax() - 0.5) * $vol * 0.6;
    $o = $p; $g = sqrt(-2*log(max(mt_rand()/mt_getrandmax(),1e-12)))*cos(2*M_PI*mt_rand()/mt_getrandmax());
    $c = $o + $drift + $g * $vol;
    $out[] = ['t'=>$start+$i*3600,'o'=>$o,'h'=>max($o,$c)+mt_rand()/mt_getrandmax()*$vol*.6,'l'=>min($o,$c)-mt_rand()/mt_getrandmax()*$vol*.6,'c'=>$c];
    $p = $c;
  }
  $series[$pair] = $out;
}
$opened = 0; $closedLines = 0;
for ($h = 300; $h < $hours; $h++) {
  foreach ([20, 40] as $min) { // two runs per hour inside the forming candle
    $now = $start + $h * 3600 + $min * 60;
    $log = Cron::run($now, function ($pair) use ($series, $h, $min) {
      $c = array_slice($series[$pair], $h - 299, 300); // last one forming
      $last = &$c[count($c)-1];
      // partial forming candle: shrink range proportionally
      $f = $min / 60; $last['c'] = $last['o'] + ($last['c'] - $last['o']) * $f;
      $last['h'] = max($last['o'], $last['c']) + ($last['h'] - max($last['o'], $series[$pair][$h]['c'])) * $f;
      $last['l'] = min($last['o'], $last['c']) - (min($last['o'], $series[$pair][$h]['c']) - $last['l']) * $f;
      return $c;
    }, false);
    foreach ($log as $l) { if (strpos($l, 'NEW') !== false) $opened++; if (strpos($l, 'closed') !== false) $closedLines++; }
  }
}
$st = Stats::compute(Db::all("SELECT pair,pips,closed_at FROM signals WHERE status='closed'"), 0);
echo "opened=$opened closed_log=$closedLines\n";
unset($st['equity']);
echo json_encode(array_diff_key($st, ['by_pair'=>1,'monthly'=>1])), "\n";
print_r(Db::all("SELECT result, COUNT(*) n, ROUND(AVG(pips),1) avg FROM signals GROUP BY result"));
print_r(Db::one("SELECT id,pair,direction,entry,stop_loss,tp1,tp2,status,result,pips,opened_at,tp1_hit_at,closed_at FROM signals WHERE result='tp2' LIMIT 1"));
