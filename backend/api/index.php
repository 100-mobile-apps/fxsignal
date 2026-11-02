<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: ' . config('cors_origin', '*'));
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
$path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');

$routes = [
    ['GET', '#^/$#', fn() => Market::health()],
    ['GET', '#^/health$#', fn() => Market::health()],
    ['POST', '#^/auth/register$#', fn() => Auth::register()],
    ['POST', '#^/auth/login$#', fn() => Auth::login()],
    ['POST', '#^/auth/forgot$#', fn() => Auth::forgot()],
    ['POST', '#^/auth/reset$#', fn() => Auth::reset()],
    ['POST', '#^/auth/verify$#', fn() => Auth::verify()],
    ['POST', '#^/auth/verify/resend$#', fn() => Auth::resendVerification()],
    ['GET', '#^/me$#', fn() => Account::me()],
    ['PUT', '#^/me$#', fn() => Account::update()],
    ['DELETE', '#^/me$#', fn() => Account::delete()],
    ['PUT', '#^/me/password$#', fn() => Account::changePassword()],
    ['PUT', '#^/me/notifications$#', fn() => Account::notifications()],
    ['PUT', '#^/me/watchlist$#', fn() => Account::watchlist()],
    ['PUT', '#^/me/preferences$#', fn() => Account::preferences()],
    ['GET', '#^/me/ea-key$#', fn() => Ea::key()],
    ['POST', '#^/me/ea-key$#', fn() => Ea::key()],
    ['GET', '#^/ea/signals$#', fn() => Ea::signals()],
    ['GET', '#^/results$#', fn() => Results::show()],
    ['GET', '#^/strategies$#', fn() => Results::strategies()],
    ['GET', '#^/signals$#', fn() => Signals::list()],
    ['GET', '#^/signals/(?P<id>\d+)$#', fn($p) => Signals::show($p)],
    ['GET', '#^/stats$#', fn() => Stats::show()],
    ['GET', '#^/reports$#', fn() => Reports::show()],
    ['GET', '#^/email/unsubscribe$#', fn() => Notify::unsubscribe()],
    ['POST', '#^/email/unsubscribe$#', fn() => Notify::unsubscribe()],
    ['GET', '#^/market/prices$#', fn() => Market::prices()],
];

try {
    $matchedPath = false;
    foreach ($routes as [$m, $re, $handler]) {
        if (preg_match($re, $path, $params)) {
            $matchedPath = true;
            if ($m === $method) {
                $handler($params);
                exit;
            }
        }
    }
    throw new ApiError($matchedPath ? 'Method not allowed' : 'Not found', $matchedPath ? 405 : 404);
} catch (ApiError $e) {
    Http::send(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[fx-api] ' . $e);
    Http::send(['error' => config('debug', false) ? $e->getMessage() : 'Something went wrong. Please try again.'], 500);
}
