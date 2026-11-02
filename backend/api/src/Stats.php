<?php
declare(strict_types=1);

final class Stats
{
    public static function compute(array $closed, int $running): array
    {
        $total = count($closed);
        $wins = 0;
        $losses = 0;
        $pips = 0.0;
        $winPips = 0.0;
        $lossPips = 0.0;
        $byPair = [];
        $monthly = [];
        $equity = [];

        // oldest first for the equity curve
        usort($closed, fn($a, $b) => strcmp($a['closed_at'], $b['closed_at']));
        $cum = 0.0;
        foreach ($closed as $s) {
            $p = (float) $s['pips'];
            $pips += $p;
            if ($p > 0) { $wins++; $winPips += $p; }
            elseif ($p < 0) { $losses++; $lossPips += -$p; }

            $bp = &$byPair[$s['pair']];
            $bp = $bp ?? ['pair' => $s['pair'], 'total' => 0, 'wins' => 0, 'pips' => 0.0];
            $bp['total']++;
            if ($p > 0) $bp['wins']++;
            $bp['pips'] += $p;
            unset($bp);

            $m = substr($s['closed_at'], 0, 7);
            $mm = &$monthly[$m];
            $mm = $mm ?? ['month' => $m, 'total' => 0, 'wins' => 0, 'pips' => 0.0];
            $mm['total']++;
            if ($p > 0) $mm['wins']++;
            $mm['pips'] += $p;
            unset($mm);

            $cum += $p;
            $equity[] = ['t' => iso($s['closed_at']), 'pips' => round($cum, 1)];
        }

        $pairs = array_values(array_map(function ($b) {
            $b['win_rate'] = $b['total'] ? round($b['wins'] * 100 / $b['total'], 1) : 0.0;
            $b['pips'] = round($b['pips'], 1);
            return $b;
        }, $byPair));
        usort($pairs, fn($a, $b) => $b['pips'] <=> $a['pips']);

        $months = array_values(array_map(function ($m) {
            $m['pips'] = round($m['pips'], 1);
            return $m;
        }, $monthly));

        return [
            'total' => $total,
            'wins' => $wins,
            'losses' => $losses,
            'running' => $running,
            'win_rate' => $total ? round($wins * 100 / $total, 1) : 0.0,
            'total_pips' => round($pips, 1),
            'avg_win_pips' => $wins ? round($winPips / $wins, 1) : 0.0,
            'avg_loss_pips' => $losses ? round($lossPips / $losses, 1) : 0.0,
            'profit_factor' => $lossPips > 0 ? round($winPips / $lossPips, 2) : null,
            'best_pair' => $pairs[0]['pair'] ?? null,
            'by_pair' => $pairs,
            'monthly' => $months,
            'equity' => $equity,
        ];
    }

    public static function show(): void
    {
        Auth::user();
        $days = (int) ($_GET['days'] ?? 30);
        $params = [];
        $where = "status = 'closed' AND published = 1";
        if ($days > 0) {
            $where .= ' AND closed_at >= ?';
            $params[] = now(time() - $days * 86400);
        }
        $closed = Db::all("SELECT pair, pips, closed_at FROM signals WHERE $where", $params);
        $running = (int) Db::one("SELECT COUNT(*) AS n FROM signals WHERE status = 'running' AND published = 1")['n'];
        $stats = self::compute($closed, $running);
        $stats['days'] = $days;
        Http::send($stats);
    }
}
