<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

$configFile = getenv('FX_CONFIG') ?: __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server not configured. Copy src/config.sample.php to src/config.php and fill it in.']);
    exit;
}
$GLOBALS['__CONFIG'] = require $configFile;

function config(string $key, $default = null)
{
    return $GLOBALS['__CONFIG'][$key] ?? $default;
}

function now(?int $ts = null): string
{
    return gmdate('Y-m-d H:i:s', $ts ?? time());
}

/** ISO-8601 UTC string for a DB datetime. */
function iso(?string $dt): ?string
{
    return $dt ? str_replace(' ', 'T', substr($dt, 0, 19)) . 'Z' : null;
}

function pip_size(string $pair): float
{
    if (strpos($pair, 'XAU') === 0) return 0.1;
    if (strpos($pair, 'JPY') !== false) return 0.01;
    return 0.0001;
}

function price_decimals(string $pair): int
{
    if (strpos($pair, 'XAU') === 0) return 2;
    if (strpos($pair, 'JPY') !== false) return 3;
    return 5;
}

/** FCM topic-safe name, e.g. "EUR/USD" -> "EURUSD". */
function topic_key(string $pair): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($pair));
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/Engine.php';
require_once __DIR__ . '/TradingPolicy.php';
require_once __DIR__ . '/Tracker.php';
require_once __DIR__ . '/TwelveData.php';
require_once __DIR__ . '/Fcm.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Market.php';
require_once __DIR__ . '/Cron.php';
require_once __DIR__ . '/EmailTemplates.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/Reports.php';
