<?php
/**
 * Automated email tests (SQLite, in-process). Usage: php backend/tests/campaigns_test.php
 */
putenv('FX_CONFIG=' . __DIR__ . '/campaigns_config.php');
@unlink(__DIR__ . '/campaigns.db');
file_put_contents(__DIR__ . '/campaigns_config.php', '<?php return ' . var_export([
    'app_name' => 'FX Campaign Test', 'base_url' => 'https://x/api',
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/campaigns.db'],
    'jwt_secret' => str_repeat('c', 48), 'pairs' => ['EUR/USD'],
    'play' => ['package_name' => 'com.forexsignalslive.forex_signals', 'products' => []],
    'premium_enforced' => true, 'mail_disabled' => true, 'debug' => true,
    'price_display' => ['monthly' => '$4.99', 'yearly' => '$35.99', 'launch_monthly' => '$1.50', 'launch_yearly' => '$12', 'launch_ends' => '2099-01-01'],
], true) . ';');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $what . ($ok || $extra === '' ? '' : " — $extra") . "\n";
    $ok ? $pass++ : $fail++;
}
function mkuser(string $email, int $created, array $extra = []): int {
    $cols = ['name' => 'Ada Test', 'email' => $email, 'password_hash' => 'x', 'email_verified_at' => now($created), 'created_at' => now($created)] + $extra;
    return Db::insert('INSERT INTO users (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
}
function mails(int $uid): array { return array_column(Db::all('SELECT kind FROM email_log WHERE user_id = ? ORDER BY id', [$uid]), 'kind'); }
function subjects(int $uid): array { return array_column(Db::all('SELECT subject FROM email_queue WHERE user_id = ? ORDER BY id', [$uid]), 'subject'); }

$day = 86400;
$t0 = gmmktime(10, 0, 0, 11, 1, 2026);
$old = mkuser('old@example.com', $t0 - 90 * $day, ['last_login_at' => now($t0 - $day)]);

echo "== First run only marks the start ==\n";
Campaigns::run($t0);
check('existing users are not blasted', mails($old) === []);

echo "== Welcome series ==\n";
$new = mkuser('new@example.com', $t0 + 3600, ['last_seen_at' => now($t0 + 3600)]);
Campaigns::run($t0 + 2 * 3600);
check('nothing on day 0', mails($new) === []);
Campaigns::run($t0 + $day + 7200);
check('welcome 1 on day 1', mails($new) === ['welcome1']);
Campaigns::run($t0 + 3 * $day + 7200);
check('welcome 2 on day 3', mails($new) === ['welcome1', 'welcome2']);
Campaigns::run($t0 + 3 * $day + 3 * 3600);
check('max one marketing email a day', mails($new) === ['welcome1', 'welcome2']);
Db::exec('UPDATE users SET last_seen_at = ? WHERE id = ?', [now($t0 + 6 * $day), $new]);
Campaigns::run($t0 + 6 * $day + 7200);
check('weekly cap (2) holds back welcome 3', mails($new) === ['welcome1', 'welcome2'], json_encode(mails($new)));
Campaigns::run($t0 + 9 * $day + 7200);
check('welcome 3 once the week has passed', mails($new) === ['welcome1', 'welcome2', 'welcome3'], json_encode(mails($new)));
$s = subjects($new);
check('offer email shows the launch price', str_contains((string) Db::one("SELECT text_body FROM email_queue WHERE user_id = ? AND subject LIKE 'Try Premium%'", [$new])['text_body'], '$1.50/month or $12/year'));
check('marketing emails carry an unsubscribe link', (int) Db::one("SELECT COUNT(*) AS n FROM email_queue WHERE user_id = ? AND unsubscribe_url LIKE '%k=email_marketing%'", [$new])['n'] === 3);

echo "== Upgrade offer from activity ==\n";
$tapper = mkuser('tapper@example.com', $t0 - 60 * $day, ['last_seen_at' => now($t0 + 20 * $day)]);
Db::exec("INSERT INTO user_events (user_id, kind, created_at) VALUES (?, 'locked_view', ?)", [$tapper, now($t0 + 20 * $day)]);
Campaigns::run($t0 + 20 * $day + 3600);
check('one locked tap is not enough', mails($tapper) === []);
Db::exec("INSERT INTO user_events (user_id, kind, created_at) VALUES (?, 'locked_view', ?)", [$tapper, now($t0 + 20 * $day + 60)]);
Campaigns::run($t0 + 20 * $day + 7200);
check('two locked taps in a week trigger the upgrade offer', mails($tapper) === ['upgrade']);
Db::exec("INSERT INTO user_events (user_id, kind, created_at) VALUES (?, 'locked_view', ?), (?, 'locked_view', ?)", [$tapper, now($t0 + 25 * $day), $tapper, now($t0 + 25 * $day)]);
Campaigns::run($t0 + 25 * $day + 7200);
check('no second upgrade offer within 14 days', mails($tapper) === ['upgrade']);
$paid = mkuser('paid@example.com', $t0 - 60 * $day, ['premium_until' => now($t0 + 99 * $day), 'last_seen_at' => now($t0 + 20 * $day)]);
Db::exec("INSERT INTO user_events (user_id, kind, created_at) VALUES (?, 'locked_view', ?), (?, 'locked_view', ?)", [$paid, now($t0 + 20 * $day), $paid, now($t0 + 20 * $day)]);
Campaigns::run($t0 + 21 * $day + 3600);
check('premium users get no upgrade offer', mails($paid) === []);

echo "== Win-back ==\n";
$away = mkuser('away@example.com', $t0 - 200 * $day, ['last_seen_at' => now($t0 + 30 * $day)]);
Campaigns::run($t0 + 36 * $day);
check('not yet after 6 days', mails($away) === []);
Campaigns::run($t0 + 37 * $day + 60);
check('win-back after 7 days', mails($away) === ['winback7']);
Campaigns::run($t0 + 38 * $day + 60);
check('sent once per inactive spell', mails($away) === ['winback7']);
Campaigns::run($t0 + 44 * $day + 60);
check('then after 14 days', mails($away) === ['winback7', 'winback14']);

echo "== Opt-out ==\n";
$quiet = mkuser('quiet@example.com', $t0 - 200 * $day, ['last_seen_at' => now($t0 + 30 * $day), 'email_marketing' => 0]);
Campaigns::run($t0 + 37 * $day + 600);
check('opted-out users get no marketing', mails($quiet) === []);
$url = Notify::unsubscribeUrl($away, 'email_marketing');
parse_str((string) parse_url($url, PHP_URL_QUERY), $_GET);
ob_start(); @Notify::unsubscribe(); ob_end_clean();
check('unsubscribe link switches marketing off', (int) Db::one('SELECT email_marketing AS m FROM users WHERE id = ?', [$away])['m'] === 0);
Campaigns::run($t0 + 61 * $day + 60);
check('and stops later win-backs', mails($away) === ['winback7', 'winback14']);

echo "== Subscription notices ==\n";
$sub = mkuser('sub@example.com', $t0 - 10 * $day, ['email_marketing' => 0]);
$now = $t0 + 70 * $day;
$sid = Db::insert("INSERT INTO subscriptions (user_id, product_id, plan, phase, purchase_token, token_hash, state, expires_at, auto_renewing, last_checked_at, created_at, updated_at)
    VALUES (?, 'fx_premium_monthly', 'monthly', 'trial', 'x', ?, 'SUBSCRIPTION_STATE_ACTIVE', ?, 1, ?, ?, ?)", [$sub, str_repeat('a', 64), now($now + $day), now($now), now($now), now($now)]);
Campaigns::run($now);
check('trial ending notice (even with marketing off)', mails($sub) === ['trial_ending']);
Campaigns::run($now + 3600);
check('notice sent once', mails($sub) === ['trial_ending']);
Db::exec("UPDATE subscriptions SET state = 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', phase = 'base', updated_at = ? WHERE id = ?", [now($now + 2 * $day), $sid]);
Campaigns::run($now + 2 * $day);
check('payment problem notice', mails($sub) === ['trial_ending', 'payment_failed']);
check('service notices have no unsubscribe link', (int) Db::one('SELECT COUNT(*) AS n FROM email_queue WHERE user_id = ? AND unsubscribe_url IS NOT NULL', [$sub])['n'] === 0);
Db::exec("UPDATE subscriptions SET state = 'SUBSCRIPTION_STATE_EXPIRED', expires_at = ?, updated_at = ? WHERE id = ?", [now($now + 3 * $day), now($now + 3 * $day), $sid]);
Campaigns::run($now + 3 * $day + 3600);
check('expired notice', in_array('expired', mails($sub), true), json_encode(mails($sub)));

echo "== Admin switches ==\n";
$GLOBALS['__CONFIG']['email_campaigns'] = ['winback' => false, 'welcome' => false, 'upgrade' => false, 'lifecycle' => true, 'max_per_week' => 2];
$late = mkuser('late@example.com', $t0 - 200 * $day, ['last_seen_at' => now($t0 + 80 * $day)]);
Campaigns::run($t0 + 87 * $day + 60);
check('switched-off campaign sends nothing', mails($late) === []);

foreach (['welcome1', 'welcome2', 'welcome3', 'upgrade', 'winback7', 'winback14', 'winback30', 'trial_ending', 'renewal', 'payment_failed', 'expired'] as $k) {
    [$subj, $html, $text] = EmailTemplates::campaign($k, 'Ada', str_starts_with($k, 'w') || $k === 'upgrade' ? 'https://x/u' : null, ['expires_at' => '2026-12-01 00:00:00', 'auto_renewing' => 1]);
    if (!$subj || !str_contains($html, 'play.google.com')) check("template $k", false);
}
check('all 11 templates render', true);

echo "\nRESULT: $pass passed, $fail failed\n";
@unlink(__DIR__ . '/campaigns.db');
@unlink(__DIR__ . '/campaigns_config.php');
exit($fail ? 1 : 0);
