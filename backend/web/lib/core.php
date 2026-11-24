<?php
declare(strict_types=1);

/*
 * Website core: sessions, CSRF, calling the API in-process, referral tracking, formatting.
 * The website uses the same database and the same API handlers as the mobile app, so
 * validation, Premium locking and rate limits are identical on both.
 */

$apiDir = is_dir(WEB_ROOT . '/api/src') ? WEB_ROOT . '/api' : dirname(WEB_ROOT) . '/api';
require $apiDir . '/src/bootstrap.php';

const BRAND = 'Forex Signals Live';
const PLAY_PACKAGE = 'com.forexsignalslive.forex_signals';

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function site_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = (string) config('site_url', '');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $base = ($https ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $base = rtrim($base, '/');
    }
    return $base . $path;
}

function play_url(?string $ref = null): string
{
    $u = 'https://play.google.com/store/apps/details?id=' . PLAY_PACKAGE;
    return $ref ? $u . '&referrer=' . rawurlencode('ref=' . $ref) : $u;
}

function start_session(): void
{
    $https = str_starts_with(site_url(), 'https://');
    session_name('fxweb');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function check_csrf(): void
{
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        flash('error', 'Your session expired. Please try again.');
        redirect($_SERVER['REQUEST_URI'] ?? '/');
    }
}

function redirect(string $to): never
{
    if (!str_starts_with($to, '/') || str_starts_with($to, '//')) $to = '/';
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/** Remembers form values after a failed POST. */
function old(string $k, string $default = ''): string { return (string) ($_SESSION['old'][$k] ?? $default); }

// ---- signed-in user --------------------------------------------------------

function current_user(bool $fresh = false): ?array
{
    static $user = false;
    if ($user !== false && !$fresh) return $user;
    $user = null;
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id) {
        $row = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($row && (int) $row['token_version'] === (int) ($_SESSION['tv'] ?? -1) && empty($row['blocked_at'])) {
            $user = $row;
        } else {
            unset($_SESSION['uid'], $_SESSION['tv']);
        }
    }
    return $user;
}

function sign_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['tv'] = (int) $user['token_version'];
    unset($_SESSION['csrf']);
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/app';
        redirect('/login');
    }
    return $u;
}

function is_admin(?array $u): bool
{
    return $u && in_array(strtolower((string) $u['email']), array_map('strtolower', (array) config('admin_emails', [])), true);
}

/**
 * Calls an API handler in-process as the signed-in user.
 * @return array{0:int,1:array} [HTTP status, decoded JSON]
 */
function api(string $method, string $path, array $query = [], ?array $body = null, ?array $as = null): array
{
    $as = $as ?? current_user();
    $saveGet = $_GET;
    $_GET = $query;
    $_SERVER['HTTP_AUTHORIZATION'] = $as ? 'Bearer ' . Auth::tokenFor($as) : '';
    $_SERVER['HTTP_X_APP_BUILD'] = '9999'; // the website is always the current version
    Http::$bodyOverride = $body === null ? '' : json_encode($body);
    Http::$capture = true;
    Http::$lastStatus = 200;
    ob_start();
    try {
        $found = false;
        foreach (api_routes() as [$m, $re, $handler]) {
            if ($m === $method && preg_match($re, $path, $params)) {
                $found = true;
                $handler($params);
                break;
            }
        }
        if (!$found) throw new ApiError('Not found', 404);
        $status = Http::$lastStatus;
    } catch (ApiError $e) {
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
        $status = $e->status;
    } catch (Throwable $e) {
        ob_clean();
        error_log('[fx-web] ' . $e);
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
        $status = 500;
    } finally {
        $out = (string) ob_get_clean();
        Http::$capture = false;
        Http::$bodyOverride = null;
        $_GET = $saveGet;
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    return [$status, json_decode($out, true) ?: []];
}

/** api() that throws the API's message for the page to show. */
function api_ok(string $method, string $path, array $query = [], ?array $body = null): array
{
    [$status, $data] = api($method, $path, $query, $body);
    if ($status >= 400) throw new ApiError((string) ($data['error'] ?? 'Something went wrong.'), $status);
    return $data;
}

// ---- referral links ---------------------------------------------------------

function capture_referral(): void
{
    $code = $_GET['ref'] ?? null;
    if (!is_string($code) || $code === '') return;
    try {
        $aff = Affiliates::activeByCode($code);
    } catch (Throwable $e) {
        return; // tables not migrated yet
    }
    if (!$aff) return;
    $cur = current_user();
    if ($cur && (int) $cur['id'] === (int) $aff['user_id']) return;
    Affiliates::click($aff, 'visit', Http::ip());
    if (empty($_COOKIE['fx_ref'])) { // first referrer wins
        $days = (int) Affiliates::settings()['cookie_days'];
        setcookie('fx_ref', $aff['code'], ['expires' => time() + $days * 86400, 'path' => '/', 'secure' => str_starts_with(site_url(), 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['fx_ref'] = $aff['code'];
    }
}

function referral_cookie(): string
{
    $c = (string) ($_COOKIE['fx_ref'] ?? '');
    return preg_match('/^[A-Za-z0-9]{4,20}$/', $c) ? strtoupper($c) : '';
}

// ---- formatting -------------------------------------------------------------

function fmt_price(string $pair, $v): string
{
    return $v === null ? '—' : number_format((float) $v, price_decimals($pair), '.', '');
}

function fmt_pips($p, bool $unit = false): string
{
    if ($p === null) return '—';
    $p = (float) $p;
    $s = ($p > 0 ? '+' : '') . (abs($p) >= 1000 ? number_format($p, 0) : number_format($p, 1));
    return $unit ? "$s pips" : $s;
}

function pip_class($p): string
{
    return $p === null ? '' : ((float) $p > 0 ? 'up' : ((float) $p < 0 ? 'down' : ''));
}

function ago(?string $iso): string
{
    if (!$iso) return '';
    $d = time() - strtotime($iso);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 86400 * 30) return floor($d / 86400) . 'd ago';
    return gmdate('j M Y', strtotime($iso));
}

function date_local(?string $iso, string $format = 'j M Y, H:i'): string
{
    if (!$iso) return '—';
    return (new DateTimeImmutable($iso))->setTimezone(Reports::tz())->format($format);
}

function result_label(array $s): array
{
    if ($s['status'] === 'running') return [$s['tp1_hit_at'] ? 'TP1 hit' : 'Live', $s['tp1_hit_at'] ? 'win' : 'live'];
    return match ($s['result']) {
        'tp2' => ['TP2 hit', 'win'],
        'tp1' => ['TP1 hit', 'win'],
        'sl' => ['Stop loss', 'loss'],
        'expired' => ['Expired', (float) $s['pips'] > 0 ? 'win' : ((float) $s['pips'] < 0 ? 'loss' : '')],
        default => [ucfirst((string) $s['result']), ''],
    };
}

function flag(string $pair): string
{
    $map = ['EUR' => 'eu', 'USD' => 'us', 'GBP' => 'gb', 'JPY' => 'jp', 'AUD' => 'au', 'CAD' => 'ca', 'CHF' => 'ch', 'NZD' => 'nz'];
    $out = '';
    foreach (explode('/', $pair) as $c) {
        if (isset($map[$c])) {
            $out .= '<span class="coin coin-' . $map[$c] . '">' . e($c) . '</span>';
        } else {
            $out .= '<span class="coin coin-x">' . e($c === 'XAU' ? 'Au' : ($c === 'BTC' ? '₿' : ($c === 'ETH' ? 'Ξ' : $c))) . '</span>';
        }
    }
    return '<span class="pairicon">' . $out . '</span>';
}

function money_usd(float $v): string { return '$' . number_format($v, 2); }

function money_local(float $amount, string $currency): string
{
    $sym = ['NGN' => '₦', 'USD' => '$', 'GHS' => 'GH₵', 'KES' => 'KSh', 'ZAR' => 'R'][strtoupper($currency)] ?? (strtoupper($currency) . ' ');
    return $sym . number_format($amount, $amount == floor($amount) ? 0 : 2);
}

/** Free mode: Premium is switched off, so every signal is free and nothing is for sale. */
function free_mode(): bool { return !Billing::enforced(); }

/** Prices for the Pricing and Premium pages. */
function price_info(): array
{
    $web = Payments::webPricing();
    $play = (array) config('price_display', []) + ['monthly' => '$4.99', 'yearly' => '$35.99', 'launch_monthly' => '$1.50', 'launch_yearly' => '$12', 'launch_label' => 'Launch offer', 'launch_ends' => ''];
    return ['web' => $web, 'play' => $play, 'card_ready' => Paystack::configured() && (float) $web['monthly_amount'] > 0];
}
