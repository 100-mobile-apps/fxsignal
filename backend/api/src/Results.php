<?php
declare(strict_types=1);

/**
 * GET /results: what following the published signals would have made, in dollars and pips, for the account
 * size and risk the user chooses (default 10,000 at 1%). Every figure is after trading costs, and every dollar
 * figure is compounding: each signal risks the chosen share of the balance at that moment.
 */
final class Results
{
    public const RISKS = [1, 5, 10, 30];

    public static function show(): void
    {
        Auth::user();
        $account = max(10.0, min(10000000.0, (float) ($_GET['account'] ?? 10000)));
        $risk = max(0.1, min(50.0, (float) ($_GET['risk'] ?? 1)));
        $days = max(0, min(730, (int) ($_GET['days'] ?? 0)));
        $strategy = (string) ($_GET['strategy'] ?? '');
        $pairsParam = (string) ($_GET['pairs'] ?? '');

        $where = "status = 'closed' AND published = 1 AND pips IS NOT NULL";
        $params = [];
        if ($days > 0) { $where .= ' AND closed_at >= ?'; $params[] = now(time() - $days * 86400); }
        if ($strategy !== '' && Strategies::valid($strategy)) { $where .= ' AND strategy = ?'; $params[] = $strategy; }
        $all = Db::all("SELECT * FROM signals WHERE $where ORDER BY closed_at, id", $params);

        // pairs=best -> the current best markets; pairs=EUR/USD,XAU/USD -> those markets; empty -> every market
        $filterPairs = null;
        if ($pairsParam === 'best') $filterPairs = default_pairs();
        elseif ($pairsParam !== '') $filterPairs = array_values(array_intersect(array_map('trim', explode(',', $pairsParam)), array_merge((array) config('pairs', []))));
        $rows = $filterPairs === null ? $all : array_values(array_filter($all, fn($s) => in_array($s['pair'], $filterPairs, true)));

        $sim = Perf::simulate($rows, $account, $risk);

        // Month by month, chained so each month starts from the previous month's ending balance.
        $months = [];
        $byMonth = [];
        foreach ($sim['rows'] as $r) $byMonth[substr((string) $r['closed_at'], 0, 7)][] = $r;
        $start = $account;
        foreach ($byMonth as $m => $list) {
            $end = end($list)['balance'];
            $months[] = ['month' => $m, 'trades' => count($list), 'wins' => count(array_filter($list, fn($x) => $x['usd'] > 0)),
                'pips' => round(array_sum(array_column($list, 'pips')), 1), 'profit' => round($end - $start, 2), 'end_balance' => $end];
            $start = $end;
        }

        // Same trades at several risk levels, side by side (always includes 30%).
        $levels = [];
        $set = self::RISKS;
        if (!in_array((int) $risk, $set, true) || $risk != (int) $risk) $set[] = $risk;
        sort($set);
        foreach ($set as $rk) {
            $s = Perf::simulate($rows, $account, (float) $rk)['summary'];
            $levels[] = ['risk' => $rk, 'profit' => $s['profit'], 'end_balance' => $s['end_balance'],
                'max_drop_pct' => $s['max_drop_pct'], 'lowest_balance' => $s['lowest_balance']];
        }

        // Each market on its own, as if only that market had been traded.
        $perMarket = [];
        $pairs = [];
        foreach ($all as $s) $pairs[$s['pair']][] = $s;
        $rank = MarketRank::info();
        $status = [];
        foreach ((array) ($rank['markets'] ?? []) as $m) $status[$m['pair']] = $m['status'];
        foreach ($pairs as $pair => $list) {
            $s = Perf::simulate($list, $account, $risk)['summary'];
            $avgStop = array_sum(array_map([Perf::class, 'stopPips'], $list)) / count($list);
            $lastEntry = (float) end($list)['entry'];
            $pv = pip_value_per_lot($pair, $lastEntry);
            $perMarket[] = ['pair' => $pair, 'trades' => $s['trades'], 'win_rate' => $s['win_rate'], 'pips' => $s['pips'],
                'profit' => $s['profit'], 'max_drop_pct' => $s['max_drop_pct'], 'status' => $status[$pair] ?? 'new',
                'avg_stop_pips' => round($avgStop, 1), 'pip_value_per_lot' => round($pv, 2),
                'min_risk' => round(0.02 * $avgStop * $pv, 2)];   // two 0.01-lot trades is the smallest two-trade position
        }
        usort($perMarket, fn($a, $b) => $b['profit'] <=> $a['profit']);

        $perStrategy = [];
        $strat = [];
        foreach ($all as $s) $strat[$s['strategy'] ?? 'classic'][] = $s;
        foreach ($strat as $k => $list) {
            $s = Perf::simulate($list, $account, $risk)['summary'];
            $perStrategy[] = ['strategy' => $k, 'name' => Strategies::name($k), 'trades' => $s['trades'], 'win_rate' => $s['win_rate'],
                'pips' => $s['pips'], 'profit' => $s['profit'], 'max_drop_pct' => $s['max_drop_pct']];
        }
        usort($perStrategy, fn($a, $b) => $b['profit'] <=> $a['profit']);

        Http::send([
            'params' => ['account' => $account, 'risk' => $risk, 'days' => $days, 'strategy' => $strategy ?: null,
                'pairs' => $filterPairs === null ? null : $filterPairs],
            'basis' => 'live',
            'note' => 'Based on the signals this app has actually published and tracked. Each signal is counted as two half-size '
                . 'trades (TP1 and TP2) with the stop moved to entry, then to TP1, as the alerts tell you. Figures are after typical '
                . 'trading costs and past results do not guarantee future results.',
            'since' => $rows ? iso($rows[0]['closed_at']) : null,
            'summary' => $sim['summary'],
            'risk_levels' => $levels,
            'months' => $months,
            'by_market' => $perMarket,
            'by_strategy' => $perStrategy,
            'trades' => array_slice(array_reverse($sim['rows']), 0, 60),
            'best_markets' => (array) ($rank['top'] ?? []),
            'best_strategy' => MarketRank::bestStrategy(),
        ]);
    }

    /** GET /strategies and /markets/rank: what the app needs to show the pickers. */
    public static function strategies(): void
    {
        $u = Auth::user();
        $rank = MarketRank::info();
        $live = [];
        foreach ((array) ($rank['strategies'] ?? []) as $e) $live[$e['strategy']] = $e;
        $items = [];
        foreach (Strategies::keys() as $k) {
            $i = Strategies::INFO[$k];
            $items[] = ['key' => $k, 'name' => $i['name'], 'blurb' => $i['blurb'], 'opt_in' => $i['optin'],
                'live' => $live[$k] ?? null];
        }
        Http::send([
            'items' => $items,
            'default' => MarketRank::bestStrategy(),
            'selected' => MarketRank::effectiveStrategy($u['strategy'] ?? null),
            'markets' => (array) ($rank['markets'] ?? []),
            'best_markets' => default_pairs(),
            'watching' => (array) ($rank['watching'] ?? []),
        ]);
    }
}
