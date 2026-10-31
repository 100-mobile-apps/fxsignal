<?php
declare(strict_types=1);

class ApiError extends Exception
{
    public $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

final class Http
{
    public static function send($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new ApiError('Invalid JSON body', 400);
        return $data;
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$h && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') $h = $v;
            }
        }
        if ($h && preg_match('/Bearer\s+(\S+)/i', $h, $m)) return $m[1];
        return null;
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Fixed-window rate limit stored in the rate_limits table. */
    public static function rateLimit(string $key, int $max, int $windowSeconds): void
    {
        $since = now(time() - $windowSeconds);
        $row = Db::one('SELECT COUNT(*) AS n FROM rate_limits WHERE k = ? AND created_at >= ?', [$key, $since]);
        if ((int) $row['n'] >= $max) {
            throw new ApiError('Too many attempts. Please wait a few minutes and try again.', 429);
        }
        Db::exec('INSERT INTO rate_limits (k, created_at) VALUES (?, ?)', [$key, now()]);
        if (random_int(1, 50) === 1) {
            Db::exec('DELETE FROM rate_limits WHERE created_at < ?', [now(time() - 86400)]);
        }
    }
}
