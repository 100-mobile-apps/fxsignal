<?php
declare(strict_types=1);

/** Money maths shared by the rankings and the results dashboard (all figures net of trading costs). */
final class Perf
{
    /** Stop distance in pips. */
    public static function stopPips(array $s): float
    {
        return abs((float) $s['entry'] - (float) $s['stop_loss']) / pip_size($s['pair']);
    }

    /** Result as a fraction of the amount risked (1.0 = made exactly what the stop would have lost), after costs. */
    public static function net(array $s): float
    {
        $stop = self::stopPips($s);
        if ($stop <= 0 || $s['pips'] === null) return 0.0;
        return ((float) $s['pips'] - spread_pips($s['pair'], (float) $s['entry'])) / $stop;
    }

    /**
     * Replay closed signals (oldest first) on an account that risks $riskPct percent of its balance per signal.
     * @return array summary + per-trade rows
     */
    public static function simulate(array $signals, float $account, float $riskPct): array
    {
        $bal = $account;
        $peak = $account;
        $low = $account;
        $maxDrop = 0.0;
        $run = 0;
        $worstRun = 0;
        $wins = 0;
        $pips = 0.0;
        $best = 0.0;
        $worst = 0.0;
        $rows = [];
        foreach ($signals as $s) {
            $r = self::net($s);
            $risk = $bal * $riskPct / 100;
            $usd = $risk * $r;
            $bal += $usd;
            if ($bal < 0) $bal = 0.0;
            $peak = max($peak, $bal);
            $low = min($low, $bal);
            $maxDrop = max($maxDrop, $peak > 0 ? ($peak - $bal) / $peak * 100 : 0);
            $netPips = (float) $s['pips'] - spread_pips($s['pair'], (float) $s['entry']);
            $pips += $netPips;
            if ($usd > 0) { $wins++; $run = 0; } else { $run++; $worstRun = max($worstRun, $run); }
            $best = max($best, $usd);
            $worst = min($worst, $usd);
            $rows[] = ['id' => (int) $s['id'], 'pair' => $s['pair'], 'strategy' => $s['strategy'] ?? 'classic', 'direction' => $s['direction'],
                'closed_at' => iso($s['closed_at']), 'result' => $s['result'], 'pips' => round($netPips, 1),
                'risked' => round($risk, 2), 'usd' => round($usd, 2), 'balance' => round($bal, 2)];
        }
        $n = count($signals);
        return ['summary' => [
            'trades' => $n, 'wins' => $wins, 'losses' => $n - $wins,
            'win_rate' => $n ? round($wins * 100 / $n, 1) : 0.0,
            'pips' => round($pips, 1), 'profit' => round($bal - $account, 2), 'end_balance' => round($bal, 2),
            'max_drop_pct' => round($maxDrop, 1), 'lowest_balance' => round($low, 2),
            'worst_losing_run' => $worstRun, 'best_trade' => round($best, 2), 'worst_trade' => round($worst, 2),
        ], 'rows' => $rows];
    }
}
