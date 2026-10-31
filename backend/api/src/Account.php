<?php
declare(strict_types=1);

final class Account
{
    public static function me(): void
    {
        Http::send(['user' => Auth::publicUser(Auth::user())]);
    }

    public static function update(): void
    {
        $u = Auth::user();
        $b = Http::body();
        if (array_key_exists('name', $b)) {
            Db::exec('UPDATE users SET name = ? WHERE id = ?', [Auth::validName((string) $b['name']), $u['id']]);
        }
        self::me();
    }

    public static function changePassword(): void
    {
        $u = Auth::user();
        $b = Http::body();
        if (!password_verify((string) ($b['current_password'] ?? ''), $u['password_hash'])) {
            throw new ApiError('Your current password is incorrect.', 400);
        }
        $pw = Auth::validPassword((string) ($b['new_password'] ?? ''));
        Db::exec('UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        $fresh = Db::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
        Http::send(['token' => Auth::tokenFor($fresh), 'user' => Auth::publicUser($fresh)]);
    }

    public static function delete(): void
    {
        $u = Auth::user();
        if (!password_verify((string) (Http::body()['password'] ?? ''), $u['password_hash'])) {
            throw new ApiError('Password is incorrect.', 400);
        }
        Db::exec('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        Db::exec('DELETE FROM users WHERE id = ?', [$u['id']]);
        Http::send(['ok' => true]);
    }

    public static function notifications(): void
    {
        $u = Auth::user();
        $b = Http::body();
        $pairs = array_key_exists('pairs', $b) ? $b['pairs'] : ($u['notify_pairs'] ? json_decode($u['notify_pairs'], true) : null);
        if ($pairs !== null) {
            if (!is_array($pairs)) throw new ApiError('pairs must be a list', 422);
            $pairs = array_values(array_intersect($pairs, config('pairs')));
        }
        $bool = fn($arr, $key, $current) => array_key_exists($key, $arr) ? (!empty($arr[$key]) ? 1 : 0) : (int) $current;
        $email = is_array($b['email'] ?? null) ? $b['email'] : [];
        Db::exec('UPDATE users SET notify_signals = ?, notify_results = ?, notify_reports = ?, notify_pairs = ?,
                  email_signals = ?, email_results = ?, email_daily = ?, email_weekly = ?, email_monthly = ? WHERE id = ?', [
            $bool($b, 'signals', $u['notify_signals']),
            $bool($b, 'results', $u['notify_results']),
            $bool($b, 'reports', $u['notify_reports']),
            $pairs === null ? null : json_encode($pairs),
            $bool($email, 'signals', $u['email_signals']),
            $bool($email, 'results', $u['email_results']),
            $bool($email, 'daily', $u['email_daily']),
            $bool($email, 'weekly', $u['email_weekly']),
            $bool($email, 'monthly', $u['email_monthly']),
            $u['id'],
        ]);
        self::me();
    }

    public static function watchlist(): void
    {
        $u = Auth::user();
        $symbols = Http::body()['symbols'] ?? null;
        if (!is_array($symbols)) throw new ApiError('symbols must be a list', 422);
        $symbols = array_values(array_unique(array_intersect($symbols, config('pairs'))));
        Db::exec('UPDATE users SET watchlist = ? WHERE id = ?', [json_encode($symbols), $u['id']]);
        self::me();
    }
}
