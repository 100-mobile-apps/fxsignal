<?php
declare(strict_types=1);
/**
 * Downloads ~3 years of hourly XAU/USD from Twelve Data by paging backwards (5000 bars per request),
 * and writes gold_3y.csv in backtest.php's format. The API key is read from the live config on the server.
 * Usage: php gold_fetch3y.php [--app=/home/USER/forexsignal.bulksend.top] [--pages=4] [--symbol=XAU/USD]
 */
$opts = getopt('', ['app::', 'pages::', 'symbol::']);
$app = rtrim($opts['app'] ?? '/home/globacom/forexsignal.bulksend.top', '/');
$pages = (int) ($opts['pages'] ?? 4);
$sym = $opts['symbol'] ?? 'XAU/USD';
$cfg = require $app . '/src/config.php';
$key = (string) ($cfg['twelvedata_key'] ?? '');

$rows = []; $end = null;
for ($p = 0; $p < $pages; $p++) {
    if ($p > 0) sleep(10);
    $q = ['symbol' => $sym, 'interval' => '1h', 'outputsize' => 5000, 'timezone' => 'UTC', 'order' => 'ASC', 'apikey' => $key];
    if ($end !== null) $q['end_date'] = $end;
    $ch = curl_init('https://api.twelvedata.com/time_series?' . http_build_query($q));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $d = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (empty($d['values'])) { echo "page $p: " . ($d['message'] ?? 'no data') . "\n"; break; }
    foreach ($d['values'] as $v) $rows[(int) strtotime($v['datetime'] . ' UTC')] = $v;
    $first = $d['values'][0]['datetime'];
    printf("page %d: %d bars %s -> %s\n", $p, count($d['values']), $first, end($d['values'])['datetime']);
    $end = gmdate('Y-m-d H:i:s', (int) strtotime($first . ' UTC') - 3600);
}
ksort($rows);
$out = "symbol,time,open,high,low,close\n";
foreach ($rows as $t => $v) {
    if ($t % 3600 !== 0) continue;
    $o = (float) $v['open']; $c = (float) $v['close'];
    $h = max((float) $v['high'], $o, $c); $l = min((float) $v['low'], $o, $c);
    $out .= sprintf("%s,%s,%s,%s,%s,%s\n", $sym, gmdate('Y-m-d\TH:00:00\Z', $t), $o, $h, $l, $c);
}
file_put_contents(__DIR__ . '/gold_3y.csv', $out);
printf("saved %d bars %s -> %s\n", count($rows), gmdate('Y-m-d', array_key_first($rows)), gmdate('Y-m-d', array_key_last($rows)));
