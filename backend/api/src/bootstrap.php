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

/**
 * Markets a user follows until they choose their own: the best recent markets (see MarketRank) when ranking is on
 * and enough results exist, otherwise config 'default_pairs', otherwise every pair.
 */
function default_pairs(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $all = (array) config('pairs', []);
    $ranked = config('auto_default_pairs', true) && class_exists('MarketRank') ? MarketRank::top() : [];
    $ranked = array_values(array_intersect($ranked, $all));
    if ($ranked) return $cache = $ranked;
    $d = config('default_pairs');
    $d = is_array($d) ? array_values(array_intersect($d, $all)) : [];
    return $cache = ($d ?: $all);
}

/** Markets this user follows: their own choice if they made one, else the best recent markets (default_pairs). */
function user_pairs(array $u): array
{
    $all = (array) config('pairs', []);
    $chosen = !empty($u['notify_pairs']) ? json_decode((string) $u['notify_pairs'], true) : null;
    if (is_array($chosen)) {
        $chosen = array_values(array_intersect($chosen, $all));
        if ($chosen) return $chosen;
    }
    return default_pairs();
}

/** Where user_pairs() came from: 'chosen' by the user, 'best' recent markets, or the configured 'default'. */
function user_pairs_source(array $u): string
{
    $chosen = !empty($u['notify_pairs']) ? json_decode((string) $u['notify_pairs'], true) : null;
    if (is_array($chosen) && array_intersect($chosen, (array) config('pairs', []))) return 'chosen';
    $ranked = config('auto_default_pairs', true) && class_exists('MarketRank') ? MarketRank::top() : [];
    return array_intersect($ranked, (array) config('pairs', [])) ? 'best' : 'default';
}

/**
 * Pair filter for the signal list and stats: the viewer's markets unless ?markets=all.
 * Returns [sql fragment, params, info for the response].
 */
function markets_scope(array $u): array
{
    $mode = ($_GET['markets'] ?? 'mine') === 'all' ? 'all' : 'mine';
    $pairs = user_pairs($u);
    $info = ['mode' => $mode, 'markets' => $pairs, 'source' => user_pairs_source($u), 'best_markets' => default_pairs()];
    if ($mode === 'all' || !$pairs) return ['', [], $info];
    return [' AND pair IN (' . implode(',', array_fill(0, count($pairs), '?')) . ')', $pairs, $info];
}

function is_crypto(string $pair): bool
{
    return (bool) preg_match('#^(BTC|ETH)/#', $pair);
}

/** Price distance of one "pip" (gold 0.1, JPY 0.01, crypto 1 dollar, others 0.0001). */
function pip_size(string $pair): float
{
    if (strpos($pair, 'XAU') === 0) return 0.1;
    if (is_crypto($pair)) return 1.0;
    if (strpos($pair, 'JPY') !== false) return 0.01;
    return 0.0001;
}

function price_decimals(string $pair): int
{
    if (strpos($pair, 'XAU') === 0 || is_crypto($pair)) return 2;
    if (strpos($pair, 'JPY') !== false) return 3;
    return 5;
}

/** Typical round-trip trading cost in pips (FX 1.5, gold 3, BTC 0.05% and ETH 0.07% of price). */
function spread_pips(string $pair, float $price): float
{
    if (strpos($pair, 'XAU') === 0) return 3.0;
    if (strpos($pair, 'BTC/') === 0) return 0.0005 * $price;
    if (strpos($pair, 'ETH/') === 0) return 0.0007 * $price;
    return 1.5;
}

/** Approximate dollars per pip for a 1.00 lot (used for the lot-size and small-account warnings). */
function pip_value_per_lot(string $pair, float $price): float
{
    if (strpos($pair, 'XAU') === 0) return 10.0;            // 100 oz, $0.10 move
    if (is_crypto($pair)) return 1.0;                       // 1 coin, $1 move
    $quote = substr($pair, -3);
    if ($quote === 'USD') return 10.0;
    return $price > 0 ? 100000 * pip_size($pair) / $price : 10.0;   // JPY, CAD, CHF ... quote currency to USD (approx.)
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
require_once __DIR__ . '/Strategies.php';
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
require_once __DIR__ . '/Perf.php';
require_once __DIR__ . '/MarketRank.php';
require_once __DIR__ . '/Results.php';
require_once __DIR__ . '/Ea.php';
