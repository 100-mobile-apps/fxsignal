<?php
declare(strict_types=1);

final class TradingPolicy
{
    public static function validCandles(array $candles, ?int $asOf = null): bool
    {
        if (!$candles) return false;
        $previousTime = null;
        foreach ($candles as $bar) {
            $time = $bar['t'] ?? null;
            if (!is_int($time) || $time <= 0 || ($asOf !== null && $time > $asOf) ||
                ($previousTime !== null && $time <= $previousTime)) return false;
            $previousTime = $time;
            foreach (['o', 'h', 'l', 'c'] as $field) {
                if (!isset($bar[$field]) || !is_numeric($bar[$field]) ||
                    !is_finite((float) $bar[$field]) || (float) $bar[$field] <= 0) return false;
            }
            if ((float) $bar['l'] > min((float) $bar['o'], (float) $bar['c']) ||
                (float) $bar['h'] < max((float) $bar['o'], (float) $bar['c'])) return false;
        }
        return true;
    }

    // Fixed UTC publication window, not a broker-market calendar or DST adjustment.
    public static function canPublish(int $now): bool
    {
        $weekday = (int) gmdate('N', $now);
        return $weekday <= 4 || ($weekday === 5 && (int) gmdate('G', $now) < 21);
    }

    public static function freshFormingQuote(int $candleTime, int $timeframeSeconds, int $now): bool
    {
        return $timeframeSeconds > 0 && $candleTime <= $now && $now < $candleTime + $timeframeSeconds;
    }

    public static function entryWithinAtr(float $entry, float $confirmedClose, float $atr, float $limit): bool
    {
        return is_finite($entry) && is_finite($confirmedClose) && is_finite($atr) && is_finite($limit)
            && $entry > 0 && $confirmedClose > 0 && $atr > 0 && $limit >= 0
            && abs($entry - $confirmedClose) <= $atr * $limit;
    }
}
