<?php
declare(strict_types=1);
/**
 * Downloads up to 5000 hourly candles for extra markets (indices, metals, oil, crypto) and caches the raw JSON
 * in ./extra/. Prints what the Twelve Data plan allows, the date range, and how many hours a day each market trades.
 * Usage: php fetch_extra.php [--app=/home/USER/forexsignal.bulksend.top] [SYMBOL ...]
 */
$opts = getopt('', ['app::'], $rest);
$app = rtrim($opts['app'] ?? '/home/globacom/forexsignal.bulksend.top', '/');
$want = array_slice($argv, $rest) ?: ['NDX', 'IXIC', 'SPX', 'DJI', 'QQQ', 'XAG/USD', 'WTI/USD', 'BTC/USD', 'ETH/USD'];
$cfg = require $app . '/src/config.php';
$key = (string) ($cfg['twelvedata_key'] ?? '');
@mkdir(__DIR__ . '/extra');

foreach ($want as $i => $sym) {
    $file = __DIR__ . '/extra/' . str_replace('/', '_', $sym) . '.json';
    if (!is_file($file)) {
        if ($i > 0) sleep(10);
        $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
            'symbol' => $sym, 'interval' => '1h', 'outputsize' => 5000, 'timezone' => 'UTC', 'order' => 'ASC', 'apikey' => $key]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $d = json_decode($raw, true);
        if (empty($d['values'])) { printf("%-8s NOT AVAILABLE: %s\n", $sym, substr((string) ($d['message'] ?? 'no data'), 0, 140)); continue; }
        file_put_contents($file, $raw);
    }
    $d = json_decode((string) file_get_contents($file), true);
    $v = $d['values']; $n = count($v);
    $days = [];
    foreach ($v as $x) $days[substr($x['datetime'], 0, 10)] = ($days[substr($x['datetime'], 0, 10)] ?? 0) + 1;
    $mins = array_count_values(array_map(fn($x) => substr($x['datetime'], 14, 2), $v));
    arsort($mins);
    printf("%-8s %5d bars  %s -> %s  ~%.1f bars/trading day  minute stamps: %s  (%s)\n", $sym, $n, $v[0]['datetime'], $v[$n - 1]['datetime'],
        $n / max(1, count($days)), implode(',', array_keys(array_slice($mins, 0, 2, true))), $d['meta']['exchange'] ?? ($d['meta']['type'] ?? ''));
}
