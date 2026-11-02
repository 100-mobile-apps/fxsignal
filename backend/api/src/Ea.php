<?php
declare(strict_types=1);

/**
 * Auto-trade bot support (MetaTrader EA). The EA never sees broker logins on our side: it polls
 * GET /ea/signals with a personal read-only key and places the trades on the user's own terminal.
 */
final class Ea
{
    /** GET /me/ea-key shows the key, POST /me/ea-key creates it or replaces it with a new one. */
    public static function key(): void
    {
        $u = Auth::user();
        $key = $u['ea_key'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || !$key) {
            $key = bin2hex(random_bytes(16));
            Db::exec('UPDATE users SET ea_key = ? WHERE id = ?', [$key, $u['id']]);
        }
        Http::send(['key' => $key, 'url' => self::baseUrl() . '/ea/signals']);
    }

    private static function baseUrl(): string
    {
        $b = config('public_url', '');
        return rtrim(is_string($b) && $b !== '' ? $b : 'https://forexsignal.bulksend.top', '/');
    }

    private static function userByKey(): array
    {
        $k = $_SERVER['HTTP_X_EA_KEY'] ?? ($_GET['key'] ?? '');
        $k = is_string($k) ? trim($k) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $k)) throw new ApiError('Bad key', 401);
        $u = Db::one('SELECT * FROM users WHERE ea_key = ?', [$k]);
        if (!$u) throw new ApiError('Bad key', 401);
        return $u;
    }

    /** What the bot should trade right now, plus recently closed signals so it can tidy up. */
    public static function signals(): void
    {
        $u = self::userByKey();
        $strategy = MarketRank::effectiveStrategy($u['strategy'] ?? null);
        $pairs = default_pairs();
        if (!empty($_GET['pairs'])) {
            $want = array_filter(array_map('trim', explode(',', (string) $_GET['pairs'])));
            $pairs = array_values(array_intersect(config('pairs'), $want));
        }
        $out = ['running' => [], 'closed' => []];
        if ($pairs) {
            $in = implode(',', array_fill(0, count($pairs), '?'));
            $rows = Db::all("SELECT * FROM signals WHERE status = 'running' AND published = 1 AND strategy = ? AND pair IN ($in) ORDER BY opened_at ASC", array_merge([$strategy], $pairs));
            foreach ($rows as $s) {
                $f = Signals::format($s);
                $out['running'][] = [
                    'id' => $f['id'], 'pair' => $f['pair'], 'direction' => $f['direction'],
                    'entry' => $f['entry'], 'stop_loss' => $f['stop_loss'], 'tp1' => $f['tp1'], 'tp2' => $f['tp2'],
                    'tp1_hit' => $f['tp1_hit_at'] !== null, 'lock_hit' => $f['lock_hit_at'] !== null,
                    'opened_at' => $f['opened_at'], 'expires_at' => $f['expires_at'],
                    'stop_pips' => $f['stop_pips'], 'tp1_pips' => $f['tp1_pips'], 'tp2_pips' => $f['tp2_pips'],
                ];
            }
            $since = now(time() - 3 * 86400);
            $rows = Db::all("SELECT id, pair, result, closed_at, close_price FROM signals WHERE status = 'closed' AND published = 1 AND strategy = ? AND closed_at >= ? AND pair IN ($in) ORDER BY closed_at DESC LIMIT 100", array_merge([$strategy, $since], $pairs));
            foreach ($rows as $s) {
                $out['closed'][] = ['id' => (int) $s['id'], 'pair' => $s['pair'], 'result' => $s['result'], 'closed_at' => iso($s['closed_at']),
                    'close_price' => $s['close_price'] !== null ? (float) $s['close_price'] : null];
            }
        }
        Http::send([
            'server_time' => iso(now()),
            'strategy' => $strategy,
            'markets' => $pairs,
            'risk_pct' => $u['risk_pct'] !== null ? (float) $u['risk_pct'] : 1.0,
            'signals' => $out['running'],
            'closed' => $out['closed'],
        ]);
    }
}
