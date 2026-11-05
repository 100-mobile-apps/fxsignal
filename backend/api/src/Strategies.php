<?php
declare(strict_types=1);

/**
 * Signal strategies. Every strategy looks at fully closed 1h candles (oldest first) and returns
 * the same shape as Engine::analyze(): direction, confidence, entry, sl, tp1, tp2, atr, reasons.
 * Prices are relative to the last closed candle; Cron shifts them to the live entry.
 *
 * Stops and targets are fixed at entry so every signal is tracked the same honest way (two half
 * positions, TP1 and TP2, stop to entry after TP1, stop to TP1 halfway to TP2).
 */
final class Strategies
{
    private static ?string $scope = null;

    /** Which strategy the lists, stats and reports describe (the viewer's own, else the platform default). */
    public static function setScope(?string $k): void { self::$scope = ($k !== null && self::valid($k)) ? $k : null; }

    public static function scope(): string { return self::$scope ?? MarketRank::effectiveStrategy(null); }

    /** SQL fragment, safe to inline: the key is validated against the known list. */
    public static function scopeSql(): string { return " AND strategy = '" . self::scope() . "'"; }

    /** key => [name, blurb, expiry hours, min 1h candles, risky opt-in] */
    public const INFO = [
        'steady' => [
            'name' => 'Steady',
            'blurb' => 'Follows the bigger trend on the 4-hour chart (Parabolic SAR flip with the 200 EMA). Fewer, calmer signals.',
            'expiry_h' => 120, 'min' => 1000, 'optin' => false,
        ],
        'adaptive' => [
            'name' => 'Adaptive breakout',
            'blurb' => 'Breakouts from quiet ranges. Aims for more when conditions are strong (trend, momentum, London/New York hours).',
            'expiry_h' => 96, 'min' => 1000, 'optin' => false,
        ],
        'high_reward' => [
            'name' => 'High reward',
            'blurb' => 'Moving-average crossover with the 200 average filter. Wins less often but aims for bigger moves.',
            'expiry_h' => 120, 'min' => 400, 'optin' => false,
        ],
        'classic' => [
            'name' => 'Classic',
            'blurb' => 'The original multi-indicator engine (EMA trend, MACD, RSI, crossovers).',
            'expiry_h' => 72, 'min' => 200, 'optin' => false,
        ],
        'turtle' => [
            'name' => 'Gold breakout',
            'blurb' => 'Gold only. Buys a close above the last 20 four-hour highs (sells below the lows), stop 2 ATR, targets 2.67 and 5.33 times the stop. Part of the gold live test.',
            'expiry_h' => 360, 'min' => 1000, 'optin' => false, 'pairs' => ['XAU/USD'],
        ],
        'win90' => [
            'name' => 'High win rate (90% style)',
            'blurb' => 'Tiny targets with a very wide stop. It wins very often, but one loss can erase many wins. Opt-in only.',
            'expiry_h' => 72, 'min' => 400, 'optin' => true,
        ],
    ];

    public static function keys(): array
    {
        $on = config('strategies');
        $all = array_keys(self::INFO);
        return is_array($on) ? array_values(array_intersect($all, $on)) : $all;
    }

    /** Markets a strategy may trade; null = every market. */
    public static function pairs(string $key): ?array
    {
        return self::INFO[$key]['pairs'] ?? null;
    }

    public static function valid(string $key): bool
    {
        return isset(self::INFO[$key]);
    }

    public static function name(string $key): string
    {
        return self::INFO[$key]['name'] ?? $key;
    }

    public static function expiryHours(string $key): int
    {
        return (int) (self::INFO[$key]['expiry_h'] ?? config('signal_expiry_hours', 72));
    }

    /** @return array|null signal in Engine::analyze() shape, or null when there is no setup */
    public static function analyze(string $key, array $c, int $minConfidence = 65): ?array
    {
        if (!isset(self::INFO[$key]) || count($c) < self::INFO[$key]['min'] || !TradingPolicy::validCandles($c)) return null;
        switch ($key) {
            case 'classic': return Engine::analyze($c, $minConfidence);
            case 'steady': return self::steady($c);
            case 'adaptive': return self::adaptive($c);
            case 'high_reward': return self::highReward($c);
            case 'win90': return self::win90($c);
            case 'turtle': return self::turtle($c);
        }
        return null;
    }

    // ---------- shared helpers ----------

    private static function out(string $dir, float $entry, float $sl, float $tp1, float $tp2, float $atr, int $conf, array $reasons): array
    {
        return ['direction' => $dir, 'confidence' => $conf, 'entry' => $entry, 'sl' => $sl, 'tp1' => $tp1, 'tp2' => $tp2,
            'rsi' => 50.0, 'atr' => $atr, 'reasons' => $reasons];
    }

    /** SL distance $risk; TP1 = $m1 x risk, TP2 = $m2 x risk. $d = 1 buy, -1 sell. */
    private static function levels(int $d, float $entry, float $risk, float $m1, float $m2): array
    {
        return [$entry - $d * $risk, $entry + $d * $m1 * $risk, $entry + $d * $m2 * $risk];
    }

    public static function sma(array $v, int $n): array
    {
        $o = [];
        $s = 0.0;
        foreach ($v as $i => $x) {
            $s += $x;
            if ($i >= $n) $s -= $v[$i - $n];
            $o[$i] = $i >= $n - 1 ? $s / $n : null;
        }
        return $o;
    }

    /** 1h -> 4h candles aligned to UTC 4h blocks. The block still in progress is left out. */
    public static function to4h(array $bars): array
    {
        $out = [];
        foreach ($bars as $b) {
            $k = intdiv($b['t'], 14400);
            if (!isset($out[$k])) $out[$k] = ['t' => $k * 14400, 'o' => $b['o'], 'h' => $b['h'], 'l' => $b['l'], 'c' => $b['c'], 'n' => 1];
            else { $out[$k]['h'] = max($out[$k]['h'], $b['h']); $out[$k]['l'] = min($out[$k]['l'], $b['l']); $out[$k]['c'] = $b['c']; $out[$k]['n']++; }
        }
        $out = array_values($out);
        $last = $bars[count($bars) - 1]['t'] + 3600;      // end of the last closed 1h candle
        while ($out && $out[count($out) - 1]['t'] + 14400 > $last) array_pop($out);   // incomplete 4h block
        return $out;
    }

    /** Parabolic SAR (0.02 / 0.2). Returns [sar[], direction[]] with direction 1 up / -1 down. */
    public static function psar(array $b, float $step = 0.02, float $max = 0.2): array
    {
        $sar = $dir = [];
        $d = 1;
        $af = $step;
        $ep = $b[0]['h'];
        $s = $b[0]['l'];
        foreach ($b as $i => $x) {
            if ($i === 0) { $sar[0] = $s; $dir[0] = $d; continue; }
            $s = $s + $af * ($ep - $s);
            if ($d === 1) {
                $s = min($s, $b[$i - 1]['l'], $b[max(0, $i - 2)]['l']);
                if ($x['l'] < $s) { $d = -1; $s = $ep; $ep = $x['l']; $af = $step; }
                elseif ($x['h'] > $ep) { $ep = $x['h']; $af = min($max, $af + $step); }
            } else {
                $s = max($s, $b[$i - 1]['h'], $b[max(0, $i - 2)]['h']);
                if ($x['h'] > $s) { $d = 1; $s = $ep; $ep = $x['h']; $af = $step; }
                elseif ($x['l'] < $ep) { $ep = $x['l']; $af = min($max, $af + $step); }
            }
            $sar[$i] = $s;
            $dir[$i] = $d;
        }
        return [$sar, $dir];
    }

    /** ADX (14). */
    private static function adx(array $b, int $n = 14): array
    {
        $adx = [];
        $sTr = $sP = $sM = 0.0;
        $dxs = [];
        $a = null;
        foreach ($b as $i => $x) {
            if ($i === 0) { $adx[0] = null; continue; }
            $pc = $b[$i - 1]['c'];
            $t = max($x['h'] - $x['l'], abs($x['h'] - $pc), abs($x['l'] - $pc));
            $up = $x['h'] - $b[$i - 1]['h'];
            $dn = $b[$i - 1]['l'] - $x['l'];
            $p = ($up > $dn && $up > 0) ? $up : 0.0;
            $m = ($dn > $up && $dn > 0) ? $dn : 0.0;
            if ($i <= $n) { $sTr += $t; $sP += $p; $sM += $m; }
            else { $sTr = $sTr - $sTr / $n + $t; $sP = $sP - $sP / $n + $p; $sM = $sM - $sM / $n + $m; }
            if ($i < $n || $sTr == 0) { $adx[$i] = null; continue; }
            $pdi = 100 * $sP / $sTr;
            $mdi = 100 * $sM / $sTr;
            $dx = ($pdi + $mdi) > 0 ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0;
            if ($a === null) { $dxs[] = $dx; if (count($dxs) === $n) $a = array_sum($dxs) / $n; }
            else $a = ($a * ($n - 1) + $dx) / $n;
            $adx[$i] = $a;
        }
        return $adx;
    }

    private static function closes(array $b): array
    {
        return array_map(fn($x) => (float) $x['c'], $b);
    }

    private static function lastAtr(array $b, int $n = 14): float
    {
        return (float) (Engine::atr($b, $n)[count($b) - 1] ?? 0.0);
    }

    // ---------- strategies ----------

    /** Steady: 4h Parabolic SAR flip in the direction of the 4h 200 EMA. Stop at the SAR, TP1 1R, TP2 2.5R. */
    private static function steady(array $c1): ?array
    {
        $b = self::to4h($c1);
        $n = count($b);
        if ($n < 210) return null;
        $cl = self::closes($b);
        $e200 = Engine::ema($cl, 200);
        [$sar, $dir] = self::psar($b);
        $i = $n - 1;
        // The flip must be on the 4h candle that just closed (so the alert is fresh).
        if ($e200[$i] === null || $dir[$i] === $dir[$i - 1]) return null;
        if ($b[$i]['t'] + 14400 < $c1[count($c1) - 1]['t'] + 3600 - 3600 * 1) return null;
        $d = $dir[$i];
        if (($d === 1 && $cl[$i] < $e200[$i]) || ($d === -1 && $cl[$i] > $e200[$i])) return null;
        $price = (float) $c1[count($c1) - 1]['c'];
        $atr4 = self::lastAtr($b);
        $risk = abs($price - $sar[$i]);
        if ($atr4 <= 0) return null;
        $risk = max($risk, 0.8 * $atr4);          // never an absurdly tight stop
        $risk = min($risk, 3.0 * $atr4);          // nor an absurdly wide one
        [$sl, $tp1, $tp2] = self::levels($d, $price, $risk, 1.0, 2.5);
        $side = $d === 1 ? 'up' : 'down';
        return self::out($d === 1 ? 'BUY' : 'SELL', $price, $sl, $tp1, $tp2, $atr4 / 4, 70, [
            "4-hour Parabolic SAR just flipped $side",
            'Direction agrees with the 4-hour 200 EMA trend',
            'Stop at the SAR level',
        ]);
    }

    /**
     * Gold breakout (Turtle System 1 entry): the 4h candle that just closed ends above the highest high of the 20 before it
     * (buy) or below the lowest low (sell). Stop 2 ATR(4h); TP1 2.67x and TP2 5.33x the stop, as in the 2025-26 backtest
     * (+80% / +160% of the account at 30% risk). The tracker's two-half rule then moves the TP2 stop to entry after TP1.
     */
    private static function turtle(array $c1): ?array
    {
        $b = self::to4h($c1);
        $n = count($b);
        if ($n < 40) return null;
        $i = $n - 1;
        if ($b[$i]['t'] + 14400 < $c1[count($c1) - 1]['t'] + 3600 - 3600 * 1) return null;   // breakout candle must be fresh
        $hi = -INF; $lo = INF;
        for ($k = $i - 20; $k < $i; $k++) { $hi = max($hi, (float) $b[$k]['h']); $lo = min($lo, (float) $b[$k]['l']); }
        $close = (float) $b[$i]['c'];
        $d = $close > $hi ? 1 : ($close < $lo ? -1 : 0);
        if ($d === 0) return null;
        $atr4 = self::lastAtr($b);
        if ($atr4 <= 0) return null;
        $price = (float) $c1[count($c1) - 1]['c'];
        [$sl, $tp1, $tp2] = self::levels($d, $price, 2.0 * $atr4, 80 / 30, 160 / 30);
        return self::out($d === 1 ? 'BUY' : 'SELL', $price, $sl, $tp1, $tp2, $atr4 / 4, 70, [
            $d === 1 ? '4-hour close above the last 20 highs' : '4-hour close below the last 20 lows',
            'Stop 2 ATR away; TP1 2.67x and TP2 5.33x the stop',
            'Gold live test strategy',
        ]);
    }

    /** Adaptive breakout: 20-bar breakout with the 200 EMA, scored for opportunity. */
    private static function adaptive(array $b): ?array
    {
        $n = count($b);
        $cl = self::closes($b);
        $e200 = Engine::ema($cl, 200);
        $atr = Engine::atr($b, 14);
        $adx = self::adx($b);
        $i = $n - 1;
        if ($e200[$i] === null || $atr[$i] === null || $adx[$i] === null) return null;
        $hh = -INF;
        $ll = INF;
        for ($k = $i - 20; $k < $i; $k++) { $hh = max($hh, $b[$k]['h']); $ll = min($ll, $b[$k]['l']); }
        $d = 0;
        if ($cl[$i] > $e200[$i] && $cl[$i] > $hh) $d = 1;
        elseif ($cl[$i] < $e200[$i] && $cl[$i] < $ll) $d = -1;
        if (!$d) return null;
        // 4h trend agreement
        $h4 = self::to4h($b);
        $c4 = self::closes($h4);
        $e50 = Engine::ema($c4, 50);
        $e200_4 = Engine::ema($c4, 200);
        $j = count($h4) - 1;
        if ($j < 200 || $e50[$j] === null || $e200_4[$j] === null) return null;
        // quiet range before the breakout: a narrow 20-bar band in the last 20 bars versus its 200-bar average
        $bw = [];
        for ($k = 19; $k < $n; $k++) {
            $w = array_slice($cl, $k - 19, 20);
            $m = array_sum($w) / 20;
            $sd = sqrt(array_sum(array_map(fn($y) => ($y - $m) ** 2, $w)) / 20);
            $bw[$k] = $m > 0 ? 4 * $sd / $m : null;
        }
        $avg = array_sum(array_slice($bw, -200)) / max(1, count(array_slice($bw, -200)));
        $quiet = false;
        for ($k = $i - 20; $k < $i; $k++) if (isset($bw[$k]) && $bw[$k] < 0.7 * $avg) { $quiet = true; break; }
        $hr = (int) gmdate('G', $b[$i]['t'] + 3600);
        $score = ($adx[$i] > 25 ? 1 : 0) + ((($e50[$j] - $e200_4[$j]) * $d > 0) ? 1 : 0) + ($quiet ? 1 : 0) + (($hr >= 7 && $hr <= 16) ? 1 : 0);
        if ($score <= 1) return null;
        $risk = 1.5 * $atr[$i];
        $high = $score >= 3;
        [$sl, $tp1, $tp2] = self::levels($d, $cl[$i], $risk, 1.0, $high ? 4.0 : 2.0);
        $why = ['Broke the 20-candle ' . ($d === 1 ? 'high' : 'low') . ' with the 200 EMA trend'];
        if ($adx[$i] > 25) $why[] = 'Strong trend strength (ADX above 25)';
        if ((($e50[$j] - $e200_4[$j]) * $d) > 0) $why[] = '4-hour trend agrees';
        if ($quiet) $why[] = 'Breaking out of a quiet range';
        if ($hr >= 7 && $hr <= 16) $why[] = 'London / New York hours';
        return self::out($d === 1 ? 'BUY' : 'SELL', $cl[$i], $sl, $tp1, $tp2, (float) $atr[$i], $high ? 75 : 60, $why);
    }

    /** High reward: SMA 20/50 cross with the SMA 200 filter. Stop 2 ATR, TP1 1R, TP2 3R. */
    private static function highReward(array $b): ?array
    {
        $n = count($b);
        $cl = self::closes($b);
        $s20 = self::sma($cl, 20);
        $s50 = self::sma($cl, 50);
        $s200 = self::sma($cl, 200);
        $atr = Engine::atr($b, 14);
        $i = $n - 1;
        if ($s200[$i] === null || $atr[$i] === null || $s50[$i - 1] === null) return null;
        $up = $s20[$i] > $s50[$i];
        $was = $s20[$i - 1] > $s50[$i - 1];
        $d = 0;
        if ($up && !$was && $cl[$i] > $s200[$i]) $d = 1;
        elseif (!$up && $was && $cl[$i] < $s200[$i]) $d = -1;
        if (!$d) return null;
        [$sl, $tp1, $tp2] = self::levels($d, $cl[$i], 2.0 * $atr[$i], 1.0, 3.0);
        return self::out($d === 1 ? 'BUY' : 'SELL', $cl[$i], $sl, $tp1, $tp2, (float) $atr[$i], 65, [
            'Fresh ' . ($d === 1 ? 'bullish' : 'bearish') . ' 20/50 moving-average crossover',
            'Price is on the right side of the 200 average',
            'Wider stop, bigger target',
        ]);
    }

    /** "90% style": RSI(2) extreme in the direction of the 200 SMA, tiny target, 4 ATR stop. */
    private static function win90(array $b): ?array
    {
        $n = count($b);
        $cl = self::closes($b);
        $s200 = self::sma($cl, 200);
        $r2 = Engine::rsi($cl, 2);
        $atr = Engine::atr($b, 14);
        $i = $n - 1;
        if ($s200[$i] === null || $r2[$i] === null || $atr[$i] === null) return null;
        $d = 0;
        if ($cl[$i] > $s200[$i] && $r2[$i] < 10) $d = 1;
        elseif ($cl[$i] < $s200[$i] && $r2[$i] > 90) $d = -1;
        if (!$d) return null;
        $a = (float) $atr[$i];
        $sl = $cl[$i] - $d * 4.0 * $a;
        $tp1 = $cl[$i] + $d * 0.15 * $a;
        $tp2 = $cl[$i] + $d * 0.25 * $a;
        return self::out($d === 1 ? 'BUY' : 'SELL', $cl[$i], $sl, $tp1, $tp2, $a, 60, [
            'Short-term price stretch with the long-term trend',
            'Very small target, very wide stop: wins often, but a loss is large',
        ]);
    }
}
