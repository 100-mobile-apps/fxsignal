<?php
declare(strict_types=1);
/*
 * FX Signals admin: /admin
 * Sign in with an app account whose email is listed in config 'admin_emails' (config.premium.php).
 * Settings saved here go to the `settings` table and override the config files.
 */
require dirname(__DIR__) . '/api/src/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

session_name('fxadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const ADMIN_IDLE = 1800;
const KNOWN_PAIRS = ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'XAU/USD', 'BTC/USD', 'ETH/USD'];
const CAMPAIGNS = [
    'welcome' => 'Welcome series (new accounts)',
    'upgrade' => 'Upgrade offers based on activity',
    'winback' => 'Win-back for inactive users (7/14/30 days)',
    'lifecycle' => 'Trial ending, renewal, payment failed, expired',
];

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function admin_emails(): array { return array_map('strtolower', (array) config('admin_emails', [])); }
function setting_save(string $k, $v): void
{
    Db::exec('REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, ?)', [$k, json_encode($v), now()]);
}
function lines(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower($s)) as $e) {
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_values(array_unique($out));
}

$error = $notice = null;
$admin = null;
if (!empty($_SESSION['admin']) && ($_SESSION['seen'] ?? 0) > time() - ADMIN_IDLE && in_array($_SESSION['admin'], admin_emails(), true)) {
    $admin = $_SESSION['admin'];
    $_SESSION['seen'] = time();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('Session expired. Please try again.');
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            Http::rateLimit('admin:' . Http::ip(), 6, 900);
            $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
            if (!admin_emails() || !in_array($email, admin_emails(), true) || !$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                error_log("fxadmin: failed login for $email from " . Http::ip());
                throw new RuntimeException('Wrong email or password.');
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = $email; $_SESSION['seen'] = time(); unset($_SESSION['csrf']);
            header('Location: ./'); exit;
        }
        if ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
        if (!$admin) throw new RuntimeException('Please sign in.');

        if ($action === 'save') {
            $pairs = array_values(array_intersect(KNOWN_PAIRS, (array) ($_POST['pairs'] ?? [])));
            if (!$pairs) throw new RuntimeException('Pick at least one market.');
            $strength = max(50, min(95, (int) ($_POST['free_max_strength'] ?? 70)));
            $minConf = max(55, min(90, (int) ($_POST['min_confidence'] ?? 65)));
            $prices = [];
            foreach (['monthly', 'yearly', 'launch_monthly', 'launch_yearly', 'launch_label'] as $k) {
                $prices[$k] = mb_substr(trim((string) ($_POST["price_$k"] ?? '')), 0, 60);
            }
            $end = trim((string) ($_POST['price_launch_ends'] ?? ''));
            $prices['launch_ends'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : '';
            $campaigns = ['max_per_week' => max(0, min(7, (int) ($_POST['max_per_week'] ?? 2)))];
            foreach (CAMPAIGNS as $k => $_) $campaigns[$k] = !empty($_POST["campaign_$k"]);

            setting_save('pairs', $pairs);
            setting_save('premium_markets', array_values(array_intersect($pairs, (array) ($_POST['premium_markets'] ?? []))));
            setting_save('free_max_strength', $strength);
            setting_save('min_confidence', $minConf);
            setting_save('premium_test_emails', lines((string) ($_POST['premium_test_emails'] ?? '')));
            setting_save('premium_emails', lines((string) ($_POST['premium_emails'] ?? '')));
            setting_save('price_display', $prices);
            setting_save('email_campaigns', $campaigns);
            $want = !empty($_POST['premium_enforced']);
            if ($want !== (bool) config('premium_enforced', false)) {
                if ($want && ($_POST['confirm_launch'] ?? '') !== 'LAUNCH') throw new RuntimeException('Other settings saved. To switch Premium on for everyone, type LAUNCH in the confirmation box.');
                setting_save('premium_enforced', $want);
            }
            error_log("fxadmin: settings saved by $admin");
            header('Location: ./?saved=1'); exit;
        }
    }
} catch (ApiError $e) {
    $error = $e->getMessage();
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
if (isset($_GET['saved'])) $notice = 'Saved. Changes apply immediately.';

function stats(): array
{
    $now = now();
    $one = fn(string $sql, array $p = []) => (int) (Db::one($sql, $p)['n'] ?? 0);
    $since7 = now(time() - 7 * 86400);
    return [
        'Users' => $one('SELECT COUNT(*) AS n FROM users'),
        'New users (7 days)' => $one('SELECT COUNT(*) AS n FROM users WHERE created_at >= ?', [$since7]),
        'Active in 7 days' => $one('SELECT COUNT(*) AS n FROM users WHERE last_login_at >= ?', [$since7]),
        'Premium now' => $one('SELECT COUNT(*) AS n FROM users WHERE premium_until > ?', [$now]),
        'Monthly subscribers' => $one("SELECT COUNT(*) AS n FROM users WHERE premium_until > ? AND premium_plan LIKE '%month%'", [$now]),
        'Yearly subscribers' => $one("SELECT COUNT(*) AS n FROM users WHERE premium_until > ? AND premium_plan LIKE '%year%'", [$now]),
        'Auto-renew off' => $one('SELECT COUNT(*) AS n FROM subscriptions WHERE expires_at > ? AND auto_renewing = 0', [$now]),
        'Signals (7 days)' => $one('SELECT COUNT(*) AS n FROM signals WHERE opened_at >= ?', [$since7]),
    ];
}

$enforced = (bool) config('premium_enforced', false);
$pairs = (array) config('pairs', []);
$premiumMarkets = Billing::premiumMarkets();
$prices = (array) config('price_display', []) + ['monthly' => '$4.99', 'yearly' => '$35.99', 'launch_monthly' => '$1.50', 'launch_yearly' => '$12', 'launch_label' => 'Launch offer', 'launch_ends' => ''];
$campaigns = (array) config('email_campaigns', []) + ['max_per_week' => 2, 'welcome' => true, 'upgrade' => true, 'winback' => true, 'lifecycle' => true];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>FX Signals admin</title>
<style>
:root{--bg:#0b0f17;--card:#141a26;--line:#243047;--text:#e6ebf5;--mut:#8b97ad;--gold:#f5c451;--buy:#22c55e;--sell:#ef4444}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:860px;margin:0 auto;padding:24px 16px 80px}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:0 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin:16px 0}
label{display:block;margin:10px 0 4px;color:var(--mut);font-size:13px}input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:#0e1420;color:var(--text);font:inherit}
textarea{min-height:70px}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line);border-radius:999px;color:var(--text);font-size:14px;margin:0}
button{background:linear-gradient(135deg,#f9d77e,#e0a526);color:#1a1204;border:0;border-radius:10px;padding:11px 18px;font-weight:700;font:inherit;font-weight:700;cursor:pointer}
.ghost{background:none;color:var(--mut);border:1px solid var(--line)}.msg{padding:10px 14px;border-radius:10px;margin:12px 0}.err{background:#3a1515;color:#fecaca}.ok{background:#12301f;color:#bbf7d0}
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.stat{background:#0e1420;border-radius:10px;padding:12px}.stat b{display:block;font-size:22px}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700}.on{background:#12301f;color:var(--buy)}.off{background:#2b2312;color:var(--gold)}
.hint{color:var(--mut);font-size:13px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px}
@media(max-width:600px){.row{grid-template-columns:1fr}}
</style></head><body><main>
<?php if (!$admin): ?>
  <div class="card" style="max-width:380px;margin:60px auto">
    <h1>FX Signals admin</h1><p class="hint">Sign in with your app account.</p>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif ?>
    <?php if (!admin_emails()): ?><div class="msg err">Admin is switched off. Add your email to 'admin_emails' in config.premium.php.</div><?php endif ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="login">
      <label>Email</label><input type="email" name="email" required autocomplete="username">
      <label>Password</label><input type="password" name="password" required autocomplete="current-password">
      <p><button type="submit">Sign in</button></p>
    </form>
  </div>
<?php else: ?>
  <div class="top">
    <div><h1>FX Signals admin</h1><span class="hint"><?= h($admin) ?></span></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="ghost" name="action" value="logout">Sign out</button></form>
  </div>
  <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif ?>
  <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif ?>

  <div class="card"><h2>Overview</h2><div class="stats">
    <?php foreach (stats() as $k => $v): ?><div class="stat"><span class="hint"><?= h($k) ?></span><b><?= number_format($v) ?></b></div><?php endforeach ?>
  </div></div>

  <form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="save">

  <div class="card"><h2>Premium lock <span class="pill <?= $enforced ? 'on' : 'off' ?>"><?= $enforced ? 'LIVE for everyone' : 'Off (test accounts only)' ?></span></h2>
    <label class="chip" style="width:max-content"><input type="checkbox" name="premium_enforced" value="1" <?= $enforced ? 'checked' : '' ?>> Lock Premium signals for everyone</label>
    <?php if (!$enforced): ?><label>To switch it on, also type LAUNCH here</label><input type="text" name="confirm_launch" autocomplete="off" placeholder="LAUNCH"><?php endif ?>
    <p class="hint">When on, users on old app versions are asked to update, and free users see locked levels on Premium signals.</p>
    <label>Test accounts (see the lock before launch), one email per line</label>
    <textarea name="premium_test_emails"><?= h(implode("\n", (array) config('premium_test_emails', []))) ?></textarea>
    <label>Complimentary Premium (free Premium for these emails)</label>
    <textarea name="premium_emails"><?= h(implode("\n", (array) config('premium_emails', []))) ?></textarea>
  </div>

  <div class="card"><h2>Markets and what is free</h2>
    <label>Markets the engine scans</label>
    <div class="chips"><?php foreach (KNOWN_PAIRS as $p): ?><label class="chip"><input type="checkbox" name="pairs[]" value="<?= h($p) ?>" <?= in_array($p, $pairs, true) ? 'checked' : '' ?>> <?= h($p) ?></label><?php endforeach ?></div>
    <p class="hint">Each market uses about 72 Twelve Data credits a day (free plan: 800/day, so 10 markets at most).</p>
    <label>Premium-only markets (locked at any strength)</label>
    <div class="chips"><?php foreach (KNOWN_PAIRS as $p): ?><label class="chip"><input type="checkbox" name="premium_markets[]" value="<?= h($p) ?>" <?= in_array($p, $premiumMarkets, true) ? 'checked' : '' ?>> <?= h($p) ?></label><?php endforeach ?></div>
    <div class="row">
      <div><label>Other markets: free up to this strength (%)</label><input type="number" name="free_max_strength" min="50" max="95" value="<?= (int) Billing::freeMaxStrength() ?>"></div>
      <div><label>Minimum strength to publish a signal (%)</label><input type="number" name="min_confidence" min="55" max="90" value="<?= (int) config('min_confidence', 65) ?>"></div>
    </div>
  </div>

  <div class="card"><h2>Prices shown in the app</h2>
    <p class="hint">Google Play charges the price set in Play Console. These are labels only; keep them in step with Play.</p>
    <div class="row">
      <div><label>Monthly (regular)</label><input type="text" name="price_monthly" value="<?= h($prices['monthly']) ?>"></div>
      <div><label>Yearly (regular)</label><input type="text" name="price_yearly" value="<?= h($prices['yearly']) ?>"></div>
      <div><label>Monthly (launch offer)</label><input type="text" name="price_launch_monthly" value="<?= h($prices['launch_monthly']) ?>"></div>
      <div><label>Yearly (launch offer)</label><input type="text" name="price_launch_yearly" value="<?= h($prices['launch_yearly']) ?>"></div>
      <div><label>Offer badge text</label><input type="text" name="price_launch_label" value="<?= h($prices['launch_label']) ?>"></div>
      <div><label>Offer ends (shown as a countdown)</label><input type="date" name="price_launch_ends" value="<?= h($prices['launch_ends']) ?>"></div>
    </div>
  </div>

  <div class="card"><h2>Automated emails</h2>
    <?php foreach (CAMPAIGNS as $k => $label): ?><label class="chip" style="margin:6px 0;width:max-content"><input type="checkbox" name="campaign_<?= h($k) ?>" value="1" <?= !empty($campaigns[$k]) ? 'checked' : '' ?>> <?= h($label) ?></label><?php endforeach ?>
    <label>Most marketing emails per user per week</label><input type="number" name="max_per_week" min="0" max="7" value="<?= (int) $campaigns['max_per_week'] ?>" style="max-width:120px">
    <p class="hint">Signal alerts and billing notices don't count towards this limit. Every marketing email has an unsubscribe link.</p>
  </div>

  <p><button type="submit">Save changes</button></p>
  </form>
<?php endif ?>
</main></body></html>
