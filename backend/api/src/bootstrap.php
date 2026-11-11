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
// Optional settings file without secrets (Premium/strategy settings); overrides config.php.
if (!getenv('FX_CONFIG') && is_file(__DIR__ . '/config.premium.php')) {
    $GLOBALS['__CONFIG'] = array_replace($GLOBALS['__CONFIG'], (array) require __DIR__ . '/config.premium.php');
}

/** Settings the admin page may change; stored in the `settings` table and override the config files. */
const ADMIN_SETTINGS = [
    'premium_enforced', 'premium_test_emails', 'premium_emails', 'premium_markets', 'free_max_strength',
    'pairs', 'min_confidence', 'price_display', 'email_campaigns', 'bot',
];

function config(string $key, $default = null)
{
    static $loaded = false;
    if (!$loaded && in_array($key, ADMIN_SETTINGS, true)) {
        $loaded = true;
        try {
            foreach (Db::all('SELECT k, v FROM settings') as $row) {
                if (in_array($row['k'], ADMIN_SETTINGS, true)) $GLOBALS['__CONFIG'][$row['k']] = json_decode($row['v'], true);
            }
        } catch (Throwable $e) {
            // settings table not created yet: config files only
        }
    }
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
    // Crypto and indices: one "pip" = one point of price ($1 on BTC, 0.1 on ETH, 1 point on US100/US30).
    if (in_array($pair, ['BTC/USD', 'US100', 'US30'], true)) return 1.0;
    if ($pair === 'ETH/USD') return 0.1;
    if (strpos($pair, 'XAU') === 0) return 0.1;
    if (strpos($pair, 'JPY') !== false) return 0.01;
    return 0.0001;
}

function price_decimals(string $pair): int
{
    if (in_array($pair, ['BTC/USD', 'ETH/USD', 'US100', 'US30'], true)) return 2;
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
require_once __DIR__ . '/GoogleAuth.php';
require_once __DIR__ . '/Fcm.php';
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/Campaigns.php';
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
