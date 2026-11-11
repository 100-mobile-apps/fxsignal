<?php
declare(strict_types=1);
require_once __DIR__ . '/TradingPolicy.php';

/**
 * Production technical-indicator signal engine. The Flutter demo has its own
 * illustrative rules and is not a validation of this server's signals.
 *
 * Strategy v2 (Nov 2026), run on 4-hour candles (config timeframe '4h'):
 *  - Currency pairs: "range reversal". In a quiet market (ADX < 20) price that
 *    closes outside the 20-period Bollinger Band (2 st. dev.) with RSI at an
 *    extreme tends to snap back toward the average. SL 2 ATR, TP1 1 ATR, TP2 2 ATR.
 *  - Gold: "trend pullback". Gold trends, so the trend-confluence rules are kept
 *    but a signal is only taken when price has pulled back to within 1 ATR of
 *    the 20 EMA (no chasing). SL 1.5 ATR, TP1 1.5 ATR, TP2 3 ATR.
 * Backtest on Dukascopy hourly data 2022-01 to 2026-11 aggregated to 4h, with
 * 1.2 pip (FX) / 3 pip (gold) costs: see backend/STRATEGY_RESULTS.md.
 *
 * Candles: list of ['t' => unix seconds, 'o', 'h', 'l', 'c'], oldest first.
 */
final class Engine
{
    public static function ema(array $v, int $p): array
    {
        $n = count($v);
        $out = array_fill(0, $n, null);
        if ($n < $p) return $out;
        $prev = array_sum(array_slice($v, 0, $p)) / $p;
        $out[$p - 1] = $prev;
        $k = 2 / ($p + 1);
        for ($i = $p; $i < $n; $i++) {
            $prev = $v[$i] * $k + $prev * (1 - $k);
            $out[$i] = $prev;
        }
        return $out;
    }

    public static function rsi(array $c, int $p): array
    {
        $n = count($c);
        $out = array_fill(0, $n, null);
        if ($n <= $p) return $out;
        $g = 0.0;
        $l = 0.0;
        for ($i = 1; $i <= $p; $i++) {
            $d = $c[$i] - $c[$i - 1];
            if ($d > 0) $g += $d; else $l -= $d;
        }
        $ag = $g / $p;
        $al = $l / $p;
        $out[$p] = self::rsiValue($ag, $al);
        for ($i = $p + 1; $i < $n; $i++) {
            $d = $c[$i] - $c[$i - 1];
            $ag = ($ag * ($p - 1) + max($d, 0)) / $p;
            $al = ($al * ($p - 1) + max(-$d, 0)) / $p;
            $out[$i] = self::rsiValue($ag, $al);
        }
        return $out;
    }

    private static function rsiValue(float $g, float $l): float
    {
        if ($l == 0.0) return $g == 0.0 ? 50.0 : 100.0;
        return 100 - 100 / (1 + $g / $l);
    }

    public static function macdHistogram(array $c): array
    {
        $n = count($c);
        $f = self::ema($c, 12);
        $s = self::ema($c, 26);
        $hist = array_fill(0, $n, null);
        $start = 25;
        if ($n <= $start) return $hist;
        $line = [];
        for ($i = $start; $i < $n; $i++) $line[] = $f[$i] - $s[$i];
        $sig = self::ema($line, 9);
        foreach ($sig as $j => $sv) {
            if ($sv !== null) $hist[$start + $j] = $line[$j] - $sv;
        }
        return $hist;
    }

    public static function atr(array $candles, int $p): array
    {
        $n = count($candles);
        $out = array_fill(0, $n, null);
        if ($n <= $p) return $out;
        $tr = [$candles[0]['h'] - $candles[0]['l']];
        for ($i = 1; $i < $n; $i++) {
            $pc = $candles[$i - 1]['c'];
            $tr[] = max($candles[$i]['h'] - $candles[$i]['l'], abs($candles[$i]['h'] - $pc), abs($candles[$i]['l'] - $pc));
        }
        $prev = array_sum(array_slice($tr, 1, $p)) / $p;
        $out[$p] = $prev;
        for ($i = $p + 1; $i < $n; $i++) {
            $prev = ($prev * ($p - 1) + $tr[$i]) / $p;
            $out[$i] = $prev;
        }
        return $out;
    }

    /** Average Directional Index (Wilder). */
    public static function adx(array $candles, int $p = 14): array
    {
        $n = count($candles);
        $out = array_fill(0, $n, null);
        if ($n < 2 * $p + 1) return $out;
        $tr = $pdm = $mdm = [0.0];
        for ($i = 1; $i < $n; $i++) {
            $up = $candles[$i]['h'] - $candles[$i - 1]['h'];
            $dn = $candles[$i - 1]['l'] - $candles[$i]['l'];
            $pdm[] = ($up > $dn && $up > 0) ? $up : 0.0;
            $mdm[] = ($dn > $up && $dn > 0) ? $dn : 0.0;
            $tr[] = max($candles[$i]['h'] - $candles[$i]['l'], abs($candles[$i]['h'] - $candles[$i - 1]['c']), abs($candles[$i]['l'] - $candles[$i - 1]['c']));
        }
        $atr = array_sum(array_slice($tr, 1, $p));
        $sp = array_sum(array_slice($pdm, 1, $p));
        $sm = array_sum(array_slice($mdm, 1, $p));
        $dx = [];
        for ($i = $p + 1; $i < $n; $i++) {
            $atr = $atr - $atr / $p + $tr[$i];
            $sp = $sp - $sp / $p + $pdm[$i];
            $sm = $sm - $sm / $p + $mdm[$i];
            $pdi = $atr > 0 ? 100 * $sp / $atr : 0;
            $mdi = $atr > 0 ? 100 * $sm / $atr : 0;
            $dx[$i] = ($pdi + $mdi) > 0 ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0;
            if ($i === 2 * $p) $out[$i] = array_sum(array_slice($dx, 0, $p)) / $p;
            elseif ($i > 2 * $p) $out[$i] = ($out[$i - 1] * ($p - 1) + $dx[$i]) / $p;
        }
        return $out;
    }

    /**
     * Strategy v2 entry point. Without a pair the original trend engine is used.
     * @return array|null ['direction' => BUY|SELL|WAIT, 'confidence', 'entry', 'sl', 'tp1', 'tp2', 'rsi', 'atr', 'reasons']
     */
    public static function analyze(array $candles, int $threshold = 60, ?string $pair = null): ?array
    {
        if ($pair === null) return self::trendConfluence($candles, $threshold);
        if (in_array($pair, ['BTC/USD', 'ETH/USD'], true)) return self::cryptoBreakout($candles);
        return str_starts_with($pair, 'XAU') ? self::goldPullback($candles, $threshold) : self::rangeReversal($candles);
    }

    /**
     * Crypto: 55-candle (about 9 days on 4h) breakout in the direction of the trend
     * (EMA 50 above/below EMA 200). SL 2 ATR, TP1 2 ATR, TP2 4 ATR.
     */
    public static function cryptoBreakout(array $candles): ?array
    {
        $n = count($candles);
        if ($n < 200 || !TradingPolicy::validCandles($candles)) return null;
        $c = array_map(fn($x) => (float) $x['c'], $candles);
        $L = $n - 1;
        $price = $c[$L];
        $at = self::atr($candles, 14)[$L] ?? 0.0;
        $e50 = self::ema($c, 50)[$L];
        $e200 = self::ema($c, 200)[$L];
        $rsi = self::rsi($c, 14)[$L] ?? 50.0;
        $prior = array_slice($candles, $L - 55, 55);
        $hh = max(array_map(fn($x) => (float) $x['h'], $prior));
        $ll = min(array_map(fn($x) => (float) $x['l'], $prior));
        $out = ['direction' => 'WAIT', 'confidence' => 0, 'entry' => $price, 'sl' => null, 'tp1' => null, 'tp2' => null,
                'rsi' => $rsi, 'atr' => $at, 'reasons' => []];
        if ($at <= 0 || $e50 === null || $e200 === null) return $out;
        if ($price > $hh && $price > $e50 && $e50 > $e200) $dir = 1;
        elseif ($price < $ll && $price < $e50 && $e50 < $e200) $dir = -1;
        else return $out;
        $beyond = min(1.0, abs($price - ($dir === 1 ? $hh : $ll)) / $at);
        $out['direction'] = $dir === 1 ? 'BUY' : 'SELL';
        $out['confidence'] = (int) round(min(95, 74 + 8 * $beyond + 6 * min(1.0, abs($e50 - $e200) / (3 * $at))));
        $out['sl'] = $price - $dir * 2.0 * $at;
        $out['tp1'] = $price + $dir * 2.0 * $at;
        $out['tp2'] = $price + $dir * 4.0 * $at;
        $out['reasons'] = [
            sprintf('Price broke the %s of the last 55 candles (about 9 days)', $dir === 1 ? 'high' : 'low'),
            sprintf('Trend %s: EMA 50 is %s EMA 200', $dir === 1 ? 'up' : 'down', $dir === 1 ? 'above' : 'below'),
            'Breakouts in the trend direction tend to run in crypto',
        ];
        return $out;
    }

    public static function goldPullback(array $candles, int $threshold = 60): ?array
    {
        $a = self::trendConfluence($candles, $threshold);
        if (!$a || $a['direction'] === 'WAIT') return $a;
        $c = array_map(fn($x) => (float) $x['c'], $candles);
        $L = count($c) - 1;
        $e20 = self::ema($c, 20)[$L];
        if ($a['atr'] <= 0 || abs($c[$L] - $e20) > $a['atr']) {
            return ['direction' => 'WAIT', 'sl' => null, 'tp1' => null, 'tp2' => null, 'reasons' => ['Trend is up/down but price is stretched; waiting for a pullback']] + $a;
        }
        $a['reasons'][] = 'Price pulled back near the 20 EMA (entry in the trend without chasing)';
        return $a;
    }

    public static function rangeReversal(array $candles): ?array
    {
        $n = count($candles);
        if ($n < 200 || !TradingPolicy::validCandles($candles)) return null;
        $c = array_map(fn($x) => (float) $x['c'], $candles);
        $L = $n - 1;
        $price = $c[$L];
        $at = self::atr($candles, 14)[$L] ?? 0.0;
        $adx = self::adx($candles, 14)[$L] ?? 99.0;
        $rsi = self::rsi($c, 14)[$L] ?? 50.0;
        $win = array_slice($c, $L - 19, 20);
        $mid = array_sum($win) / 20;
        $sd = sqrt(array_sum(array_map(fn($v) => ($v - $mid) ** 2, $win)) / 20);
        $out = ['direction' => 'WAIT', 'confidence' => 0, 'entry' => $price, 'sl' => null, 'tp1' => null, 'tp2' => null,
                'rsi' => $rsi, 'atr' => $at, 'reasons' => []];
        if ($at <= 0 || $sd <= 0 || $adx >= 20) return $out;
        $z = ($price - $mid) / $sd;
        if ($z < -2 && $rsi <= 35) $dir = 1;
        elseif ($z > 2 && $rsi >= 65) $dir = -1;
        else return $out;

        $stretch = min(1.0, (abs($z) - 2) / 1.0);
        $rsiX = min(1.0, (($dir === 1 ? 35 - $rsi : $rsi - 65)) / 15);
        $calm = min(1.0, (20 - $adx) / 10);
        $bar = $candles[$L];
        $reversal = ($bar['c'] - $bar['o']) * $dir > 0;
        $hour = (int) gmdate('G', (int) $bar['t']);
        // Backtest 2022-2026: setups formed in the London morning (candles opening 04:00-11:59 UTC)
        // reached TP1 less often and lost money; the rest made money. Strength reflects that:
        // London-morning setups score 62-70, all others 72-95. A closing reversal candle adds strength.
        $london = $hour >= 4 && $hour < 12;
        $raw = 3 * $stretch + 3 * $rsiX + 2 * $calm + ($reversal ? 4 : 0); // 0..12
        $out['direction'] = $dir === 1 ? 'BUY' : 'SELL';
        $out['confidence'] = (int) round($london ? 62 + 8 * $raw / 12 : 72 + 23 * $raw / 12);
        $out['sl'] = $price - $dir * 2.0 * $at;
        $out['tp1'] = $price + $dir * 1.0 * $at;
        $out['tp2'] = $price + $dir * 2.0 * $at;
        $out['reasons'] = [
            sprintf('Price closed %s the Bollinger Band (%.1f st. dev. from the 20-period average)', $dir === 1 ? 'below the lower' : 'above the upper', abs($z)),
            sprintf('RSI %.0f: %s', $rsi, $dir === 1 ? 'oversold' : 'overbought'),
            sprintf('ADX %.0f: quiet, ranging market that favours a move back to the average', $adx),
            'Targets sit between price and the 20-period average',
        ];
        if ($reversal) $out['reasons'][] = 'The candle closed back in the signal direction (reversal confirmed)';
        if ($london) $out['reasons'][] = 'Formed in the London morning session, which has been less reliable for this setup';
        return $out;
    }

    /** Original trend-confluence rules (EMA 20/50/200, MACD, RSI). */
    public static function trendConfluence(array $candles, int $threshold = 60): ?array
    {
        $n = count($candles);
        if ($n < 200 || !TradingPolicy::validCandles($candles)) return null;
        $c = array_map(fn($x) => (float) $x['c'], $candles);
        $L = $n - 1;
        $fa = self::ema($c, 20);
        $sl = self::ema($c, 50);
        $tr = self::ema($c, 200);
        $r = self::rsi($c, 14);
        $h = self::macdHistogram($c);
        $a = self::atr($candles, 14);

        $price = $c[$L];
        $eps = abs($price) * 1e-7;
        $rn = $r[$L] ?? 50.0;
        $hn = $h[$L] ?? 0.0;
        $hp = $h[$L - 1] ?? 0.0;
        $at = $a[$L] ?? 0.0;
        $bull = 0;
        $bear = 0;
        $br = [];
        $sr = [];

        if ($tr[$L] !== null) {
            if ($price - $tr[$L] > $eps) { $bull += 20; $br[] = 'Price above the 200 EMA (long-term uptrend)'; }
            elseif ($tr[$L] - $price > $eps) { $bear += 20; $sr[] = 'Price below the 200 EMA (long-term downtrend)'; }
        }
        if ($fa[$L] - $sl[$L] > $eps) { $bull += 25; $br[] = '20 EMA above 50 EMA'; }
        elseif ($sl[$L] - $fa[$L] > $eps) { $bear += 25; $sr[] = '20 EMA below 50 EMA'; }

        if ($hn > $eps) {
            $bull += 20; $br[] = 'MACD histogram positive';
            if ($hn > $hp) { $bull += 5; $br[] = 'Bullish momentum increasing'; }
        } elseif ($hn < -$eps) {
            $bear += 20; $sr[] = 'MACD histogram negative';
            if ($hn < $hp) { $bear += 5; $sr[] = 'Bearish momentum increasing'; }
        }

        if ($rn > 55) { $bull += 15; $br[] = sprintf('RSI %.0f confirms bullish momentum', $rn); }
        elseif ($rn < 45) { $bear += 15; $sr[] = sprintf('RSI %.0f confirms bearish momentum', $rn); }

        $cross = 0;
        for ($i = $L; $i > $L - 3; $i--) {
            if ($fa[$i - 1] === null || $sl[$i - 1] === null) break;
            if ($fa[$i - 1] <= $sl[$i - 1] && $fa[$i] > $sl[$i]) { $cross = 1; break; }
            if ($fa[$i - 1] >= $sl[$i - 1] && $fa[$i] < $sl[$i]) { $cross = -1; break; }
        }
        if ($cross > 0) { $bull += 15; $br[] = 'Fresh bullish EMA crossover'; }
        elseif ($cross < 0) { $bear += 15; $sr[] = 'Fresh bearish EMA crossover'; }

        if ($rn > 70) { $bull -= 15; $br[] = 'Caution: RSI overbought'; }
        elseif ($rn < 30) { $bear -= 15; $sr[] = 'Caution: RSI oversold'; }

        $dir = 'WAIT';
        $conf = max($bull, $bear);
        $reasons = [];
        $longAligned = $tr[$L] !== null && $price > $tr[$L] + $eps &&
            $fa[$L] > $sl[$L] + $eps && $hn > $eps && $rn > 50 && $rn <= 70;
        $shortAligned = $tr[$L] !== null && $price < $tr[$L] - $eps &&
            $sl[$L] > $fa[$L] + $eps && $hn < -$eps && $rn < 50 && $rn >= 30;
        if ($bull >= $threshold && $bull > $bear && $longAligned) {
            $dir = 'BUY'; $conf = $bull; $reasons = $br;
        } elseif ($bear >= $threshold && $bear > $bull && $shortAligned) {
            $dir = 'SELL'; $conf = $bear; $reasons = $sr;
        }

        $out = [
            'direction' => $dir,
            'confidence' => max(0, min(100, $conf)),
            'entry' => $price,
            'sl' => null, 'tp1' => null, 'tp2' => null,
            'rsi' => $rn,
            'atr' => $at,
            'reasons' => $reasons,
        ];
        if ($dir === 'BUY' && $at > 0) {
            $out['sl'] = $price - 1.5 * $at;
            $out['tp1'] = $price + 1.5 * $at;
            $out['tp2'] = $price + 3.0 * $at;
        } elseif ($dir === 'SELL' && $at > 0) {
            $out['sl'] = $price + 1.5 * $at;
            $out['tp1'] = $price - 1.5 * $at;
            $out['tp2'] = $price - 3.0 * $at;
        }
        return $out;
    }
}
