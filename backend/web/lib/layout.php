<?php
declare(strict_types=1);

const LOGO_SVG = '<svg viewBox="0 0 100 100" aria-hidden="true"><g fill="currentColor"><rect x="22.5" y="50" width="3" height="32" rx="1.5"/><rect x="16" y="58" width="16" height="18" rx="3"/><rect x="48.5" y="32" width="3" height="40" rx="1.5"/><rect x="42" y="40" width="16" height="24" rx="3"/><rect x="74.5" y="14" width="3" height="42" rx="1.5"/><rect x="68" y="22" width="16" height="28" rx="3"/></g></svg>';

function icon(string $name): string
{
    static $p = [
        'signals' => 'M13 2L4 14h7l-1 8 9-12h-7z',
        'results' => 'M3 17l6-6 4 4 8-8M15 7h6v6',
        'reports' => 'M4 4h16v16H4zM8 9h8M8 13h8M8 17h5',
        'markets' => 'M3 12h4l3-8 4 16 3-8h4',
        'tools' => 'M4 4h16v16H4zM8 8h3M8 12h3M8 16h3M14 8h2M14 12h2M14 16h2',
        'premium' => 'M3 8l4 4 5-7 5 7 4-4-2 11H5z',
        'affiliate' => 'M16 11a4 4 0 10-8 0M4 20a8 8 0 0116 0M19 8v6M16 11h6',
        'account' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
        'lock' => 'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
        'copy' => 'M9 9h11v11H9zM5 15H4V4h11v1',
        'check' => 'M5 13l4 4L19 7',
        'broker' => 'M3 21h18M5 21V10M19 21V10M12 3l9 6H3zM9 21v-7M15 21v-7',
        'logout' => 'M15 12H3M7 8l-4 4 4 4M10 4h10v16H10',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'play' => 'M5 3l15 9-15 9z',
        'whatsapp' => 'M4 20l1.3-4A8 8 0 1112 20a8 8 0 01-4-1z',
        'admin' => 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($p[$name] ?? '') . '"/></svg>';
}

/**
 * <head> + opening body. $meta: title, description, canonical path, noindex, jsonld (array of objects).
 */
function page_start(array $meta, string $bodyClass = ''): void
{
    $title = $meta['title'] ?? BRAND;
    $desc = $meta['description'] ?? 'Free forex and gold signals with entry, stop loss and take profit, a public track record, alerts and trading tools.';
    $canonical = site_url($meta['canonical'] ?? strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    $og = site_url('/assets/og.png');
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex,nofollow"><?php endif ?>
<meta name="theme-color" content="#070B14">
<meta property="og:type" content="website"><meta property="og:site_name" content="<?= e(BRAND) ?>">
<meta property="og:title" content="<?= e($title) ?>"><meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>"><meta property="og:image" content="<?= e($og) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icon-192.png">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(WEB_VERSION) ?>">
<?php foreach ((array) ($meta['jsonld'] ?? []) as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach ?>
<script src="/assets/app.js?v=<?= e(WEB_VERSION) ?>" defer></script>
</head><body class="<?= e($bodyClass) ?>">
<?php
}

function flashes(): string
{
    $out = '';
    foreach (take_flash() as [$type, $msg]) $out .= '<div class="flash flash-' . e($type) . '" role="status">' . e($msg) . '</div>';
    return $out;
}

function brand_link(): string
{
    return '<a class="brand" href="/"><span class="logo">' . LOGO_SVG . '</span><span>' . e(BRAND) . '</span></a>';
}

function public_header(string $active = ''): void
{
    $u = current_user();
    $links = ['/results' => 'Results', '/how-it-works' => 'How it works', '/pricing' => 'Pricing', '/affiliates' => 'Affiliates'];
    ?>
<header class="topbar"><div class="wrap topbar-in">
  <?= brand_link() ?>
  <nav class="topnav" id="topnav">
    <?php foreach ($links as $href => $label): ?><a href="<?= e($href) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach ?>
  </nav>
  <div class="topcta">
    <?php if ($u): ?><a class="btn btn-gold btn-sm" href="/app">Open dashboard</a>
    <?php else: ?><a class="btn btn-ghost btn-sm" href="/login">Log in</a><a class="btn btn-gold btn-sm" href="/register">Start free</a><?php endif ?>
    <button class="iconbtn navtoggle" data-toggle="topnav" aria-label="Menu"><?= icon('menu') ?></button>
  </div>
</div></header>
<?php
    echo '<div class="wrap">' . flashes() . '</div>';
}

function site_footer(): void
{
    $dev = (array) config('developer', []) + ['name' => 'Swiftmynd Technologies', 'whatsapp' => 'https://wa.me/2347011401387', 'coffee' => 'https://buymeacoffee.com/swalafblogM'];
    $promo = (array) config('promo', []) + ['coffee' => true, 'whatsapp' => true, 'hire' => true];
    ?>
<footer class="footer"><div class="wrap">
  <div class="foot-grid">
    <div><?= brand_link() ?><p class="muted small">Free forex and gold signals with entry, stop loss and take profit, tracked openly win or loss.</p>
      <a class="playbadge" href="<?= e(play_url(referral_cookie() ?: null)) ?>" rel="noopener"><?= icon('play') ?><span><small>Get it on</small>Google Play</span></a></div>
    <div><h4>Product</h4><a href="/results">Track record</a><a href="/how-it-works">How signals work</a><a href="/pricing">Pricing</a><a href="/tools">Free calculators</a></div>
    <div><h4>Company</h4><a href="/affiliates">Affiliate programme</a><a href="mailto:swalafblog@gmail.com">Contact</a>
      <?php if (!empty($promo['hire'])): ?><a href="<?= e($dev['whatsapp']) ?>" rel="noopener" target="_blank">Hire the developer</a><?php endif ?>
      <?php if (!empty($promo['coffee'])): ?><a href="<?= e($dev['coffee']) ?>" rel="noopener" target="_blank">Buy me a coffee</a><?php endif ?></div>
    <div><h4>Legal</h4><a href="/terms">Terms of use</a><a href="/privacy">Privacy policy</a><a href="/risk">Risk disclaimer</a><a href="/affiliate-terms">Affiliate terms</a></div>
  </div>
  <p class="risk small">Risk warning: trading forex, gold and crypto on margin carries a high level of risk and may not be suitable for all investors. Signals are automated technical analysis for information and education only, not financial advice. Past results do not guarantee future results.</p>
  <p class="muted small">© <?= gmdate('Y') ?> Swiftmynd Technologies Ltd. Built by <?= e($dev['name']) ?>.</p>
</div></footer>
<?php if (!empty($promo['whatsapp'])): ?>
<a class="wa-fab" href="<?= e('https://wa.me/2347011401387?text=' . rawurlencode('Hi, I have a question about ' . BRAND . '.')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><?= icon('whatsapp') ?></a>
<?php endif ?>
</body></html>
<?php
}

const APP_NAV = [
    '/app' => ['Signals', 'signals'],
    '/app/results' => ['Results', 'results'],
    '/app/reports' => ['Reports', 'reports'],
    '/app/markets' => ['Markets', 'markets'],
    '/app/tools' => ['Tools', 'tools'],
    '/app/premium' => ['Premium', 'premium'],
    '/app/affiliate' => ['Affiliate', 'affiliate'],
    '/app/account' => ['Account', 'account'],
];

function app_start(string $title, string $active, string $heading = '', string $sub = ''): array
{
    $u = require_user();
    $pub = Auth::publicUser($u);
    page_start(['title' => "$title · " . BRAND, 'noindex' => true], 'app');
    ?>
<div class="shell">
  <aside class="side" id="side">
    <?= brand_link() ?>
    <nav class="sidenav">
      <?php foreach (APP_NAV as $href => [$label, $ic]): ?>
        <a href="<?= e($href) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span>
        <?php if ($href === '/app/premium' && !$pub['premium']): ?><em class="pill pill-gold">Upgrade</em><?php endif ?></a>
      <?php endforeach ?>
      <?php if (is_admin($u)): ?><a href="/admin/"><?= icon('admin') ?><span>Admin</span></a><?php endif ?>
    </nav>
    <?= broker_card($u) ?>
    <div class="side-user">
      <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) $u['name'], 0, 1))) ?></div>
      <div class="side-user-t"><b><?= e($u['name']) ?></b><span><?= $pub['premium'] ? 'Premium' : 'Free plan' ?></span></div>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="iconbtn" title="Log out" aria-label="Log out"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="main">
    <header class="mtop">
      <button class="iconbtn navtoggle" data-toggle="side" aria-label="Menu"><?= icon('menu') ?></button>
      <?= brand_link() ?>
    </header>
    <?php if (!$pub['email_verified'] && $active !== '/app/verify'): ?>
      <div class="banner">Verify your email to receive signal alerts. <a href="/verify">Enter your code</a></div>
    <?php endif ?>
    <?= flashes() ?>
    <?php if ($heading !== ''): ?><div class="pagehead"><div><h1><?= e($heading) ?></h1><?php if ($sub !== ''): ?><p class="muted"><?= e($sub) ?></p><?php endif ?></div></div><?php endif ?>
<?php
    return [$u, $pub];
}

function app_end(): void
{
    ?>
  </div>
</div>
<nav class="bottomnav">
  <?php foreach (array_slice(APP_NAV, 0, 5, true) as $href => [$label, $ic]): ?><a href="<?= e($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a><?php endforeach ?>
</nav>
</body></html>
<?php
}

function broker_card(?array $u): string
{
    try {
        $b = $u ? Affiliates::brokerFor($u) : Affiliates::defaultBroker();
    } catch (Throwable $e) {
        return '';
    }
    if (!$b) return '';
    return '<a class="brokercard" href="/go/broker" rel="sponsored noopener" target="_blank">' . icon('broker')
        . '<span><b>Open a broker account</b><small>with ' . e($b['name']) . ' · partner link</small></span></a>';
}

/** Signal card used on the Signals and History lists. */
function signal_card(array $s, array $pub): string
{
    $buy = $s['direction'] === 'BUY';
    [$label, $cls] = result_label($s);
    $running = $s['status'] === 'running';
    $pips = $running ? $s['live_pips'] : $s['pips'];
    $h = '<a class="sigcard ' . ($buy ? 'buy' : 'sell') . ($s['locked'] ? ' locked' : '') . '" href="/app/signal/' . (int) $s['id'] . '" data-sig="' . (int) $s['id'] . '">';
    $h .= '<div class="sig-top">' . flag($s['pair']) . '<div class="sig-name"><b>' . e($s['pair']) . '</b> <span class="dir ' . ($buy ? 'buy' : 'sell') . '">' . e($s['direction']) . '</span>';
    if ($s['premium']) $h .= ' <span class="pill pill-gold">Premium</span>';
    $h .= '<small>' . e(strtoupper((string) $s['timeframe'])) . ' · ' . ($running ? 'Opened ' . e(ago($s['opened_at'])) : 'Closed ' . e(ago($s['closed_at']))) . '</small></div>';
    if ($s['locked']) {
        $h .= '<div class="sig-pips"><span class="lockico">' . icon('lock') . '</span></div>';
    } else {
        $h .= '<div class="sig-pips ' . pip_class($pips) . '" data-live="' . (int) $s['id'] . '"><b>' . e(fmt_pips($pips)) . '</b><small>pips</small></div>';
    }
    $h .= '</div><div class="sig-levels">';
    foreach (['entry' => 'Entry', 'stop_loss' => 'Stop loss', 'tp1' => 'TP1', 'tp2' => 'TP2'] as $k => $lab) {
        $v = $s[$k];
        $h .= '<div><small>' . $lab . '</small><b class="' . ($k === 'stop_loss' ? 'down' : ($k === 'entry' ? '' : 'up')) . '">' . ($v === null ? '<span class="blur">0.00000</span>' : e(fmt_price($s['pair'], $v))) . '</b></div>';
    }
    $h .= '</div><div class="sig-foot"><span class="status st-' . e($cls) . '">' . e($label) . '</span><span class="muted">⚡ ' . (int) $s['confidence'] . '% strength</span></div>';
    if ($s['locked']) $h .= '<div class="sig-lock">' . icon('lock') . ' Premium signal · tap to unlock</div>';
    return $h . '</a>';
}
