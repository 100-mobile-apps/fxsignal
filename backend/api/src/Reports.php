<?php
declare(strict_types=1);

/**
 * Daily / weekly / monthly performance reports built from the signals table.
 * Periods follow config('report_timezone') (default UTC).
 */
final class Reports
{
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) config('report_timezone', 'UTC'));
    }

    /** Start of the period containing $t (in report timezone). */
    public static function periodStart(string $period, DateTimeImmutable $t): DateTimeImmutable
    {
        $t = $t->setTimezone(self::tz());
        switch ($period) {
            case 'weekly':
                return $t->setTime(0, 0)->modify('-' . ((int) $t->format('N') - 1) . ' days');
            case 'monthly':
                return $t->setDate((int) $t->format('Y'), (int) $t->format('n'), 1)->setTime(0, 0);
            default:
                return $t->setTime(0, 0);
        }
    }

    public static function next(string $period, DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->modify(['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$period]);
    }

    public static function prev(string $period, DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->modify(['daily' => '-1 day', 'weekly' => '-1 week', 'monthly' => '-1 month'][$period]);
    }

    public static function key(string $period, DateTimeImmutable $start): string
    {
        return match ($period) {
            'weekly' => $start->format('o-\WW'),
            'monthly' => $start->format('Y-m'),
            default => $start->format('Y-m-d'),
        };
    }

    public static function label(string $period, DateTimeImmutable $start): string
    {
        return match ($period) {
            'weekly' => $start->format('M j') . ' – ' . $start->modify('+6 days')->format('M j, Y'),
            'monthly' => $start->format('F Y'),
            default => $start->format('D, M j, Y'),
        };
    }

    private static function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function emptyRow(string $period, DateTimeImmutable $start): array
    {
        $end = self::next($period, $start);
        return [
            'key' => self::key($period, $start),
            'label' => self::label($period, $start),
            'start' => iso(self::utc($start)),
            'end' => iso(self::utc($end)),
            'opened' => 0, 'closed' => 0, 'wins' => 0, 'losses' => 0,
            'win_rate' => 0.0, 'pips' => 0.0,
            'best' => null, 'worst' => null,
        ];
    }

    /** Stats for one period window; also returns the closed signals. */
    public static function window(string $period, DateTimeImmutable $start): array
    {
        $end = self::next($period, $start);
        $closed = Db::all(
            "SELECT id, pair, direction, entry, stop_loss, tp1, tp2, result, pips, closed_at FROM signals
             WHERE status = 'closed' AND closed_at >= ? AND closed_at < ? ORDER BY closed_at",
            [self::utc($start), self::utc($end)]
        );
        $opened = (int) Db::one('SELECT COUNT(*) AS n FROM signals WHERE opened_at >= ? AND opened_at < ?',
            [self::utc($start), self::utc($end)])['n'];
        $row = self::emptyRow($period, $start);
        $row['opened'] = $opened;
        self::accumulate($row, $closed);
        return [$row, $closed];
    }

    private static function accumulate(array &$row, array $closed): void
    {
        foreach ($closed as $s) {
            $p = (float) $s['pips'];
            $row['closed']++;
            $row['pips'] += $p;
            if ($p > 0) $row['wins']++;
            elseif ($p < 0) $row['losses']++;
            if ($row['best'] === null || $p > $row['best']['pips']) $row['best'] = ['id' => (int) $s['id'], 'pair' => $s['pair'], 'pips' => $p];
            if ($row['worst'] === null || $p < $row['worst']['pips']) $row['worst'] = ['id' => (int) $s['id'], 'pair' => $s['pair'], 'pips' => $p];
        }
        $row['pips'] = round($row['pips'], 1);
        $row['win_rate'] = $row['closed'] ? round($row['wins'] * 100 / $row['closed'], 1) : 0.0;
    }

    /** Last $limit periods, newest first (weekends skipped for daily). */
    public static function rows(string $period, int $limit, ?int $nowTs = null): array
    {
        $now = new DateTimeImmutable('@' . ($nowTs ?? time()));
        $cur = self::periodStart($period, $now);
        $starts = [];
        while (count($starts) < $limit) {
            $dow = (int) $cur->format('N');
            if ($period !== 'daily' || $dow < 6) $starts[] = $cur;
            $cur = self::prev($period, $cur);
        }
        $oldest = end($starts);
        $rangeEnd = self::next($period, $starts[0]);

        $closed = Db::all("SELECT id, pair, pips, closed_at FROM signals WHERE status = 'closed' AND closed_at >= ? AND closed_at < ?",
            [self::utc($oldest), self::utc($rangeEnd)]);
        $opened = Db::all('SELECT opened_at FROM signals WHERE opened_at >= ? AND opened_at < ?',
            [self::utc($oldest), self::utc($rangeEnd)]);

        $rows = [];
        foreach ($starts as $s) $rows[self::key($period, $s)] = self::emptyRow($period, $s);
        $bucket = function (string $utc) use ($period): string {
            return self::key($period, self::periodStart($period, new DateTimeImmutable($utc . ' UTC')));
        };
        $grouped = [];
        foreach ($closed as $c) $grouped[$bucket($c['closed_at'])][] = $c;
        foreach ($grouped as $k => $list) {
            if (isset($rows[$k])) self::accumulate($rows[$k], $list);
        }
        foreach ($opened as $o) {
            $k = $bucket($o['opened_at']);
            if (isset($rows[$k])) $rows[$k]['opened']++;
        }
        return array_values($rows);
    }

    public static function summary(?int $nowTs = null): array
    {
        $now = new DateTimeImmutable('@' . ($nowTs ?? time()));
        $out = [];
        foreach (['today' => 'daily', 'week' => 'weekly', 'month' => 'monthly'] as $name => $period) {
            [$row] = self::window($period, self::periodStart($period, $now));
            $out[$name] = $row;
        }
        $all = Db::one("SELECT COUNT(*) AS closed, SUM(CASE WHEN pips > 0 THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN pips < 0 THEN 1 ELSE 0 END) AS losses, COALESCE(SUM(pips), 0) AS pips, MIN(opened_at) AS since
            FROM signals WHERE status = 'closed'");
        $closedAll = (int) $all['closed'];
        $out['all'] = [
            'closed' => $closedAll,
            'wins' => (int) $all['wins'],
            'losses' => (int) $all['losses'],
            'pips' => round((float) $all['pips'], 1),
            'win_rate' => $closedAll ? round((int) $all['wins'] * 100 / $closedAll, 1) : 0.0,
            'since' => iso($all['since']),
        ];
        $out['running'] = (int) Db::one("SELECT COUNT(*) AS n FROM signals WHERE status = 'running'")['n'];
        return $out;
    }

    public static function show(): void
    {
        Auth::user();
        $period = (string) ($_GET['period'] ?? 'daily');
        if (!in_array($period, self::PERIODS, true)) throw new ApiError('period must be daily, weekly or monthly', 422);
        $limit = max(1, min(60, (int) ($_GET['limit'] ?? ($period === 'monthly' ? 12 : ($period === 'weekly' ? 12 : 20)))));
        Http::send([
            'period' => $period,
            'timezone' => self::tz()->getName(),
            'summary' => self::summary(),
            'items' => self::rows($period, $limit),
        ]);
    }

    /**
     * Called by cron: sends the daily (Mon–Fri after 22:00), weekly (Saturday
     * after 08:00) and monthly (1st after 08:00) reports once each.
     */
    public static function sendScheduled(int $nowTs): array
    {
        $log = [];
        $local = (new DateTimeImmutable('@' . $nowTs))->setTimezone(self::tz());
        $hour = (int) $local->format('G');
        $dow = (int) $local->format('N');
        $jobs = [];
        if ($dow <= 5 && $hour >= 22) {
            $jobs[] = ['daily', self::periodStart('daily', $local)];
        }
        if ($dow === 6 && $hour >= 8) {
            $jobs[] = ['weekly', self::periodStart('weekly', $local)];
        }
        if ((int) $local->format('j') === 1 && $hour >= 8) {
            $jobs[] = ['monthly', self::prev('monthly', self::periodStart('monthly', $local))];
        }
        foreach ($jobs as [$period, $start]) {
            $key = self::key($period, $start);
            $metaKey = "report_$period";
            $done = Db::one('SELECT v FROM meta WHERE k = ?', [$metaKey]);
            if ($done && $done['v'] === $key) continue;
            // Queue the report emails and mark the report as sent in one transaction, so a failure part-way
            // leaves it unsent (retried next run) instead of marked sent with nothing queued.
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                [$row, $signals] = self::window($period, $start);
                $n = Notify::report($period, $row, $signals, self::summary($nowTs));
                Db::exec('DELETE FROM meta WHERE k = ?', [$metaKey]);
                Db::exec('INSERT INTO meta (k, v) VALUES (?, ?)', [$metaKey, $key]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $log[] = "report $period: not sent, will retry ({$e->getMessage()})";
                continue;
            }
            $log[] = "report $period {$row['label']}: {$row['wins']}W/{$row['losses']}L {$row['pips']} pips, queued $n emails";
        }
        return $log;
    }
}
