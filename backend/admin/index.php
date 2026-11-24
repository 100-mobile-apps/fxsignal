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
            setting_save('free_unlock_days', max(0, min(60, (int) ($_POST['free_unlock_days'] ?? 14))));
            setting_save('premium_test_emails', lines((string) ($_POST['premium_test_emails'] ?? '')));
            setting_save('premium_emails', lines((string) ($_POST['premium_emails'] ?? '')));
            setting_save('price_display', $prices);
            setting_save('email_campaigns', $campaigns);
            $appId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['deriv_app_id'] ?? ''));
            setting_save('bot', ['paper' => !empty($_POST['bot_paper']), 'copy' => !empty($_POST['bot_copy']),
                'deriv' => !empty($_POST['bot_deriv']), 'deriv_app_id' => mb_substr($appId, 0, 40)]);
            $want = !empty($_POST['premium_enforced']);
            if ($want !== (bool) config('premium_enforced', false)) {
                if ($want && ($_POST['confirm_launch'] ?? '') !== 'LAUNCH') throw new RuntimeException('Other settings saved. To switch Premium on for everyone, type LAUNCH in the confirmation box.');
                setting_save('premium_enforced', $want);
            }
            error_log("fxadmin: settings saved by $admin");
            header('Location: ./?tab=settings&saved=1'); exit;
        }
        $back = function (string $tab, string $msg, array $extra = []): never {
            $_SESSION['notice'] = $msg;
            header('Location: ./?' . http_build_query(['tab' => $tab] + array_filter($extra, fn($v) => $v !== ''))); exit;
        };
        $uid = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'save_web') {
            $cur = Payments::webPricing();
            $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['currency'] ?? 'NGN')));
            if (!in_array($currency, ['NGN', 'USD', 'GHS', 'KES', 'ZAR'], true)) throw new RuntimeException('Paystack supports NGN, USD, GHS, KES and ZAR.');
            $web = [
                'currency' => $currency,
                'monthly_amount' => max(0, round((float) ($_POST['monthly_amount'] ?? 0), 2)),
                'yearly_amount' => max(0, round((float) ($_POST['yearly_amount'] ?? 0), 2)),
                'monthly_plan' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['monthly_plan'] ?? '')),
                'yearly_plan' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['yearly_plan'] ?? '')),
                'usd_rate' => max(0.0001, (float) ($_POST['usd_rate'] ?? $cur['usd_rate'])),
            ];
            if (($web['monthly_amount'] != $cur['monthly_amount'] || $web['currency'] !== $cur['currency']) && $web['monthly_plan'] === $cur['monthly_plan']) $web['monthly_plan'] = '';
            if (($web['yearly_amount'] != $cur['yearly_amount'] || $web['currency'] !== $cur['currency']) && $web['yearly_plan'] === $cur['yearly_plan']) $web['yearly_plan'] = '';
            setting_save('web_pricing', $web);
            $aff = [
                'enabled' => !empty($_POST['aff_enabled']),
                'rate' => max(0, min(90, (float) ($_POST['aff_rate'] ?? 30))),
                'hold_days' => max(0, min(90, (int) ($_POST['aff_hold_days'] ?? 14))),
                'min_payout' => max(1, min(10000, (float) ($_POST['aff_min_payout'] ?? 10))),
                'cookie_days' => max(1, min(365, (int) ($_POST['aff_cookie_days'] ?? 60))),
                'auto_approve' => !empty($_POST['aff_auto_approve']),
            ];
            setting_save('affiliate', $aff);
            $bName = mb_substr(trim((string) ($_POST['broker_name'] ?? '')), 0, 60);
            $bUrl = trim((string) ($_POST['broker_url'] ?? ''));
            setting_save('broker_default', $bUrl === '' ? [] : ['name' => $bName ?: 'our partner broker', 'url' => Affiliates::validBrokerUrl($bUrl)]);
            $base = [];
            foreach (['monthly', 'yearly', 'monthly_intro', 'yearly_intro'] as $k) $base[$k] = max(0, round((float) ($_POST["play_$k"] ?? 0), 2));
            setting_save('play_commission_base', $base);
            error_log("fxadmin: web/affiliate settings saved by $admin");
            $back('settings', 'Website, Paystack and affiliate settings saved.');
        }
        if ($action === 'create_plans') {
            $codes = Paystack::createPlans();
            setting_save('web_pricing', array_merge(Payments::webPricing(), $codes));
            $back('settings', 'Paystack plans created: ' . implode(', ', $codes));
        }
        if ($action === 'grant') {
            $days = (int) ($_POST['days'] ?? 0);
            if (!$uid || $days < 1) throw new RuntimeException('Enter the number of days.');
            Payments::grant($uid, $days, $admin);
            error_log("fxadmin: $admin granted $days days Premium to user $uid");
            $back('users', "Premium granted for $days days.", ['q' => (string) ($_POST['q'] ?? '')]);
        }
        if ($action === 'block' || $action === 'unblock') {
            if (!$uid) throw new RuntimeException('Unknown user.');
            $target = Db::one('SELECT email FROM users WHERE id = ?', [$uid]);
            if ($target && in_array(strtolower($target['email']), admin_emails(), true)) throw new RuntimeException('Admins cannot be suspended here.');
            Db::exec('UPDATE users SET blocked_at = ?, token_version = token_version + ? WHERE id = ?', [$action === 'block' ? now() : null, $action === 'block' ? 1 : 0, $uid]);
            error_log("fxadmin: $admin {$action}ed user $uid");
            $back('users', $action === 'block' ? 'User suspended and logged out everywhere.' : 'User restored.', ['q' => (string) ($_POST['q'] ?? '')]);
        }
        if ($action === 'refund') {
            $pid = (int) ($_POST['payment_id'] ?? 0);
            if (($_POST['confirm'] ?? '') !== 'yes') throw new RuntimeException('Tick the box to confirm the refund.');
            Payments::refund($pid);
            error_log("fxadmin: $admin marked payment $pid refunded");
            $back('payments', 'Payment marked as refunded; Premium time and the affiliate commission were removed. Send the money back in the Paystack dashboard (or Play Console) if you have not already.');
        }
        if ($action === 'aff_status') {
            $st = (string) ($_POST['status'] ?? '');
            if (!in_array($st, Affiliates::STATUSES, true)) throw new RuntimeException('Unknown status.');
            Db::exec('UPDATE affiliates SET status = ?, updated_at = ? WHERE user_id = ?', [$st, now(), $uid]);
            $back('affiliates', 'Affiliate is now ' . $st . '.');
        }
        if ($action === 'aff_rate') {
            $raw = trim((string) ($_POST['rate'] ?? ''));
            $rate = $raw === '' ? null : max(0, min(90, (float) $raw));
            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
            Db::exec('UPDATE affiliates SET rate = ?, note = ?, updated_at = ? WHERE user_id = ?', [$rate, $note ?: null, now(), $uid]);
            $back('affiliates', 'Saved.');
        }
        if ($action === 'broker') {
            $st = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
            Db::exec('UPDATE affiliates SET broker_status = ?, updated_at = ? WHERE user_id = ? AND broker_url IS NOT NULL', [$st, now(), $uid]);
            $back('affiliates', 'Broker link ' . $st . '.');
        }
        if ($action === 'comm_reverse') {
            $n = Db::exec("UPDATE commissions SET status = 'reversed' WHERE id = ? AND status IN ('pending', 'approved') AND payout_id IS NULL", [(int) ($_POST['id'] ?? 0)]);
            $back('commissions', $n ? 'Commission reversed.' : 'Only unpaid commissions that are not in a payout can be reversed.');
        }
        if ($action === 'payout') {
            $paid = ($_POST['decision'] ?? '') === 'paid';
            $ref = trim((string) ($_POST['reference'] ?? ''));
            if ($paid && $ref === '') throw new RuntimeException('Enter the transfer reference (or transaction hash) before marking it paid.');
            Affiliates::processPayout((int) ($_POST['id'] ?? 0), $paid, $ref, trim((string) ($_POST['note'] ?? '')));
            error_log("fxadmin: $admin " . ($paid ? 'paid' : 'rejected') . ' payout ' . (int) ($_POST['id'] ?? 0));
            $back('payouts', $paid ? 'Payout marked as paid; the affiliate was emailed.' : 'Payout rejected; the commissions are available again.');
        }
    }
} catch (ApiError $e) {
    $error = $e->getMessage();
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
if (isset($_GET['saved'])) $notice = 'Saved. Changes apply immediately.';
if (!empty($_SESSION['notice'])) { $notice = (string) $_SESSION['notice']; unset($_SESSION['notice']); }

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
$bot = (array) config('bot', []) + ['paper' => true, 'copy' => true, 'deriv' => true, 'deriv_app_id' => ''];
$campaigns = (array) config('email_campaigns', []) + ['max_per_week' => 2, 'welcome' => true, 'upgrade' => true, 'winback' => true, 'lifecycle' => true];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>FX Signals admin</title>
<style>
:root{--bg:#0b0f17;--card:#141a26;--line:#243047;--text:#e6ebf5;--mut:#8b97ad;--gold:#f5c451;--buy:#22c55e;--sell:#ef4444}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:1180px;margin:0 auto;padding:24px 16px 80px}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:0 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin:16px 0}
label{display:block;margin:10px 0 4px;color:var(--mut);font-size:13px}input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:#0e1420;color:var(--text);font:inherit}
textarea{min-height:70px}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line);border-radius:999px;color:var(--text);font-size:14px;margin:0}
button{background:linear-gradient(135deg,#f9d77e,#e0a526);color:#1a1204;border:0;border-radius:10px;padding:11px 18px;font-weight:700;font:inherit;font-weight:700;cursor:pointer}
.ghost{background:none;color:var(--mut);border:1px solid var(--line)}.msg{padding:10px 14px;border-radius:10px;margin:12px 0}.err{background:#3a1515;color:#fecaca}.ok{background:#12301f;color:#bbf7d0}
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.stat{background:#0e1420;border-radius:10px;padding:12px}.stat b{display:block;font-size:22px}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700}.on{background:#12301f;color:var(--buy)}.off{background:#2b2312;color:var(--gold)}
.hint{color:var(--mut);font-size:13px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px}
.tabs{display:flex;flex-wrap:wrap;gap:6px;margin:18px 0 4px;border-bottom:1px solid var(--line);padding-bottom:10px}.tabs a{color:var(--mut);text-decoration:none;padding:8px 14px;border-radius:10px}.tabs a.cur{background:var(--card);color:var(--gold);font-weight:700}
.badge{background:var(--sell);color:#fff;border-radius:999px;padding:0 7px;font-size:12px;margin-left:4px}
table{width:100%;border-collapse:collapse;font-size:14px}th{text-align:left;color:var(--mut);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em;padding:8px;border-bottom:1px solid var(--line)}td{padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}
.scroll{overflow-x:auto}.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}.pre{white-space:pre-wrap;word-break:break-all}code{font-family:ui-monospace,Menlo,monospace;color:var(--gold)}
.search{display:flex;gap:8px;margin-bottom:12px}.search input{flex:1}.inline{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:4px 0}.stack{display:grid;gap:6px;min-width:220px}.acts{min-width:230px}
button.sm{padding:6px 10px;font-size:13px}input.sm{width:80px!important;padding:6px 8px!important}input.md{width:140px!important;padding:6px 8px!important}.chip.sm{padding:3px 8px;font-size:12px}a.chip{text-decoration:none}a.chip.cur{border-color:var(--gold);color:var(--gold)}
.pager{display:flex;gap:16px;margin-top:12px}.pager a{color:var(--gold)}h3{font-size:14px;margin:18px 0 6px}
.row3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.row4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:800px){.row3,.row4{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.row,.row3,.row4{grid-template-columns:1fr}}
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
<?php else:
  $tab = (string) ($_GET['tab'] ?? 'overview');
  $tabs = ['overview' => 'Overview', 'users' => 'Users', 'payments' => 'Payments', 'affiliates' => 'Affiliates', 'commissions' => 'Commissions', 'payouts' => 'Payouts', 'settings' => 'Settings'];
  if (!isset($tabs[$tab])) $tab = 'overview';
  $q = trim((string) ($_GET['q'] ?? ''));
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $per = 50;
  $off = ($page - 1) * $per;
  $money = fn($v) => '$' . number_format((float) $v, 2);
  $date = fn($v) => $v ? h(substr((string) $v, 0, 16)) : '—';
  $pendingPayouts = (int) (Db::one("SELECT COUNT(*) AS n FROM payouts WHERE status = 'requested'")['n'] ?? 0);
  $pendingAff = (int) (Db::one("SELECT COUNT(*) AS n FROM affiliates WHERE status = 'pending' OR broker_status = 'pending'")['n'] ?? 0);
  $pager = function (int $count) use ($page, $per, $tab, $q): string {
      if ($count < $per && $page === 1) return '';
      $o = '<div class="pager">';
      if ($page > 1) $o .= '<a href="?' . h(http_build_query(['tab' => $tab, 'q' => $q, 'page' => $page - 1])) . '">← Newer</a>';
      if ($count >= $per) $o .= '<a href="?' . h(http_build_query(['tab' => $tab, 'q' => $q, 'page' => $page + 1])) . '">Older →</a>';
      return $o . '</div>';
  };
  $hidden = fn() => '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
?>
  <div class="top">
    <div><h1>FX Signals admin</h1><span class="hint"><?= h($admin) ?></span></div>
    <form method="post"><?= $hidden() ?><button class="ghost" name="action" value="logout">Sign out</button></form>
  </div>
  <nav class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="?tab=<?= h($k) ?>" class="<?= $k === $tab ? 'cur' : '' ?>"><?= h($label) ?><?php
        if ($k === 'payouts' && $pendingPayouts) echo ' <span class="badge">' . $pendingPayouts . '</span>';
        if ($k === 'affiliates' && $pendingAff) echo ' <span class="badge">' . $pendingAff . '</span>';
      ?></a>
    <?php endforeach ?>
  </nav>
  <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif ?>
  <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif ?>

<?php if ($tab === 'overview'):
  $r30 = Payments::revenue(time() - 30 * 86400); $r7 = Payments::revenue(time() - 7 * 86400); $rAll = Payments::revenue(0);
  $src = Db::all("SELECT source, COUNT(*) AS n, COALESCE(SUM(amount_usd),0) AS usd FROM payments WHERE status = 'paid' AND test = 0 AND source <> 'manual' AND created_at >= ? GROUP BY source", [now(time() - 30 * 86400)]);
  $owed = Db::one("SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_usd END),0) AS pend, COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_usd END),0) AS appr, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_usd END),0) AS paid FROM commissions");
  $affCount = (int) (Db::one("SELECT COUNT(*) AS n FROM affiliates WHERE status = 'active'")['n'] ?? 0);
  $referred = (int) (Db::one('SELECT COUNT(*) AS n FROM users WHERE referred_by IS NOT NULL')['n'] ?? 0);
  $topAff = Db::all("SELECT a.code, u.name, COUNT(c.id) AS n, COALESCE(SUM(c.amount_usd),0) AS usd FROM commissions c JOIN affiliates a ON a.user_id = c.affiliate_id JOIN users u ON u.id = a.user_id WHERE c.status <> 'reversed' GROUP BY a.user_id, a.code, u.name ORDER BY usd DESC LIMIT 5");
?>
  <div class="card"><h2>Revenue (after Google's fee, in USD, test purchases excluded)</h2><div class="stats">
    <div class="stat"><span class="hint">Last 7 days</span><b><?= $money($r7['usd']) ?></b><span class="hint"><?= $r7['count'] ?> payments</span></div>
    <div class="stat"><span class="hint">Last 30 days</span><b><?= $money($r30['usd']) ?></b><span class="hint"><?= $r30['count'] ?> payments</span></div>
    <div class="stat"><span class="hint">All time</span><b><?= $money($rAll['usd']) ?></b><span class="hint"><?= $rAll['count'] ?> payments</span></div>
    <?php foreach ($src as $s): ?><div class="stat"><span class="hint"><?= h($s['source'] === 'play' ? 'Google Play' : ucfirst($s['source'])) ?> (30 days)</span><b><?= $money($s['usd']) ?></b><span class="hint"><?= (int) $s['n'] ?> payments</span></div><?php endforeach ?>
  </div></div>
  <div class="card"><h2>Users and Premium</h2><div class="stats">
    <?php foreach (stats() as $k => $v): ?><div class="stat"><span class="hint"><?= h($k) ?></span><b><?= number_format($v) ?></b></div><?php endforeach ?>
  </div></div>
  <div class="card"><h2>Affiliate programme</h2><div class="stats">
    <div class="stat"><span class="hint">Active affiliates</span><b><?= $affCount ?></b></div>
    <div class="stat"><span class="hint">Referred users</span><b><?= $referred ?></b></div>
    <div class="stat"><span class="hint">Commission on hold</span><b><?= $money($owed['pend']) ?></b></div>
    <div class="stat"><span class="hint">Commission owed</span><b><?= $money($owed['appr']) ?></b></div>
    <div class="stat"><span class="hint">Commission paid</span><b><?= $money($owed['paid']) ?></b></div>
    <div class="stat"><span class="hint">Payout requests</span><b><?= $pendingPayouts ?></b></div>
  </div>
  <?php if ($topAff): ?><h3>Top affiliates</h3><table><tr><th>Affiliate</th><th>Code</th><th>Sales</th><th>Earned</th></tr>
    <?php foreach ($topAff as $a): ?><tr><td><?= h($a['name']) ?></td><td><code><?= h($a['code']) ?></code></td><td><?= (int) $a['n'] ?></td><td><?= $money($a['usd']) ?></td></tr><?php endforeach ?></table><?php endif ?>
  </div>

<?php elseif ($tab === 'users'):
  $where = ''; $p = [];
  if ($q !== '') { $where = 'WHERE u.email LIKE ? OR u.name LIKE ? OR u.id = ?'; $p = ["%$q%", "%$q%", (int) $q]; }
  $rows = Db::all("SELECT u.id, u.name, u.email, u.email_verified_at, u.premium_until, u.premium_plan, u.created_at, u.last_seen_at, u.blocked_at, r.code AS ref_code,
      (SELECT COALESCE(SUM(amount_usd),0) FROM payments WHERE user_id = u.id AND status = 'paid') AS paid
    FROM users u LEFT JOIN affiliates r ON r.user_id = u.referred_by $where ORDER BY u.id DESC LIMIT $per OFFSET $off", $p);
?>
  <div class="card"><h2>Users</h2>
    <form method="get" class="search"><input type="hidden" name="tab" value="users"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, email or ID"><button>Search</button></form>
    <div class="scroll"><table><tr><th>User</th><th>Joined</th><th>Premium</th><th>Paid</th><th>Referred by</th><th>Actions</th></tr>
    <?php foreach ($rows as $u): $prem = $u['premium_until'] && strtotime($u['premium_until'] . ' UTC') > time(); ?>
      <tr>
        <td><b><?= h($u['name']) ?></b><br><span class="hint"><?= h($u['email']) ?> · #<?= (int) $u['id'] ?><?= $u['email_verified_at'] ? '' : ' · unverified' ?></span><?= $u['blocked_at'] ? '<br><span class="pill off">Suspended</span>' : '' ?></td>
        <td><?= $date($u['created_at']) ?><br><span class="hint">seen <?= $date($u['last_seen_at']) ?></span></td>
        <td><?= $prem ? '<span class="pill on">until ' . h(substr($u['premium_until'], 0, 10)) . '</span><br><span class="hint">' . h((string) $u['premium_plan']) . '</span>' : '<span class="hint">Free</span>' ?></td>
        <td><?= $money($u['paid']) ?></td>
        <td><?= $u['ref_code'] ? '<code>' . h($u['ref_code']) . '</code>' : '—' ?></td>
        <td class="acts">
          <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="grant"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><input type="number" name="days" min="1" max="3660" placeholder="days" class="sm"><button class="sm">Grant Premium</button></form>
          <?php if (!in_array(strtolower($u['email']), admin_emails(), true)): ?>
          <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><button class="sm ghost" name="action" value="<?= $u['blocked_at'] ? 'unblock' : 'block' ?>"><?= $u['blocked_at'] ? 'Restore' : 'Suspend' ?></button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="hint">No users found.</td></tr><?php endif ?>
    </table></div><?= $pager(count($rows)) ?>
    <p class="hint">Granting Premium adds days on top of any time the user already has. It is recorded as a free payment, so no commission is paid.</p>
  </div>

<?php elseif ($tab === 'payments'):
  $where = ''; $p = [];
  if ($q !== '') { $where = 'WHERE u.email LIKE ? OR p.ref LIKE ?'; $p = ["%$q%", "%$q%"]; }
  $rows = Db::all("SELECT p.*, u.name, u.email, c.amount_usd AS comm, c.status AS comm_status, a.code AS aff_code FROM payments p JOIN users u ON u.id = p.user_id
      LEFT JOIN commissions c ON c.payment_id = p.id LEFT JOIN affiliates a ON a.user_id = c.affiliate_id $where ORDER BY p.id DESC LIMIT $per OFFSET $off", $p);
?>
  <div class="card"><h2>Payments</h2>
    <form method="get" class="search"><input type="hidden" name="tab" value="payments"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search email or reference"><button>Search</button></form>
    <div class="scroll"><table><tr><th>Date</th><th>User</th><th>Source</th><th>Plan</th><th>Amount</th><th>Commission</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $date($r['created_at']) ?></td>
        <td><?= h($r['name']) ?><br><span class="hint"><?= h($r['email']) ?></span></td>
        <td><?= h(['play' => 'Google Play', 'paystack' => 'Paystack', 'manual' => 'Admin grant'][$r['source']] ?? $r['source']) ?><?= $r['test'] ? ' <span class="pill off">test</span>' : '' ?><br><span class="hint mono"><?= h(mb_substr($r['ref'], 0, 28)) ?></span></td>
        <td><?= h($r['plan']) ?></td>
        <td><?= h(number_format((float) $r['amount'], 2) . ' ' . $r['currency']) ?><br><span class="hint"><?= $money($r['amount_usd']) ?></span></td>
        <td><?= $r['comm'] !== null ? $money($r['comm']) . ' <span class="hint">' . h($r['aff_code'] . ' · ' . $r['comm_status']) . '</span>' : '—' ?></td>
        <td><span class="pill <?= $r['status'] === 'paid' ? 'on' : 'off' ?>"><?= h($r['status']) ?></span></td>
        <td><?php if ($r['status'] === 'paid'): ?>
          <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>"><label class="chip sm"><input type="checkbox" name="confirm" value="yes"> confirm</label><button class="sm ghost">Mark refunded</button></form>
        <?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="hint">No payments yet.</td></tr><?php endif ?>
    </table></div><?= $pager(count($rows)) ?>
    <p class="hint">Marking a payment refunded removes the Premium time it bought and reverses the affiliate commission (or deducts it from their next payout if it was already paid). It doesn't move money: refund the customer in the Paystack dashboard or Play Console.</p>
  </div>

<?php elseif ($tab === 'affiliates'):
  $where = ''; $p = [];
  if ($q !== '') { $where = 'WHERE u.email LIKE ? OR u.name LIKE ? OR a.code LIKE ?'; $p = ["%$q%", "%$q%", "%$q%"]; }
  $rows = Db::all("SELECT a.*, u.name, u.email,
      (SELECT COUNT(*) FROM users r WHERE r.referred_by = a.user_id) AS signups,
      (SELECT COUNT(*) FROM referral_clicks k WHERE k.affiliate_id = a.user_id AND k.kind = 'visit') AS visits,
      (SELECT COALESCE(SUM(amount_usd),0) FROM commissions c WHERE c.affiliate_id = a.user_id AND c.status IN ('pending','approved','paid')) AS earned
    FROM affiliates a JOIN users u ON u.id = a.user_id $where
    ORDER BY (a.status = 'pending' OR a.broker_status = 'pending') DESC, a.created_at DESC LIMIT $per OFFSET $off", $p);
  $defRate = (float) Affiliates::settings()['rate'];
?>
  <div class="card"><h2>Affiliates</h2>
    <form method="get" class="search"><input type="hidden" name="tab" value="affiliates"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, email or code"><button>Search</button></form>
    <div class="scroll"><table><tr><th>Affiliate</th><th>Status</th><th>Visits / sign-ups</th><th>Earned</th><th>Rate</th><th>Broker link</th></tr>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><b><?= h($a['name']) ?></b> <code><?= h($a['code']) ?></code><br><span class="hint"><?= h($a['email']) ?> · since <?= h(substr($a['created_at'], 0, 10)) ?></span>
          <?php if ($a['payout_method']): ?><br><span class="hint">Payout: <?= h(Affiliates::PAYOUT_METHODS[$a['payout_method']] ?? $a['payout_method']) ?></span><?php endif ?></td>
        <td><span class="pill <?= $a['status'] === 'active' ? 'on' : 'off' ?>"><?= h($a['status']) ?></span>
          <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="aff_status"><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
            <?php foreach (Affiliates::STATUSES as $st): if ($st === $a['status']) continue; ?><button class="sm ghost" name="status" value="<?= h($st) ?>"><?= h(['active' => 'Approve', 'pending' => 'Set pending', 'blocked' => 'Block'][$st]) ?></button><?php endforeach ?>
          </form></td>
        <td><?= (int) $a['visits'] ?> / <?= (int) $a['signups'] ?></td>
        <td><?= $money($a['earned']) ?></td>
        <td><form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="aff_rate"><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
            <input type="number" step="0.5" min="0" max="90" name="rate" value="<?= $a['rate'] !== null ? h($a['rate']) : '' ?>" placeholder="<?= h($defRate) ?>" class="sm">%
            <input type="text" name="note" value="<?= h((string) $a['note']) ?>" placeholder="private note" class="md"><button class="sm ghost">Save</button></form></td>
        <td><?php if ($a['broker_url']): ?>
            <?= h((string) $a['broker_name']) ?> <span class="pill <?= $a['broker_status'] === 'approved' ? 'on' : 'off' ?>"><?= h((string) $a['broker_status']) ?></span><br>
            <a href="<?= h($a['broker_url']) ?>" target="_blank" rel="noopener noreferrer" class="hint mono"><?= h(mb_substr($a['broker_url'], 0, 40)) ?></a>
            <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="broker"><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
              <?php if ($a['broker_status'] !== 'approved'): ?><button class="sm" name="decision" value="approve">Approve</button><?php endif ?>
              <?php if ($a['broker_status'] !== 'rejected'): ?><button class="sm ghost" name="decision" value="reject">Reject</button><?php endif ?></form>
          <?php else: ?><span class="hint">—</span><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="hint">No affiliates yet. Users join from the website's Affiliate page.</td></tr><?php endif ?>
    </table></div><?= $pager(count($rows)) ?>
    <p class="hint">Leave the rate empty to use the default (<?= h($defRate) ?>%). Check each broker link opens a real broker sign-up page before approving it: approved links are shown to the users that affiliate referred.</p>
  </div>

<?php elseif ($tab === 'commissions'):
  Affiliates::mature();
  $st = (string) ($_GET['status'] ?? '');
  $where = in_array($st, ['pending', 'approved', 'paid', 'reversed'], true) ? 'WHERE c.status = ?' : '';
  $rows = Db::all("SELECT c.*, a.code, au.name AS aff_name, ru.email AS ref_email, p.source, p.amount, p.currency FROM commissions c
      JOIN affiliates a ON a.user_id = c.affiliate_id JOIN users au ON au.id = c.affiliate_id JOIN users ru ON ru.id = c.referred_user_id JOIN payments p ON p.id = c.payment_id
      $where ORDER BY c.id DESC LIMIT $per OFFSET $off", $where ? [$st] : []);
?>
  <div class="card"><h2>Commissions</h2>
    <div class="chips" style="margin-bottom:12px"><?php foreach (['' => 'All', 'pending' => 'On hold', 'approved' => 'Owed', 'paid' => 'Paid', 'reversed' => 'Reversed'] as $k => $l): ?><a class="chip <?= $st === $k ? 'cur' : '' ?>" href="?tab=commissions&amp;status=<?= h($k) ?>"><?= h($l) ?></a><?php endforeach ?></div>
    <div class="scroll"><table><tr><th>Date</th><th>Affiliate</th><th>Referred user</th><th>Payment</th><th>Commission</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= $date($c['created_at']) ?></td>
        <td><?= h($c['aff_name']) ?> <code><?= h($c['code']) ?></code></td>
        <td><?= h($c['ref_email']) ?></td>
        <td><?= h(number_format((float) $c['amount'], 2) . ' ' . $c['currency']) ?> <span class="hint"><?= h($c['source']) ?></span></td>
        <td><?= $money($c['amount_usd']) ?> <span class="hint"><?= h((string) round((float) $c['rate'], 1)) ?>%</span></td>
        <td><span class="pill <?= in_array($c['status'], ['approved', 'paid'], true) ? 'on' : 'off' ?>"><?= h(['pending' => 'on hold', 'approved' => 'owed'][$c['status']] ?? $c['status']) ?></span>
          <?= $c['status'] === 'pending' ? '<br><span class="hint">until ' . h(substr($c['available_at'], 0, 10)) . '</span>' : '' ?><?= $c['payout_id'] ? '<br><span class="hint">payout #' . (int) $c['payout_id'] . '</span>' : '' ?></td>
        <td><?php if (in_array($c['status'], ['pending', 'approved'], true) && !$c['payout_id']): ?>
          <form method="post" class="inline"><?= $hidden() ?><input type="hidden" name="action" value="comm_reverse"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="sm ghost">Reverse</button></form>
        <?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="hint">No commissions.</td></tr><?php endif ?>
    </table></div><?= $pager(count($rows)) ?>
    <p class="hint">A commission is created for every payment by a referred user. It stays on hold for <?= (int) Affiliates::settings()['hold_days'] ?> days (the refund window), then becomes owed and can be requested as a payout.</p>
  </div>

<?php elseif ($tab === 'payouts'):
  $rows = Db::all("SELECT o.*, u.name, u.email, a.code FROM payouts o JOIN users u ON u.id = o.affiliate_id JOIN affiliates a ON a.user_id = o.affiliate_id
      ORDER BY (o.status = 'requested') DESC, o.id DESC LIMIT $per OFFSET $off");
?>
  <div class="card"><h2>Payouts</h2>
    <div class="scroll"><table><tr><th>Requested</th><th>Affiliate</th><th>Amount</th><th>Pay to</th><th>Status</th></tr>
    <?php foreach ($rows as $o): ?>
      <tr>
        <td><?= $date($o['created_at']) ?></td>
        <td><?= h($o['name']) ?> <code><?= h($o['code']) ?></code><br><span class="hint"><?= h($o['email']) ?></span></td>
        <td><b><?= $money($o['amount_usd']) ?></b><?php $nr = (float) Payments::webPricing()['usd_rate']; if ($o['method'] === 'bank' && $nr > 1): ?><br><span class="hint">≈ ₦<?= number_format($o['amount_usd'] * $nr) ?></span><?php endif ?></td>
        <td><?= h(Affiliates::PAYOUT_METHODS[$o['method']] ?? $o['method']) ?><br><span class="mono pre"><?= h($o['details']) ?></span></td>
        <td><?php if ($o['status'] === 'requested'): ?>
          <form method="post" class="stack"><?= $hidden() ?><input type="hidden" name="action" value="payout"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <input type="text" name="reference" placeholder="Transfer reference / tx hash"><input type="text" name="note" placeholder="Note to keep (optional)">
            <div><button class="sm" name="decision" value="paid">Mark paid</button> <button class="sm ghost" name="decision" value="reject">Reject</button></div></form>
        <?php else: ?>
          <span class="pill <?= $o['status'] === 'paid' ? 'on' : 'off' ?>"><?= h($o['status']) ?></span> <span class="hint"><?= $date($o['processed_at']) ?></span>
          <?= $o['reference'] ? '<br><span class="hint mono">' . h($o['reference']) . '</span>' : '' ?><?= $o['admin_note'] ? '<br><span class="hint">' . h($o['admin_note']) . '</span>' : '' ?>
        <?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="hint">No payout requests yet.</td></tr><?php endif ?>
    </table></div><?= $pager(count($rows)) ?>
    <p class="hint">Send the money yourself (bank app, exchange or PayPal), then mark it paid with the reference. The affiliate gets an email.</p>
  </div>

<?php else: /* settings */
  $web = Payments::webPricing(); $aff = Affiliates::settings(); $broker = (array) config('broker_default', []) + ['name' => '', 'url' => '']; $base = Payments::playBase();
?>
  <form method="post">
  <?= $hidden() ?><input type="hidden" name="action" value="save_web">
  <div class="card"><h2>Website Premium (Paystack) <span class="pill <?= Paystack::configured() ? 'on' : 'off' ?>"><?= Paystack::configured() ? (Paystack::testMode() ? 'Test keys' : 'Live keys') : 'Not set up' ?></span></h2>
    <?php if (!Paystack::configured()): ?><p class="hint">Add <code>'paystack' =&gt; ['secret_key' =&gt; 'sk_live_…']</code> to config.php and set the webhook URL in Paystack → Settings → API Keys &amp; Webhooks to <code><?= h(rtrim((string) config('base_url', ''), '/')) ?>/billing/paystack</code>.</p><?php endif ?>
    <div class="row3">
      <div><label>Currency</label><input type="text" name="currency" value="<?= h($web['currency']) ?>" maxlength="3"></div>
      <div><label>Monthly price</label><input type="number" step="0.01" name="monthly_amount" value="<?= h($web['monthly_amount']) ?>"></div>
      <div><label>Yearly price</label><input type="number" step="0.01" name="yearly_amount" value="<?= h($web['yearly_amount']) ?>"></div>
      <div><label>Paystack monthly plan code</label><input type="text" name="monthly_plan" value="<?= h($web['monthly_plan']) ?>" placeholder="PLN_…"></div>
      <div><label>Paystack yearly plan code</label><input type="text" name="yearly_plan" value="<?= h($web['yearly_plan']) ?>" placeholder="PLN_…"></div>
      <div><label>Naira per US dollar (for commissions)</label><input type="number" step="0.01" name="usd_rate" value="<?= h($web['usd_rate']) ?>"></div>
    </div>
    <p class="hint">Changing a price clears its plan code; click "Create Paystack plans" afterwards so renewals charge the new price. Existing subscribers keep their old plan.</p>
  </div>
  <div class="card"><h2>Affiliate programme</h2>
    <label class="chip" style="width:max-content"><input type="checkbox" name="aff_enabled" value="1" <?= $aff['enabled'] ? 'checked' : '' ?>> Programme open</label>
    <label class="chip" style="width:max-content;margin-top:8px"><input type="checkbox" name="aff_auto_approve" value="1" <?= $aff['auto_approve'] ? 'checked' : '' ?>> Approve new affiliates automatically</label>
    <div class="row3">
      <div><label>Commission (% of each payment)</label><input type="number" step="0.5" name="aff_rate" value="<?= h($aff['rate']) ?>"></div>
      <div><label>Hold before payable (days)</label><input type="number" name="aff_hold_days" value="<?= h($aff['hold_days']) ?>"></div>
      <div><label>Minimum payout (USD)</label><input type="number" step="0.01" name="aff_min_payout" value="<?= h($aff['min_payout']) ?>"></div>
      <div><label>Referral cookie (days)</label><input type="number" name="aff_cookie_days" value="<?= h($aff['cookie_days']) ?>"></div>
    </div>
    <h3>Default broker link</h3><p class="hint">Shown to users who weren't referred, or whose affiliate has no approved broker link.</p>
    <div class="row"><div><label>Broker name</label><input type="text" name="broker_name" value="<?= h($broker['name']) ?>"></div><div><label>Your broker IB / referral link</label><input type="text" name="broker_url" value="<?= h($broker['url']) ?>" placeholder="https://"></div></div>
    <h3>Google Play: what you receive per order (USD, after Google's fee)</h3><p class="hint">Commissions on in-app purchases are worked out from these amounts.</p>
    <div class="row4">
      <div><label>Monthly</label><input type="number" step="0.01" name="play_monthly" value="<?= h($base['monthly']) ?>"></div>
      <div><label>Yearly</label><input type="number" step="0.01" name="play_yearly" value="<?= h($base['yearly']) ?>"></div>
      <div><label>Monthly (launch offer)</label><input type="number" step="0.01" name="play_monthly_intro" value="<?= h($base['monthly_intro']) ?>"></div>
      <div><label>Yearly (launch offer)</label><input type="number" step="0.01" name="play_yearly_intro" value="<?= h($base['yearly_intro']) ?>"></div>
    </div>
  </div>
  <p><button type="submit">Save website and affiliate settings</button></p>
  </form>
  <?php if (Paystack::configured()): ?>
  <form method="post" class="card"><?= $hidden() ?><input type="hidden" name="action" value="create_plans">
    <h2>Paystack plans</h2><p class="hint">Creates a monthly and a yearly recurring plan on Paystack at the prices above and saves their codes here.</p><button class="ghost">Create Paystack plans</button></form>
  <?php endif ?>

  <h2 style="margin-top:32px">App settings</h2>
  <form method="post">
  <?= $hidden() ?><input type="hidden" name="action" value="save">

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
      <div><label>Free users: one free Premium unlock every (days, 0 = off)</label><input type="number" name="free_unlock_days" min="0" max="60" value="<?= (int) Billing::freeUnlockDays() ?>"></div>
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

  <div class="card"><h2>Trading bot (Premium)</h2>
    <label class="chip" style="margin:6px 0;width:max-content"><input type="checkbox" name="bot_paper" value="1" <?= !empty($bot['paper']) ? 'checked' : '' ?>> Auto-pilot (paper trading)</label>
    <label class="chip" style="margin:6px 0;width:max-content"><input type="checkbox" name="bot_copy" value="1" <?= !empty($bot['copy']) ? 'checked' : '' ?>> Copy-trade assistant</label>
    <label class="chip" style="margin:6px 0;width:max-content"><input type="checkbox" name="bot_deriv" value="1" <?= !empty($bot['deriv']) ? 'checked' : '' ?>> Auto-trading on Deriv</label>
    <label>Deriv App ID (register a "PAT" app at developers.deriv.com)</label><input type="text" name="deriv_app_id" value="<?= h($bot['deriv_app_id']) ?>" style="max-width:240px">
    <p class="hint">Deriv auto-trading stays hidden in the app until an App ID is set. Users' Deriv tokens never reach this server.</p>
  </div>

  <p><button type="submit">Save app settings</button></p>
  </form>
<?php endif /* tabs */ ?>
<?php endif ?>
</main></body></html>
