<?php
declare(strict_types=1);

/**
 * Which markets and which strategy are doing best lately. Recomputed on every cron run from the tracked
 * results (after trading costs) and stored in `meta`, so the app can default new users to what is working.
 */
final class MarketRank
{
    public const WINDOW_DAYS = 14;
    public const MIN_TRADES = 8;
    public const TOP_N = 3;
    public const STRATEGY_DAYS = 90;
    public const STRATEGY_MIN = 20;
    public const PROVE_TRADES = 20;

    private static ?array $stored = null;

    private static function load(): array
    {
        if (self::$stored === null) {
            $row = Db::one('SELECT v FROM meta WHERE k = ?', ['market_rank']);
            self::$stored = $row ? (json_decode((string) $row['v'], true) ?: []) : [];
        }
        return self::$stored;
    }

    /** Markets to follow by default right now (best recent markets), empty if there is not enough data yet. */
    public static function top(): array
    {
        return array_values((array) (self::load()['top'] ?? []));
    }

    public static function info(): array
    {
        return self::load();
    }

    /** Strategy new users default to: best by live results, else the configured order. Never an opt-in strategy. */
    public static function bestStrategy(): string
    {
        $b = self::load()['best_strategy'] ?? null;
        if (is_string($b) && Strategies::valid($b) && in_array($b, Strategies::keys(), true)) return $b;
        // Until a strategy proves itself live (20+ results, positive after costs), use the agreed order:
        // Steady first (best balance of profit and drawdown in the 3-year backtest), classic last.
        // New config key so an older 'strategy_priors' (classic first) in a live config cannot override it.
        $prior = (array) config('strategy_order', ['steady', 'high_reward', 'adaptive', 'classic']);
        foreach ($prior as $k) if (Strategies::valid($k) && !Strategies::INFO[$k]['optin'] && in_array($k, Strategies::keys(), true)) return $k;
        return 'classic';
    }

    /** Strategy a user actually receives: their choice, else the best one. */
    public static function effectiveStrategy(?string $chosen): string
    {
        return ($chosen !== null && Strategies::valid($chosen)) ? $chosen : self::bestStrategy();
    }

    /**
     * The best markets as the app would have picked them at $ts: from trades closed in the 14 days before,
     * at least MIN_TRADES and a positive score after costs, top TOP_N; otherwise the configured default (gold).
     * Used to replay "follow the best markets" week by week without hindsight.
     */
    public static function pickAt(array $closedRows, int $ts): array
    {
        $main = (array) config('pairs', []);
        $from = now($ts - self::WINDOW_DAYS * 86400);
        $to = now($ts);
        $by = [];
        foreach ($closedRows as $s) {
            $c = (string) $s['closed_at'];
            if ($c < $from || $c >= $to || !in_array($s['pair'], $main, true)) continue;
            $by[$s['pair']]['trades'] = ($by[$s['pair']]['trades'] ?? 0) + 1;
            $by[$s['pair']]['score'] = ($by[$s['pair']]['score'] ?? 0.0) + Perf::net($s);
        }
        $ok = array_filter($by, fn($m) => $m['trades'] >= self::MIN_TRADES && $m['score'] > 0);
        uasort($ok, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice(array_keys($ok), 0, self::TOP_N);
        if ($top) return with_gold($top, $main);
        $d = array_values(array_intersect((array) config('default_pairs', ['XAU/USD']), $main));
        return $d ?: ['XAU/USD'];
    }

    public static function refresh(int $nowTs): array
    {
        $main = (array) config('pairs', []);
        $since = now($nowTs - self::WINDOW_DAYS * 86400);
        $rows = Db::all("SELECT * FROM signals WHERE status = 'closed' AND closed_at >= ? AND pips IS NOT NULL ORDER BY closed_at", [$since]);
        $by = [];
        foreach ($rows as $s) {
            $p = &$by[$s['pair']];
            $p = $p ?? ['pair' => $s['pair'], 'trades' => 0, 'wins' => 0, 'score' => 0.0, 'pips' => 0.0];
            $p['trades']++;
            $net = Perf::net($s);
            if ($net > 0) $p['wins']++;
            $p['score'] += $net;
            $p['pips'] += (float) $s['pips'] - spread_pips($s['pair'], (float) $s['entry']);
            unset($p);
        }
        // Only live markets (published signals) compete for the default.
        $live = [];
        $candidates = [];
        foreach (Db::all('SELECT DISTINCT pair, published FROM signals') as $r) {
            if ((int) $r['published'] === 1 && in_array($r['pair'], $main, true)) $live[$r['pair']] = true;
        }
        $markets = [];
        foreach ($main as $pair) {
            $p = $by[$pair] ?? ['pair' => $pair, 'trades' => 0, 'wins' => 0, 'score' => 0.0, 'pips' => 0.0];
            $markets[] = $p;
        }
        $eligible = array_values(array_filter($markets, fn($m) => $m['trades'] >= self::MIN_TRADES && $m['score'] > 0));
        usort($eligible, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_map(fn($m) => $m['pair'], array_slice($eligible, 0, self::TOP_N));
        foreach ($markets as &$m) {
            $m['status'] = in_array($m['pair'], $top, true) ? 'top'
                : ($m['trades'] < self::MIN_TRADES ? 'new' : ($m['score'] > 0 ? 'neutral' : 'cold'));
            $m['win_rate'] = $m['trades'] ? round($m['wins'] * 100 / $m['trades'], 1) : 0.0;
            $m['score'] = round($m['score'], 2);
            $m['pips'] = round($m['pips'], 1);
        }
        unset($m);

        // Markets that are only being watched (not sent to users yet): do they earn a place?
        $watch = [];
        foreach (Db::all("SELECT * FROM signals WHERE published = 0 AND status = 'closed' AND pips IS NOT NULL") as $s) {
            $w = &$watch[$s['pair']];
            $w = $w ?? ['pair' => $s['pair'], 'trades' => 0, 'wins' => 0, 'score' => 0.0, 'pips' => 0.0];
            $w['trades']++;
            $net = Perf::net($s);
            if ($net > 0) $w['wins']++;
            $w['score'] += $net;
            $w['pips'] += (float) $s['pips'] - spread_pips($s['pair'], (float) $s['entry']);
            unset($w);
        }
        foreach ($watch as &$w) {
            $w['status'] = $w['trades'] >= self::PROVE_TRADES ? ($w['score'] > 0 ? 'proven' : 'failed') : 'testing';
            $w['score'] = round($w['score'], 2);
            $w['pips'] = round($w['pips'], 1);
        }
        unset($w);

        // Strategy ranking on published signals of the last 90 days.
        $srows = Db::all("SELECT * FROM signals WHERE status = 'closed' AND published = 1 AND pips IS NOT NULL AND closed_at >= ?",
            [now($nowTs - self::STRATEGY_DAYS * 86400)]);
        $st = [];
        foreach ($srows as $s) {
            $k = $s['strategy'] ?? 'classic';
            $e = &$st[$k];
            $e = $e ?? ['strategy' => $k, 'trades' => 0, 'wins' => 0, 'score' => 0.0, 'pips' => 0.0];
            $e['trades']++;
            $net = Perf::net($s);
            if ($net > 0) $e['wins']++;
            $e['score'] += $net;
            $e['pips'] += (float) $s['pips'] - spread_pips($s['pair'], (float) $s['entry']);
            unset($e);
        }
        $ranked = array_values(array_filter($st, fn($e) => $e['trades'] >= self::STRATEGY_MIN && $e['score'] > 0
            && Strategies::valid($e['strategy']) && !Strategies::INFO[$e['strategy']]['optin']));
        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = $ranked[0]['strategy'] ?? null;
        foreach ($st as &$e) { $e['score'] = round($e['score'], 2); $e['pips'] = round($e['pips'], 1); $e['win_rate'] = $e['trades'] ? round($e['wins'] * 100 / $e['trades'], 1) : 0.0; }
        unset($e);

        $out = ['at' => iso(now($nowTs)), 'window_days' => self::WINDOW_DAYS, 'top' => $top, 'markets' => $markets,
            'watching' => array_values($watch), 'strategies' => array_values($st), 'best_strategy' => $best];
        Db::exec('DELETE FROM meta WHERE k = ?', ['market_rank']);
        Db::exec('INSERT INTO meta (k, v) VALUES (?, ?)', ['market_rank', json_encode($out)]);
        self::$stored = $out;
        return $out;
    }
}
