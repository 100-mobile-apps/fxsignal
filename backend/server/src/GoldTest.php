<?php
declare(strict_types=1);

/**
 * GET /gold-test: the gold live test. Rules were fixed before it started (4 November 2026) and must not change:
 * Steady and Gold breakout on XAU/USD, live published signals only, at least 90 days and 60 closed trades.
 * Pass: profit after costs above zero at 2% risk, profit factor 1.2 or more, worst drop under 15% (a drop of 15%
 * or more fails the test at once). Figures use the app's own two-half tracking and typical costs.
 */
final class GoldTest
{
    public const START = '2026-11-04 00:00:00';
    public const PAIR = 'XAU/USD';
    public const STRATEGIES = ['steady', 'turtle'];
    public const MIN_DAYS = 90;
    public const MIN_TRADES = 60;
    public const RISK_PCT = 2.0;
    public const ACCOUNT = 10000.0;
    public const MIN_PROFIT_FACTOR = 1.2;
    public const MAX_DROP_PCT = 15.0;

    public static function show(): void
    {
        Auth::user();
        Http::send(self::compute(time()));
    }

    public static function compute(int $nowTs): array
    {
        $in = implode(',', array_fill(0, count(self::STRATEGIES), '?'));
        $params = array_merge([self::PAIR], self::STRATEGIES, [self::START]);
        $closed = Db::all("SELECT * FROM signals WHERE published = 1 AND status = 'closed' AND pips IS NOT NULL AND pair = ? AND strategy IN ($in) AND opened_at >= ? ORDER BY closed_at, id", $params);
        $running = Db::all("SELECT strategy FROM signals WHERE published = 1 AND status = 'running' AND pair = ? AND strategy IN ($in) AND opened_at >= ?", $params);

        $sim = Perf::simulate($closed, self::ACCOUNT, self::RISK_PCT);
        $s = $sim['summary'];
        $gain = 0.0; $loss = 0.0;
        foreach ($closed as $row) { $r = Perf::net($row); if ($r > 0) $gain += $r; else $loss += -$r; }
        $pf = $loss > 0 ? round($gain / $loss, 2) : ($gain > 0 ? null : 0.0);   // null = no losses yet

        $startTs = (int) strtotime(self::START . ' UTC');
        $day = max(1, (int) floor(($nowTs - $startTs) / 86400) + 1);
        $profitPct = round($s['profit'] / self::ACCOUNT * 100, 2);
        $checks = [
            ['key' => 'profit', 'label' => 'Profit after costs', 'need' => 'above $0 at 2% risk', 'value' => $profitPct, 'unit' => '%', 'pips' => $s['pips'], 'ok' => $s['profit'] > 0],
            ['key' => 'profit_factor', 'label' => 'Profit factor', 'need' => '1.2 or more', 'value' => $pf, 'unit' => '', 'ok' => $pf === null ? $gain > 0 : $pf >= self::MIN_PROFIT_FACTOR],
            ['key' => 'drop', 'label' => 'Worst drop', 'need' => 'under 15%', 'value' => $s['max_drop_pct'], 'unit' => '%', 'ok' => $s['max_drop_pct'] < self::MAX_DROP_PCT],
        ];
        $done = $day > self::MIN_DAYS && $s['trades'] >= self::MIN_TRADES;
        if ($s['max_drop_pct'] >= self::MAX_DROP_PCT) $status = 'failed';
        elseif ($done) $status = ($checks[0]['ok'] && $checks[1]['ok'] && $checks[2]['ok']) ? 'passed' : 'failed';
        else $status = 'running';

        $per = [];
        foreach (self::STRATEGIES as $k) $per[$k] = ['strategy' => $k, 'name' => Strategies::name($k), 'closed' => 0, 'running' => 0, 'pips' => 0.0, 'live' => in_array($k, Strategies::keys(), true)];
        foreach ($sim['rows'] as $r) { $per[$r['strategy']]['closed']++; $per[$r['strategy']]['pips'] = round($per[$r['strategy']]['pips'] + $r['pips'], 1); }
        foreach ($running as $r) $per[$r['strategy']]['running']++;

        return [
            'rules' => [
                'start' => iso(self::START), 'pair' => self::PAIR, 'strategies' => self::STRATEGIES, 'min_days' => self::MIN_DAYS,
                'min_trades' => self::MIN_TRADES, 'risk_pct' => self::RISK_PCT, 'account' => self::ACCOUNT,
                'min_profit_factor' => self::MIN_PROFIT_FACTOR, 'max_drop_pct' => self::MAX_DROP_PCT,
                'earliest_end' => iso(gmdate('Y-m-d H:i:s', $startTs + self::MIN_DAYS * 86400)),
            ],
            'status' => $status,
            'day' => $day,
            'trades' => $s['trades'],
            'wins' => $s['wins'],
            'running' => count($running),
            'checks' => $checks,
            'strategies' => array_values($per),
            'recent' => array_slice(array_reverse($sim['rows']), 0, 20),
        ];
    }
}
