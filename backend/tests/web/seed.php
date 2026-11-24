<?php
// Seeds 120 days of synthetic signals + prices for screenshots and tests. Usage: FX_CONFIG=tests/web/config.php php tests/web/seed.php
declare(strict_types=1);
require __DIR__ . '/../../api/src/bootstrap.php';
mt_srand(7);
Db::exec('DELETE FROM signals'); Db::exec('DELETE FROM prices');
$base = ['EUR/USD'=>1.0850,'GBP/USD'=>1.2710,'USD/JPY'=>151.20,'AUD/USD'=>0.6590,'USD/CAD'=>1.3610,'USD/CHF'=>0.8820,'NZD/USD'=>0.6090,'XAU/USD'=>2650.0,'BTC/USD'=>91000.0,'ETH/USD'=>3150.0];
$now = time();
foreach ($base as $pair => $p) {
    $spark = []; $v = $p;
    for ($i = 0; $i < 24; $i++) { $v *= 1 + (mt_rand(-100, 100) / 100000); $spark[] = round($v, 5); }
    $chg = ($v - $p) / $p * 100;
    Db::exec('INSERT INTO prices (symbol, price, change_pct, high, low, spark, updated_at) VALUES (?,?,?,?,?,?,?)',
        [$pair, $v, round($chg, 2), max($spark), min($spark), json_encode($spark), now()]);
}
function candles(float $p, int $end, float $vol): array {
    $out = []; $c = $p * (1 - $vol * 8);
    for ($i = 69; $i >= 0; $i--) { $o = $c; $c = $o + $o * $vol * (mt_rand(-100, 110) / 100); $h = max($o, $c) * (1 + $vol * 0.4); $l = min($o, $c) * (1 - $vol * 0.4);
        $out[] = [$end - $i * 14400, round($o, 5), round($h, 5), round($l, 5), round($c, 5)]; }
    return $out;
}
$n = 0;
for ($t = $now - 120 * 86400; $t < $now - 6 * 3600; $t += mt_rand(5, 14) * 3600) {
    $pair = array_rand($base); $p = $base[$pair] * (1 + mt_rand(-300, 300) / 10000);
    $ps = pip_size($pair); $atr = ['XAU/USD' => 180, 'BTC/USD' => 900, 'ETH/USD' => 400][$pair] ?? (str_contains($pair, 'JPY') ? 60 : 35);
    $buy = mt_rand(0, 1) === 1; $d = $buy ? 1 : -1;
    $sl = $p - $d * 2 * $atr * $ps; $tp1 = $p + $d * $atr * $ps; $tp2 = $p + $d * 2 * $atr * $ps;
    $conf = mt_rand(62, 92);
    $closeT = $t + mt_rand(4, 60) * 3600;
    $running = $closeT > $now;
    $r = mt_rand(1, 100);
    [$res, $pips, $close, $tp1At] = $r <= 34 ? ['tp2', 2 * $atr, $tp2, $t + 3600 * 3] : ($r <= 58 ? ['tp1', 0.0, $p, $t + 3600 * 2] : ($r <= 66 ? ['expired', (float) mt_rand(-20, 30), $p + $d * mt_rand(-20, 30) * $ps, null] : ['sl', -2 * $atr, $sl, null]));
    if ($res === 'tp1') $pips = (float) $atr * 0.0 + 0; // TP1 then break-even counts as a small win in this engine: book TP1 half
    if ($res === 'tp1') $pips = round($atr / 2, 1);
    $dec = price_decimals($pair);
    $cur = $p + $d * mt_rand(-10, 25) * $ps * ($atr / 35);
    Db::exec('INSERT INTO signals (pair, timeframe, direction, entry, stop_loss, tp1, tp2, confidence, reasons, candles, candle_t, status, result, tp1_hit_at, closed_at, close_price, pips, current_price, live_pips, opened_at, expires_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $pair, '4h', $buy ? 'BUY' : 'SELL', round($p, $dec), round($sl, $dec), round($tp1, $dec), round($tp2, $dec), $conf,
        json_encode(['Price closed outside the 20-period Bollinger Band', 'RSI ' . ($buy ? 'oversold (32)' : 'overbought (68)'), 'ADX 17: ranging market']),
        json_encode(candles($p, $t, $ps * $atr / $p / 3)), $t, $running ? 'running' : 'closed', $running ? null : $res,
        $running ? null : ($tp1At ? now($tp1At) : null), $running ? null : now($closeT), $running ? null : round($close, $dec), $running ? null : round($pips, 1),
        $running ? round($cur, $dec) : null, $running ? round(($cur - $p) * $d / $ps, 1) : null, now($t), now($t + 120 * 3600), now(),
    ]);
    $n++;
}
// a few guaranteed live signals: free forex, premium forex (>70%), gold
foreach ([['EUR/USD', 'BUY', 66], ['GBP/USD', 'SELL', 84], ['XAU/USD', 'BUY', 78]] as $i => [$pair, $dir, $conf]) {
    $p = $base[$pair]; $ps = pip_size($pair); $atr = $pair === 'XAU/USD' ? 180 : 35; $d = $dir === 'BUY' ? 1 : -1; $t = $now - (2 + $i * 5) * 3600; $dec = price_decimals($pair);
    $cur = $p + $d * 12 * $ps * ($atr / 35);
    Db::exec('INSERT INTO signals (pair, timeframe, direction, entry, stop_loss, tp1, tp2, confidence, reasons, candles, candle_t, status, opened_at, expires_at, updated_at, current_price, live_pips)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$pair, '4h', $dir, round($p, $dec), round($p - $d * 2 * $atr * $ps, $dec), round($p + $d * $atr * $ps, $dec), round($p + $d * 2 * $atr * $ps, $dec), $conf,
        json_encode(['Trend and momentum aligned', 'Pullback to the 20 EMA']), json_encode(candles($p, $t, $ps * $atr / $p / 3)), $t, 'running', now($t), now($t + 120 * 3600), now(), round($cur, $dec), 12 * ($atr / 35)]);
    $n++;
}
echo "seeded $n signals\n";
