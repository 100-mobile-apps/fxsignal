<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: ' . config('cors_origin', '*'));
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-App-Build');
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

$routes = api_routes();

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
