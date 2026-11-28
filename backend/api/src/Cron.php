<?php
declare(strict_types=1);

/**
 * One cron run: refresh prices, update open signals (TP/SL), open new
 * signals and send push notifications.
 */
final class Cron
{
    public static function tfSeconds(string $tf): int
    {
        $map = ['15min' => 900, '30min' => 1800, '1h' => 3600, '2h' => 7200, '4h' => 14400, '1day' => 86400];
        return $map[$tf] ?? 3600;
    }

    /**
     * @param callable $fetch fn(string $pair, string $tf): array candles (oldest first)
     * @return array log lines
     */
    public static function run(int $now, callable $fetch, bool $throttle = true, ?callable $clock = null): array
    {
        $clock ??= $throttle ? static fn(): int => time() : static fn(): int => $now;
        $log = [];
        $tf = (string) config('timeframe', '1h');
        $tfSec = self::tfSeconds($tf);
        $main = (array) config('pairs');
        // Markets that are only being watched (no alerts sent) are checked once an hour to save data requests.
        $watch = $now % 3600 < 1200 ? array_values(array_diff((array) config('watch_pairs', []), $main)) : [];
        $pairs = array_merge($main, $watch);
        $rpm = max(1, (int) config('requests_per_minute', 8));

        foreach ($pairs as $i => $pair) {
            $isWatch = !in_array($pair, $main, true);
            if ($throttle && $i > 0) usleep((int) (60 / $rpm * 1e6));
            try {
                $candles = $fetch($pair, $tf);
            } catch (Throwable $e) {
                $log[] = "$pair: data error — {$e->getMessage()}";
                continue;
            }
            if (count($candles) < 60) {
                $log[] = "$pair: not enough candles";
                continue;
            }
            if (!TradingPolicy::validCandles($candles, (int) $clock())) {
                $log[] = "$pair: invalid or future-dated market candles (tracking skipped)";
                continue;
            }
            if (!$isWatch) self::updatePrice($pair, $candles, $tfSec, $now);
            $log = array_merge($log, self::updateRunning($pair, $candles, $now));

            // Only fully closed candles are used for new signals (no repainting).
            $closed = $candles;
            $lastT = $candles[count($candles) - 1]['t'];
            if ($lastT + $tfSec > $now) array_pop($closed);
            foreach (Strategies::keys() as $strategy) {
                $log[] = self::maybeOpen($pair, $strategy, $isWatch, $tf, $tfSec, $closed, $candles, (int) $clock(), $clock);
            }
        }

        $rank = MarketRank::refresh($now);
        foreach ((array) ($rank['watching'] ?? []) as $w) {
            if ($w['status'] === 'proven') $log[] = "watch: {$w['pair']} is proven ({$w['trades']} trades) - add it to 'pairs' to send alerts";
        }

        $log = array_merge($log, Reports::sendScheduled($now));
        $log = array_merge($log, Auth::sendVerifyReminders($now));
        [$sent, $failed] = Mailer::processQueue();
        if ($sent || $failed) $log[] = "emails: $sent sent" . ($failed ? ", $failed failed (will retry)" : '');

        Db::exec('DELETE FROM meta WHERE k = ?', ['last_cron']);
        Db::exec('INSERT INTO meta (k, v) VALUES (?, ?)', ['last_cron', json_encode(['at' => iso(now($now)), 'log' => $log])]);
        return $log;
    }

    private static function updatePrice(string $pair, array $candles, int $tfSec, int $now): void
    {
        $n = count($candles);
        $bars = max(1, intdiv(86400, $tfSec));
        $day = array_slice($candles, max(0, $n - $bars));
        $last = $candles[$n - 1]['c'];
        $ref = $candles[max(0, $n - 1 - $bars)]['c'];
        $spark = array_map(fn($c) => $c['c'], array_slice($candles, max(0, $n - 24)));
        Db::exec('DELETE FROM prices WHERE symbol = ?', [$pair]);
        Db::exec('INSERT INTO prices (symbol, price, change_pct, high, low, spark, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $pair, $last, $ref ? round(($last - $ref) / $ref * 100, 3) : 0,
            max(array_column($day, 'h')), min(array_column($day, 'l')),
            json_encode($spark), now($now),
        ]);
    }

    private static function candlesJson(array $candles): string
    {
        $slice = array_slice($candles, -120);
        return json_encode(array_map(fn($c) => [$c['t'], $c['o'], $c['h'], $c['l'], $c['c']], $slice));
    }

    private static function updateRunning(string $pair, array $candles, int $now): array
    {
        $log = [];
        $rows = Db::all("SELECT * FROM signals WHERE pair = ? AND status = 'running'", [$pair]);
        foreach ($rows as $s) {
            $expires = (int) strtotime($s['expires_at'] . ' UTC');
            $r = Tracker::evaluate($s, $candles, $expires, $now);
            $tp1At = $r['tp1_hit_at'] ? now($r['tp1_hit_at']) : null;
            $lockAt = !empty($r['lock_hit_at']) ? now($r['lock_hit_at']) : null;
            Db::exec(
                'UPDATE signals SET status = ?, result = ?, tp1_hit_at = ?, lock_hit_at = ?, closed_at = ?, close_price = ?, pips = ?,
                 current_price = ?, live_pips = ?, candles = ?, updated_at = ? WHERE id = ?',
                [$r['status'], $r['result'], $tp1At, $lockAt, $r['closed_at'] ? now($r['closed_at']) : null,
                 $r['close_price'], $r['pips'], $r['current_price'], $r['live_pips'],
                 self::candlesJson($candles), now($now), $s['id']]
            );
            $fresh = Db::one('SELECT * FROM signals WHERE id = ?', [$s['id']]);
            if ((int) $fresh['published'] !== 1) continue;      // watch-only markets never send alerts
            if ($r['status'] === 'closed') {
                $kind = match ($r['result']) { 'tp2' => 'tp2', 'sl' => 'sl', 'tp1' => 'tp1_close', default => 'expired' };
                $n = Notify::result($fresh, $kind);
                $log[] = "$pair: signal #{$s['id']} ({$s['strategy']}) closed {$r['result']} (" . EmailTemplates::pips((float) $r['pips']) . " pips), $n emails queued";
            } else {
                if ($tp1At && !$s['tp1_hit_at']) {
                    $n = Notify::result($fresh, 'tp1');
                    $log[] = "$pair: signal #{$s['id']} TP1 hit, $n emails queued";
                }
                if ($lockAt && empty($s['lock_hit_at'])) {
                    Notify::result($fresh, 'lock');
                    $log[] = "$pair: signal #{$s['id']} halfway to TP2, lock-at-TP1 alert sent";
                }
            }
        }
        return $log;
    }

    private static function maybeOpen(string $pair, string $strategy, bool $watchOnly, string $tf, int $tfSec, array $closed, array $all, int $now, ?callable $clock = null): string
    {
        $tag = $strategy === 'classic' ? $pair : "$pair/$strategy";
        $only = Strategies::pairs($strategy);
        if ($only !== null && !in_array($pair, $only, true)) return "$tag: not traded on this market";
        $publish = config('publish_pairs');
        $published = !$watchOnly && !(is_array($publish) && !in_array($pair, $publish, true)) ? 1 : 0;
        if ($published === 0 && !$watchOnly) return "$tag: tracking only (not in publish_pairs)";
        if (!TradingPolicy::canPublish($now)) return "$tag: new signals paused for weekend (UTC)";
        $formingT = $all[count($all) - 1]['t'];
        $candleT = $closed[count($closed) - 1]['t'];
        if ($formingT <= $candleT || !TradingPolicy::freshFormingQuote($formingT, $tfSec, $now)) {
            return "$tag: no fresh forming-candle quote for entry";
        }
        if (Db::one("SELECT id FROM signals WHERE pair = ? AND strategy = ? AND status = 'running'", [$pair, $strategy])) {
            return "$tag: signal already running";
        }
        $cooldown = (int) config('cooldown_hours', 4) * 3600;
        $recent = Db::one("SELECT id FROM signals WHERE pair = ? AND strategy = ? AND status = 'closed' AND closed_at > ?", [$pair, $strategy, now($now - $cooldown)]);
        if ($recent) return "$tag: cooling down after last signal";

        if (Db::one('SELECT id FROM signals WHERE pair = ? AND strategy = ? AND candle_t >= ?', [$pair, $strategy, $candleT])) {
            return "$tag: already evaluated this candle";
        }
        // Stale data guard (e.g. weekend): skip if the last closed candle is old.
        if ($now - ($candleT + $tfSec) > 3 * $tfSec) return "$tag: market closed / stale data";

        $min = (int) config('min_confidence', 65);
        $a = Strategies::analyze($strategy, $closed, $min);
        if (!$a || $a['direction'] === 'WAIT' || $a['sl'] === null || ($strategy === 'classic' && $a['confidence'] < $min)) {
            return "$tag: no setup" . ($a ? " ({$a['direction']} {$a['confidence']}%)" : '');
        }

        // Entry = latest price (the forming candle), levels keep the same distances.
        // Tracking starts after the candle the entry was taken in, so price
        // action from before the signal can never trigger TP/SL.
        $entry = $all[count($all) - 1]['c'];
        $maxDrift = (float) config('max_entry_drift_atr', 0.5);
        if (!TradingPolicy::entryWithinAtr((float) $entry, (float) $a['entry'], (float) $a['atr'], $maxDrift)) {
            return "$tag: setup passed but entry moved too far from confirmed close";
        }
        $publicationTime = $clock ? (int) $clock() : $now;
        if (!TradingPolicy::canPublish($publicationTime)) return "$tag: new signals paused for weekend (UTC)";
        if (!TradingPolicy::freshFormingQuote($formingT, $tfSec, $publicationTime)) {
            return "$tag: quote became stale before publication";
        }
        $trackFromT = $all[count($all) - 1]['t'];
        $shift = $entry - $a['entry'];
        $expiresAt = $publicationTime + Strategies::expiryHours($strategy) * 3600;
        $id = Db::insert(
            'INSERT INTO signals (pair, strategy, published, timeframe, direction, entry, stop_loss, tp1, tp2, confidence, reasons, candles, candle_t,
              status, current_price, live_pips, opened_at, expires_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pair, $strategy, $published, $tf, $a['direction'], $entry, $a['sl'] + $shift, $a['tp1'] + $shift, $a['tp2'] + $shift,
             $a['confidence'], json_encode($a['reasons']), self::candlesJson($all), $trackFromT,
              'running', $entry, 0.0, now($publicationTime), now($expiresAt), now($publicationTime)]
        );
        if (!$published) return "$tag: WATCH {$a['direction']} #$id (not sent to users)";
        $n = Notify::newSignal(Db::one('SELECT * FROM signals WHERE id = ?', [$id]));
        return "$tag: NEW {$a['direction']} #$id ({$a['confidence']}%), $n emails queued";
    }
}
