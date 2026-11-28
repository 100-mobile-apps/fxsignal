<?php
declare(strict_types=1);

final class TwelveData
{
    /**
     * Returns candles oldest-first as ['t' => unix, 'o','h','l','c'].
     * The newest candle is still forming.
     */
    public static function series(string $symbol, string $interval, int $count = 300): array
    {
        $key = (string) config('twelvedata_key', '');
        if ($key === '') throw new RuntimeException('twelvedata_key is not set in config.php');
        $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
            'symbol' => $symbol,
            'interval' => $interval,
            'outputsize' => $count,
            'timezone' => 'UTC',
            'apikey' => $key,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("Twelve Data request failed: $err");
        $data = json_decode((string) $raw, true);
        if ($code !== 200 || !is_array($data) || ($data['status'] ?? '') === 'error') {
            throw new RuntimeException('Twelve Data error: ' . ($data['message'] ?? "HTTP $code"));
        }
        $out = [];
        foreach (array_reverse($data['values'] ?? []) as $v) {
            $out[] = [
                't' => (int) strtotime($v['datetime'] . ' UTC'),
                'o' => (float) $v['open'],
                'h' => (float) $v['high'],
                'l' => (float) $v['low'],
                'c' => (float) $v['close'],
            ];
        }
        return $out;
    }
}
