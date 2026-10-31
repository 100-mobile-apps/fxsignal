<?php
declare(strict_types=1);

/**
 * Replays price candles after a signal opened to decide its outcome.
 * Rules (standard for signal services):
 *  - Stop loss is checked before take profit inside the same candle (conservative).
 *  - When TP1 is reached the stop moves to entry (break-even).
 *  - TP2 closes the trade. After TP1, a return to entry closes it as a TP1 win.
 *  - Trades still open after the expiry time are closed at market.
 * Pure function: same inputs always give the same result.
 */
final class Tracker
{
    public static function evaluate(array $sig, array $candles, int $expiresAt, int $nowTs): array
    {
        $buy = $sig['direction'] === 'BUY';
        $dir = $buy ? 1 : -1;
        $entry = (float) $sig['entry'];
        $sl = (float) $sig['stop_loss'];
        $tp1 = (float) $sig['tp1'];
        $tp2 = (float) $sig['tp2'];
        $pip = pip_size($sig['pair']);
        $openT = (int) $sig['candle_t'];

        $pipsTo = fn(float $p) => round(($p - $entry) * $dir / $pip, 1);

        $tp1Hit = null; // always replayed from the open, so results are deterministic
        $last = null;

        foreach ($candles as $c) {
            if ($c['t'] <= $openT) continue;
            if ($c['t'] > $expiresAt) break;
            $last = $c;
            $stop = $tp1Hit !== null ? $entry : $sl;
            $hitStop = $buy ? $c['l'] <= $stop : $c['h'] >= $stop;
            if ($hitStop) {
                return self::closed($tp1Hit, $tp1Hit !== null ? 'tp1' : 'sl', $c['t'],
                    $tp1Hit !== null ? $tp1 : $sl, $tp1Hit !== null ? $pipsTo($tp1) : $pipsTo($sl));
            }
            if ($tp1Hit === null && ($buy ? $c['h'] >= $tp1 : $c['l'] <= $tp1)) {
                $tp1Hit = $c['t'];
            }
            if ($buy ? $c['h'] >= $tp2 : $c['l'] <= $tp2) {
                return self::closed($tp1Hit ?? $c['t'], 'tp2', $c['t'], $tp2, $pipsTo($tp2));
            }
        }

        $lastClose = $last ? (float) $last['c'] : $entry;
        if ($nowTs >= $expiresAt) {
            if ($tp1Hit !== null) {
                return self::closed($tp1Hit, 'tp1', $expiresAt, $tp1, $pipsTo($tp1));
            }
            return self::closed(null, 'expired', $expiresAt, $lastClose, $pipsTo($lastClose));
        }

        return [
            'status' => 'running',
            'result' => null,
            'tp1_hit_at' => $tp1Hit,
            'closed_at' => null,
            'close_price' => null,
            'pips' => null,
            'current_price' => $lastClose,
            'live_pips' => $pipsTo($lastClose),
        ];
    }

    private static function closed(?int $tp1At, string $result, int $at, float $price, float $pips): array
    {
        return [
            'status' => 'closed',
            'result' => $result,
            'tp1_hit_at' => $tp1At,
            'closed_at' => $at,
            'close_price' => $price,
            'pips' => $pips,
            'current_price' => $price,
            'live_pips' => $pips,
        ];
    }
}
