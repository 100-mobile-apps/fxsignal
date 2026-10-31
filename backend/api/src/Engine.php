<?php
declare(strict_types=1);
require_once __DIR__ . '/TradingPolicy.php';

/**
 * Production technical-indicator signal engine. The Flutter demo has its own
 * illustrative rules and is not a validation of this server's signals.
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

    /**
     * @return array|null ['direction' => BUY|SELL|WAIT, 'confidence', 'entry', 'sl', 'tp1', 'tp2', 'rsi', 'atr', 'reasons']
     */
    public static function analyze(array $candles, int $threshold = 60): ?array
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
