<?php
declare(strict_types=1);

final class Signals
{
    public static function format(array $s, bool $withCandles = false, bool $locked = false): array
    {
        $locked = $locked && $s['status'] === 'running';
        $out = [
            'id' => (int) $s['id'],
            'pair' => $s['pair'],
            'timeframe' => $s['timeframe'],
            'direction' => $s['direction'],
            'entry' => (float) $s['entry'],
            'stop_loss' => (float) $s['stop_loss'],
            'tp1' => (float) $s['tp1'],
            'tp2' => (float) $s['tp2'],
            'confidence' => (int) $s['confidence'],
            'reasons' => json_decode($s['reasons'] ?: '[]', true),
            'status' => $s['status'],
            'result' => $s['result'],
            'tp1_hit_at' => iso($s['tp1_hit_at']),
            'opened_at' => iso($s['opened_at']),
            'expires_at' => iso($s['expires_at']),
            'closed_at' => iso($s['closed_at']),
            'close_price' => $s['close_price'] !== null ? (float) $s['close_price'] : null,
            'pips' => $s['pips'] !== null ? (float) $s['pips'] : null,
            'current_price' => $s['current_price'] !== null ? (float) $s['current_price'] : null,
            'live_pips' => $s['live_pips'] !== null ? (float) $s['live_pips'] : null,
            'updated_at' => iso($s['updated_at']),
        ];
        if ($withCandles) {
            $out['candles'] = json_decode($s['candles'] ?: '[]', true);
        }
        $out['locked'] = $locked;
        $out['premium'] = Billing::signalIsPremium($s);
        if ($locked) {
            // Live levels are premium. Anything the entry could be worked out from is removed too.
            foreach (['entry', 'stop_loss', 'tp1', 'tp2', 'live_pips'] as $k) $out[$k] = null;
            $out['reasons'] = [];
        }
        return $out;
    }

    /**
     * App versions from before subscriptions cannot show locked signals, so they
     * are asked to update instead of receiving data they would mis-read.
     */
    private static function requireCurrentApp(): void
    {
        if (Billing::enforced() && Http::appBuild() < (int) config('min_app_build', 5)) {
            throw new ApiError('Forex Signals Live has a new version. Please update the app from Google Play to see live signals.', 426);
        }
    }

    public static function list(): void
    {
        $user = Auth::user();
        $status = ($_GET['status'] ?? 'running') === 'closed' ? 'closed' : 'running';
        if ($status === 'running') self::requireCurrentApp();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 20;
        $params = [$status];
        $where = 'status = ?';
        // Optional closed-at window for reports: ?from=2026-09-01T00:00:00Z&to=...
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (!empty($_GET[$k]) && ($ts = strtotime((string) $_GET[$k])) !== false) {
                $where .= " AND closed_at $op ?";
                $params[] = now($ts);
            }
        }
        if (!empty($_GET['pair'])) {
            $where .= ' AND pair = ?';
            $params[] = (string) $_GET['pair'];
        }
        $order = $status === 'closed' ? 'closed_at DESC, id DESC' : 'opened_at DESC, id DESC';
        $offset = ($page - 1) * $per;
        $rows = Db::all("SELECT * FROM signals WHERE $where ORDER BY $order LIMIT " . ($per + 1) . " OFFSET $offset", $params);
        $hasMore = count($rows) > $per;
        Http::send([
            'items' => array_map(fn($r) => self::format($r, false, Billing::lockedFor($user, $r)), array_slice($rows, 0, $per)),
            'page' => $page,
            'has_more' => $hasMore,
        ]);
    }

    public static function show(array $params): void
    {
        $user = Auth::user();
        $row = Db::one('SELECT * FROM signals WHERE id = ?', [(int) $params['id']]);
        if (!$row) throw new ApiError('Signal not found', 404);
        if ($row['status'] === 'running') self::requireCurrentApp();
        Http::send(['signal' => self::format($row, true, Billing::lockedFor($user, $row))]);
    }
}
