<?php
declare(strict_types=1);

/* FX web client. This directory is additive; the mobile API remains unchanged. */
const BRAND = 'FX Signals Live';
const API_BASE = '/api';
const DOMAIN = 'https://forexsignal.bulksend.top';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('fxsignal_web');
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; script-src \'self\'; img-src \'self\' data:; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'');

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function api(string $path, string $method = 'GET', ?array $data = null): array {
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    $url = DOMAIN . API_BASE . $route . ($query !== '' ? '?' . $query : '');
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if (!empty($_SESSION['token'])) $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    if ($data !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}
function go(string $page): never { header('Location: /web/?page=' . rawurlencode($page), true, 303); exit; }
$page = (string)($_GET['page'] ?? 'home');
$allowed = ['home','login','register','dashboard','history','account','about'];
if (!in_array($page, $allowed, true)) { http_response_code(404); $page = 'missing'; }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid form token'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        api('/auth/logout', 'POST', []);
        $_SESSION = []; session_regenerate_id(true); go('home');
    }
    if (in_array($action, ['login','register'], true)) {
        $payload = ['email' => trim((string)($_POST['email'] ?? '')), 'password' => (string)($_POST['password'] ?? '')];
        if ($action === 'register') $payload['name'] = trim((string)($_POST['name'] ?? ''));
        [$status, $result] = api('/auth/' . $action, 'POST', $payload);
        if ($status >= 200 && $status < 300 && isset($result['token'])) {
            session_regenerate_id(true);
            $_SESSION['token'] = $result['token'];
            $_SESSION['user'] = $result['user'] ?? [];
            go('dashboard');
        }
        $error = (string)($result['error'] ?? 'Could not complete your request. Please try again.');
    }
}
if (in_array($page, ['dashboard','history','account'], true)) {
    if (empty($_SESSION['token'])) go('login');
    [$status, $identity] = api('/me');
    if ($status !== 200) { $_SESSION = []; go('login'); }
    $_SESSION['user'] = $identity['user'] ?? [];
}
if (in_array($page, ['login','register'], true) && !empty($_SESSION['token'])) go('dashboard');
$user = $_SESSION['user'] ?? [];
$signals = [];
if ($page === 'dashboard' || $page === 'history') {
    [$status, $response] = api('/signals' . ($page === 'history' ? '?status=closed' : '?status=running'));
    $signals = $response['items'] ?? [];
}
$titles = ['home'=>'FX Signals Live | Forex trading signals and tracked results','login'=>'Sign in | FX Signals Live','register'=>'Create account | FX Signals Live','dashboard'=>'Your signals | FX Signals Live','history'=>'Signal history | FX Signals Live','account'=>'Account | FX Signals Live','about'=>'How FX works | FX Signals Live','missing'=>'Page not found | FX Signals Live'];
$description = 'FX Signals Live provides forex trade setups with entry, stop loss, targets and tracked outcomes. Trading involves risk; no returns are guaranteed.';
$public = in_array($page, ['home','about'], true);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=e($titles[$page])?></title><meta name="description" content="<?=e($description)?>">
<meta name="robots" content="<?=$public ? 'index,follow' : 'noindex,nofollow'?>"><link rel="canonical" href="<?=e(DOMAIN . '/web/' . ($page === 'home' ? '' : '?page=' . $page))?>">
<meta property="og:title" content="<?=e($titles[$page])?>"><meta property="og:description" content="<?=e($description)?>"><meta property="og:type" content="website"><meta property="og:url" content="<?=e(DOMAIN . '/web/')?>">
<?php if ($page === 'home'): ?><script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>BRAND,'url'=>DOMAIN . '/web/','description'=>$description], JSON_UNESCAPED_SLASHES)?></script><?php endif; ?>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#edf4fc;background:#091321;font-synthesis:none}*{box-sizing:border-box}body{margin:0}a{color:inherit;text-decoration:none}button,input{font:inherit}button{cursor:pointer}.wrap{width:min(1120px,calc(100% - 36px));margin:auto}.top{position:sticky;top:0;z-index:5;background:#091321ed;border-bottom:1px solid #23364b;backdrop-filter:blur(12px)}.nav{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:70px}.logo{font-size:1.2rem;font-weight:850;letter-spacing:-.03em}.logo span{color:#64e1c4}.links{display:flex;align-items:center;gap:22px}.links a{color:#adbed0;font-size:.92rem}.links a:hover{color:white}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 20px;border:1px solid #39536a;border-radius:12px;background:transparent;color:white;font-weight:700}.btn.primary{color:#061c24;background:#65e1c4;border-color:#65e1c4}.btn:hover{filter:brightness(1.1)}.hero{display:grid;grid-template-columns:1.15fr .85fr;gap:44px;align-items:center;padding:100px 0 85px}.eyebrow{text-transform:uppercase;color:#65e1c4;font-size:.74rem;font-weight:800;letter-spacing:.19em}h1{font-size:clamp(2.65rem,6vw,5.4rem);line-height:1.06;letter-spacing:-.06em;margin:18px 0}h2{font-size:clamp(1.7rem,3vw,2.5rem);letter-spacing:-.04em;margin:0 0 12px}.lead{font-size:1.08rem;line-height:1.75;color:#b0c1d3;max-width:630px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:30px}.panel,.card{background:#102238;border:1px solid #2b4056;border-radius:22px;box-shadow:0 18px 60px #0002}.panel{padding:30px}.signal-preview{display:grid;gap:14px}.pill{display:inline-flex;padding:7px 11px;border-radius:99px;background:#183e40;color:#7fe8d0;font-size:.76rem;font-weight:800}.fake-row{display:flex;justify-content:space-between;gap:12px;padding:15px 0;border-bottom:1px solid #2a4156;color:#c2d1df}.fake-row strong{color:white}.section{padding:65px 0}.muted{color:#aec0d2;line-height:1.65}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:27px}.card{padding:25px}.card h3{margin:8px 0}.card p{color:#aebfd0;line-height:1.6}.auth{min-height:70vh;display:grid;place-items:center;padding:48px 0}.auth .panel{width:min(460px,100%)}label{display:block;font-size:.87rem;font-weight:700;margin:18px 0 8px}input{width:100%;min-height:48px;border:1px solid #3b5268;background:#0b1b2e;border-radius:11px;color:white;padding:10px 14px}input:focus{outline:2px solid #65e1c4;outline-offset:2px}.full{width:100%;margin-top:24px}.error{background:#552a30;color:#ffe4e5;padding:13px;border-radius:10px}.dash{padding:52px 0 90px}.dash-head{display:flex;justify-content:space-between;align-items:end;gap:15px;margin-bottom:25px}.signal-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.signal{padding:24px}.levels{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:20px}.levels div{background:#0a1b2c;padding:12px;border-radius:9px}.levels small{display:block;color:#91aabe}.empty{padding:42px;text-align:center;color:#afc0d2}.footer{border-top:1px solid #23364b;padding:30px 0;color:#a7b9cb;font-size:.85rem}.footer .wrap{display:flex;justify-content:space-between;gap:15px}.footlinks{display:flex;gap:15px}form.inline{display:inline}button.text{border:0;background:transparent;color:#adbed0}.risk{font-size:.82rem;color:#90a5b8;margin-top:16px}@media(max-width:760px){.hero{grid-template-columns:1fr;padding:58px 0}.grid,.signal-grid{grid-template-columns:1fr}.links{gap:12px}.links .desktop{display:none}.panel{padding:23px}.dash-head{display:block}.levels{grid-template-columns:repeat(2,1fr)}.footer .wrap{display:block}.footlinks{margin-top:12px}}
</style></head><body>
<header class="top"><nav class="wrap nav" aria-label="Main navigation"><a class="logo" href="/web/">Day<span>FX</span> Signal</a><div class="links"><a class="desktop" href="/web/?page=about">How it works</a><?php if ($user): ?><a href="/web/?page=dashboard">Dashboard</a><a class="desktop" href="/web/?page=history">History</a><a href="/web/?page=account">Account</a><?php else: ?><a href="/web/?page=login">Sign in</a><a class="btn primary" href="/web/?page=register">Get started</a><?php endif; ?></div></nav></header>
<main><?php if ($page === 'home'): ?><div class="wrap hero"><div><div class="eyebrow">Clarity for every trading day</div><h1>Trade setups, without the guesswork.</h1><p class="lead">See FX forex signals with a clear entry, stop loss, targets and tracked outcome. Follow your own risk plan and keep every decision in your hands.</p><div class="actions"><a class="btn primary" href="/web/?page=register">Create free account</a><a class="btn" href="/web/?page=about">Explore the platform</a></div><p class="risk">Signals are informational. Trading can result in loss of capital.</p></div><div class="panel signal-preview" aria-label="Signal format preview"><span class="pill">HOW A SIGNAL IS PRESENTED</span><h2>Everything in one view</h2><div class="fake-row"><span>Market</span><strong>Forex pairs</strong></div><div class="fake-row"><span>Direction</span><strong>Buy or sell</strong></div><div class="fake-row"><span>Plan</span><strong>Entry · Stop · Targets</strong></div><div class="fake-row"><span>Outcome</span><strong>Tracked history</strong></div><p class="muted">Sign in to see current signals from the existing FX account you use in the app.</p></div></div><section class="section wrap"><span class="eyebrow">Built for practical decisions</span><h2>A simple workflow from setup to result</h2><div class="grid"><div class="card"><h3>01 · Read the setup</h3><p>Review direction, score and the reason for a signal before considering it.</p></div><div class="card"><h3>02 · Check the levels</h3><p>See the entry, protective stop and take profit levels together.</p></div><div class="card"><h3>03 · Follow the outcome</h3><p>Return to the signal history to see how published setups finished.</p></div></div></section>
<?php elseif ($page === 'about'): ?><section class="wrap section"><span class="eyebrow">About the platform</span><h1>Signals with context and a recorded outcome.</h1><p class="lead">FX evaluates forex setups and publishes a plan only when its rules find a suitable opportunity. A signal is a trade idea, not an instruction to place a trade. Market prices and broker execution may differ. Always decide your own position size and risk.</p><div class="grid"><div class="card"><h3>Clear levels</h3><p>Every published setup includes an entry, stop loss and target levels.</p></div><div class="card"><h3>Recorded results</h3><p>Completed and expired signals remain visible in your history.</p></div><div class="card"><h3>One account</h3><p>Use the same email and password you use in the FX mobile app.</p></div></div></section>
<?php elseif ($page === 'login' || $page === 'register'): ?><section class="auth wrap"><div class="panel"><span class="eyebrow">Your FX account</span><h2><?=$page === 'login' ? 'Welcome back' : 'Create your account'?></h2><p class="muted"><?=$page === 'login' ? 'Sign in with your existing app account.' : 'Your account works on the website and mobile app.'?></p><?php if ($error): ?><p class="error" role="alert"><?=e($error)?></p><?php endif; ?><form method="post" action="/web/?page=<?=e($page)?>"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=e($page)?>"><?php if ($page === 'register'): ?><label for="name">Full name</label><input id="name" name="name" autocomplete="name" required minlength="2" value="<?=e($_POST['name'] ?? '')?>"><?php endif; ?><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="<?=e($_POST['email'] ?? '')?>"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="<?=$page === 'login' ? 'current-password' : 'new-password'?>" required minlength="8"><button class="btn primary full" type="submit"><?=$page === 'login' ? 'Sign in' : 'Create account'?></button></form><p class="muted"><?=$page === 'login' ? 'New to FX? <a href="/web/?page=register">Create an account</a>' : 'Already registered? <a href="/web/?page=login">Sign in</a>'?></p></div></section>
<?php elseif ($page === 'dashboard' || $page === 'history'): ?><section class="wrap dash"><div class="dash-head"><div><span class="eyebrow">Your workspace</span><h1><?=$page === 'history' ? 'Signal history' : 'Welcome, ' . e(explode(' ', (string)($user['name'] ?? 'trader'))[0])?></h1><p class="muted"><?=$page === 'history' ? 'Review closed signals and their reported results.' : 'Current signals from your FX account.'?></p></div><div class="actions"><a class="btn<?=$page === 'dashboard' ? ' primary' : ''?>" href="/web/?page=dashboard">Active</a><a class="btn<?=$page === 'history' ? ' primary' : ''?>" href="/web/?page=history">History</a></div></div><?php if (!$signals): ?><div class="panel empty">No <?=$page === 'history' ? 'closed' : 'active'?> signals to show right now. Check back later.</div><?php else: ?><div class="signal-grid"><?php foreach ($signals as $s): ?><article class="card signal"><span class="pill"><?=e($s['status'] ?? 'SIGNAL')?></span><h2><?=e($s['pair'] ?? 'Market')?> · <?=e($s['direction'] ?? '')?></h2><p class="muted"><?=e($s['timeframe'] ?? '')?> · <?=e($s['result'] ?? '')?> · Score <?=e($s['confidence'] ?? '—')?></p><div class="levels"><?php foreach (['Entry'=>'entry','Stop'=>'stop_loss','Target 1'=>'tp1','Target 2'=>'tp2'] as $label=>$key): ?><div><small><?=e($label)?></small><strong><?=e($s[$key] ?? '—')?></strong></div><?php endforeach; ?></div><?php if (isset($s['pips'])): ?><p class="muted">Result: <?=e($s['pips'])?> pips</p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<?php elseif ($page === 'account'): ?><section class="wrap dash"><span class="eyebrow">Your account</span><h1>Account details</h1><div class="panel"><p><strong>Name</strong><br><?=e($user['name'] ?? '')?></p><p><strong>Email</strong><br><?=e($user['email'] ?? '')?></p><form method="post" action="/web/?page=account"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Sign out</button></form><p class="muted"><a href="/api/account-deletion.html">Privacy policy</a> · <a href="/delete-account.html">Delete account information</a></p></div></section>
<?php else: ?><section class="wrap section"><h1>Page not found</h1><a class="btn" href="/web/">Back to home</a></section><?php endif; ?></main>
<footer class="footer"><div class="wrap"><span>© <?=date('Y')?> FX Signals Live · Trading involves risk.</span><div class="footlinks"><a href="/web/?page=about">About</a><a href="/api/account-deletion.html">Privacy</a><a href="/delete-account.html">Account deletion</a></div></div></footer></body></html>
