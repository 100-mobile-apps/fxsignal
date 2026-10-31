<?php
declare(strict_types=1);

final class Signals
{
    public static function format(array $s, bool $withCandles = false): array
    {
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
        return $out;
    }

    public static function list(): void
    {
        Auth::user();
        $status = ($_GET['status'] ?? 'running') === 'closed' ? 'closed' : 'running';
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
            'items' => array_map([self::class, 'format'], array_slice($rows, 0, $per)),
            'page' => $page,
            'has_more' => $hasMore,
        ]);
    }

    public static function show(array $params): void
    {
        Auth::user();
        $row = Db::one('SELECT * FROM signals WHERE id = ?', [(int) $params['id']]);
        if (!$row) throw new ApiError('Signal not found', 404);
        Http::send(['signal' => self::format($row, true)]);
    }
}
