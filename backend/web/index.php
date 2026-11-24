<?php
declare(strict_types=1);
/*
 * Forex Signals Live website: public pages, member dashboard, Premium (Paystack) and the affiliate programme.
 * Upload the contents of this folder to the document root, next to the existing api/ and admin/ folders.
 */
define('WEB_ROOT', __DIR__);
const WEB_VERSION = '1.0.0';

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/charts.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/pages/public.php';
require __DIR__ . '/pages/auth.php';
require __DIR__ . '/pages/app.php';
require __DIR__ . '/pages/premium.php';
require __DIR__ . '/pages/affiliate.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
    . "form-action 'self' https://checkout.paystack.com https://*.paystack.co https://*.paystack.com; frame-ancestors 'none'; base-uri 'self'");

start_session();
capture_referral();

$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') check_csrf();

$routes = [
    // public
    '#^/$#' => 'page_home',
    '#^/results$#' => 'page_results_public',
    '#^/how-it-works$#' => 'page_how',
    '#^/pricing$#' => 'page_pricing',
    '#^/affiliates$#' => 'page_affiliates_public',
    '#^/tools$#' => 'page_tools_public',
    '#^/(terms|privacy|risk|affiliate-terms)$#' => 'page_legal',
    '#^/sitemap\.xml$#' => 'page_sitemap',
    '#^/robots\.txt$#' => 'page_robots',
    '#^/r/(?P<code>[A-Za-z0-9]{4,20})$#' => 'page_ref',
    '#^/go/broker$#' => 'page_go_broker',
    // auth
    '#^/login$#' => 'page_login',
    '#^/register$#' => 'page_register',
    '#^/forgot$#' => 'page_forgot',
    '#^/reset$#' => 'page_reset',
    '#^/verify$#' => 'page_verify',
    '#^/logout$#' => 'page_logout',
    // members
    '#^/app$#' => 'page_signals',
    '#^/app/history$#' => 'page_history',
    '#^/app/signal/(?P<id>\d+)$#' => 'page_signal',
    '#^/app/results$#' => 'page_results',
    '#^/app/reports$#' => 'page_reports',
    '#^/app/markets$#' => 'page_markets',
    '#^/app/tools$#' => 'page_tools',
    '#^/app/account$#' => 'page_account',
    '#^/app/live\.json$#' => 'page_live_json',
    '#^/app/premium$#' => 'page_premium',
    '#^/app/premium/return$#' => 'page_premium_return',
    '#^/app/affiliate$#' => 'page_affiliate',
];

if (free_mode()) {
    if ($path === '/pricing') redirect('/');
    if (str_starts_with($path, '/app/premium')) redirect('/app');
}

try {
    foreach ($routes as $re => $fn) {
        if (preg_match($re, $path, $m)) {
            $fn($m);
            exit;
        }
    }
    page_not_found();
} catch (Throwable $e) {
    error_log('[fx-web] ' . $e);
    http_response_code(500);
    page_start(['title' => 'Something went wrong · ' . BRAND, 'noindex' => true]);
    public_header();
    echo '<main class="wrap narrow center pad"><h1>Something went wrong</h1><p class="muted">Please try again in a moment.</p><a class="btn btn-gold" href="/">Go home</a></main>';
    site_footer();
}
