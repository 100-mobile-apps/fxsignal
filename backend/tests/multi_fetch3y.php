<?php
declare(strict_types=1);
/**
 * Downloads ~3 years of hourly candles for several symbols (4 pages x 5000 bars, paging backwards) into data3y/.
 * Skips symbols already downloaded. Waits and retries when the per-minute API limit is hit.
 * Usage: php multi_fetch3y.php [--app=/home/USER/forexsignal.bulksend.top] [--pages=4] [SYMBOL ...]
 */
$opts = getopt('', ['app::', 'pages::'], $rest);
$app = rtrim($opts['app'] ?? '/home/globacom/forexsignal.bulksend.top', '/');
$pages = (int) ($opts['pages'] ?? 4);
$syms = array_slice($argv, $rest) ?: ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'BTC/USD', 'ETH/USD'];
$cfg = require $app . '/src/config.php';
$key = (string) ($cfg['twelvedata_key'] ?? '');
@mkdir(__DIR__ . '/data3y');

function td_get(array $q): array {
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init('https://api.twelvedata.com/time_series?' . http_build_query($q));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $d = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        if (!empty($d['values'])) return $d;
        $msg = (string) ($d['message'] ?? '');
        if (stripos($msg, 'minute') === false && stripos($msg, 'limit') === false) return $d;
        echo "  rate limit, waiting 65s\n"; sleep(65);
    }
    return $d;
}

foreach ($syms as $sym) {
    $file = __DIR__ . '/data3y/' . str_replace('/', '_', $sym) . '.csv';
    if (is_file($file)) { echo "$sym: already downloaded\n"; continue; }
    $rows = []; $end = null;
    for ($p = 0; $p < $pages; $p++) {
        sleep(10);
        $q = ['symbol' => $sym, 'interval' => '1h', 'outputsize' => 5000, 'timezone' => 'UTC', 'order' => 'ASC', 'apikey' => $key];
        if ($end !== null) $q['end_date'] = $end;
        $d = td_get($q);
        if (empty($d['values'])) { echo "$sym page $p: " . ($d['message'] ?? 'no data') . "\n"; break; }
        foreach ($d['values'] as $v) $rows[(int) strtotime($v['datetime'] . ' UTC')] = $v;
        $first = $d['values'][0]['datetime'];
        $end = gmdate('Y-m-d H:i:s', (int) strtotime($first . ' UTC') - 3600);
    }
    if (!$rows) continue;
    ksort($rows);
    $out = "symbol,time,open,high,low,close\n";
    foreach ($rows as $t => $v) {
        if ($t % 3600 !== 0) continue;
        $o = (float) $v['open']; $c = (float) $v['close'];
        $out .= sprintf("%s,%s,%s,%s,%s,%s\n", $sym, gmdate('Y-m-d\TH:00:00\Z', $t), $o, max((float) $v['high'], $o, $c), min((float) $v['low'], $o, $c), $c);
    }
    file_put_contents($file, $out);
    printf("%s: %d bars %s -> %s\n", $sym, count($rows), gmdate('Y-m-d', array_key_first($rows)), gmdate('Y-m-d', array_key_last($rows)));
}
echo "FETCH DONE\n";
