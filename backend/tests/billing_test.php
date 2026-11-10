<?php
/**
 * Premium subscription tests (in-process, SQLite, fake Google Play responses).
 * Usage: php backend/tests/billing_test.php
 */
putenv('FX_CONFIG=' . __DIR__ . '/billing_config.php');
@unlink(__DIR__ . '/billing.db');
file_put_contents(__DIR__ . '/billing_config.php', '<?php return ' . var_export([
    'app_name' => 'FX Billing Test',
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/billing.db'],
    'jwt_secret' => str_repeat('k', 48),
    'pairs' => ['EUR/USD', 'XAU/USD'],
    'play' => ['package_name' => 'com.forexsignalslive.forex_signals', 'products' => ['fx_premium_monthly', 'fx_premium_yearly'], 'rtdn_secret' => 'rtdn-secret'],
    'premium_emails' => ['reviewer@example.com'],
    'min_app_build' => 5,
    'fcm' => ['project_id' => '', 'service_account_file' => ''],
    'mail_disabled' => true, 'debug' => true,
], true) . ';');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $what . ($extra !== '' ? " — $extra" : '') . "\n";
}
/** Calls an API handler in-process and returns [status, json]. */
function call(callable $handler, ?array $body = null, ?string $token = null, int $build = 5, array $get = []): array {
    $_SERVER['HTTP_AUTHORIZATION'] = $token ? "Bearer $token" : '';
    if ($build) $_SERVER['HTTP_X_APP_BUILD'] = (string) $build; else unset($_SERVER['HTTP_X_APP_BUILD']);
    $_GET = $get;
    Http::$bodyOverride = $body === null ? '' : json_encode($body);
    Http::$lastStatus = 200;
    ob_start();
    try { $handler(); $code = Http::$lastStatus; }
    catch (ApiError $e) { ob_clean(); echo json_encode(['error' => $e->getMessage()]); $code = $e->status; }
    $out = ob_get_clean();
    return [$code, json_decode($out, true)];
}
function user(string $name, string $email): array {
    $id = Db::insert('INSERT INTO users (name, email, password_hash, created_at, email_verified_at) VALUES (?, ?, ?, ?, ?)',
        [$name, $email, password_hash('x', PASSWORD_DEFAULT), now(), now()]);
    $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    return [$u, Auth::tokenFor($u)];
}
function play(string $state, int $expires, string $product, ?int $forUser, array $extra = []): array {
    return $extra + [
        'subscriptionState' => $state,
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
        'latestOrderId' => 'GPA.1234-5678',
        'externalAccountIdentifiers' => $forUser ? ['obfuscatedExternalAccountId' => Billing::accountTag($forUser)] : [],
        'lineItems' => [['productId' => $product, 'expiryTime' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'autoRenewingPlan' => ['autoRenewEnabled' => true], 'offerDetails' => ['basePlanId' => str_contains($product, 'year') ? 'yearly' : 'monthly']]],
    ];
}

[$free, $freeTok] = user('Free', 'free@example.com');
[$paid, $paidTok] = user('Paid', 'paid@example.com');
[$other, $otherTok] = user('Other', 'other@example.com');
[$rev, $revTok] = user('Reviewer', 'reviewer@example.com');
$sid = Db::insert("INSERT INTO signals (pair, timeframe, direction, entry, stop_loss, tp1, tp2, confidence, reasons, candles, candle_t, status, current_price, live_pips, opened_at, expires_at, updated_at)
    VALUES ('EUR/USD', '1h', 'BUY', 1.0842, 1.0811, 1.0873, 1.0904, 78, '[\"a\"]', '[]', 1, 'running', 1.0850, 8.0, ?, ?, ?)", [now(), now(time() + 86400), now()]);
Db::insert("INSERT INTO signals (pair, timeframe, direction, entry, stop_loss, tp1, tp2, confidence, reasons, candles, candle_t, status, result, pips, current_price, live_pips, opened_at, closed_at, expires_at, updated_at)
    VALUES ('XAU/USD', '1h', 'SELL', 2641.5, 2652.8, 2630.2, 2618.9, 71, '[]', '[]', 2, 'closed', 'tp2', 226, 2618.9, null, ?, ?, ?, ?)", [now(), now(), now(), now()]);

echo "== Locking ==\n";
[$c, $r] = call(fn() => Signals::list(), null, $freeTok);
$s = $r['items'][0] ?? [];
check('free user sees live signal pair, direction and strength', $c === 200 && $s['pair'] === 'EUR/USD' && $s['direction'] === 'BUY' && $s['confidence'] === 78);
check('free user gets no entry/SL/TP/live pips/reasons', $s['locked'] === true && $s['entry'] === null && $s['stop_loss'] === null && $s['tp1'] === null && $s['tp2'] === null && $s['live_pips'] === null && $s['reasons'] === []);
[$c, $r] = call(fn() => Signals::show(['id' => $sid]), null, $freeTok);
check('free user signal detail is locked too', $c === 200 && $r['signal']['entry'] === null && $r['signal']['locked'] === true);
[$c, $r] = call(fn() => Signals::list(), null, $freeTok, 5, ['status' => 'closed']);
check('closed signals (track record) stay fully visible', $c === 200 && $r['items'][0]['entry'] === 2641.5 && $r['items'][0]['locked'] === false);
[$c, $r] = call(fn() => Signals::list(), null, $freeTok, 0);
check('old app (no build header) is asked to update for live signals', $c === 426 && str_contains($r['error'], 'update'));
[$c, $r] = call(fn() => Signals::list(), null, $freeTok, 0, ['status' => 'closed']);
check('old app can still load history', $c === 200 && count($r['items']) === 1);
[$c, $r] = call(fn() => Signals::list(), null, $revTok);
check('complimentary (reviewer) account sees full levels', $c === 200 && $r['items'][0]['entry'] === 1.0842);
[$c, $r] = call(fn() => Account::me(), null, $freeTok);
check('profile reports premium=false and a billing account id', $r['user']['premium'] === false && strlen($r['user']['billing_account_id']) === 64);

echo "== Verify purchase ==\n";
$responses = [];
Billing::$fetcher = function (string $token) use (&$responses) { if (!isset($responses[$token])) throw new ApiError('Google Play did not recognise this purchase.', 422); return $responses[$token]; };
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_yearly', 'purchase_token' => 'forged'], $paidTok);
check('fake purchase token is rejected', $c === 422);
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_lifetime', 'purchase_token' => 'tokA'], $paidTok);
check('unknown product is rejected', $c === 422);
$responses['tokOther'] = play('SUBSCRIPTION_STATE_ACTIVE', time() + 86400 * 30, 'fx_premium_monthly', $other['id']);
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_monthly', 'purchase_token' => 'tokOther'], $paidTok);
check('purchase bought by another account is rejected', $c === 409);
$responses['tokA'] = play('SUBSCRIPTION_STATE_ACTIVE', time() + 86400 * 365, 'fx_premium_yearly', $paid['id']);
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_monthly', 'purchase_token' => 'tokA'], $paidTok);
check('token for a different product is rejected', $c === 422);
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_yearly', 'purchase_token' => 'tokA'], $paidTok);
check('valid yearly purchase activates premium', $c === 200 && $r['user']['premium'] === true && $r['user']['premium_plan'] === 'yearly');
$stored = Db::one('SELECT purchase_token FROM subscriptions');
check('purchase token is stored encrypted', $stored && !str_contains($stored['purchase_token'], 'tokA'));
[$c, $r] = call(fn() => Signals::list(), null, $paidTok);
check('subscriber sees full levels', $r['items'][0]['entry'] === 1.0842 && $r['items'][0]['tp2'] === 1.0904 && $r['items'][0]['locked'] === false);
[$c, $r] = call(fn() => Billing::verify(), ['product_id' => 'fx_premium_yearly', 'purchase_token' => 'tokA'], $otherTok);
check('same token cannot be reused on a second account', $c === 409);

echo "== Lifecycle (cron re-check) ==\n";
$responses['tokA'] = play('SUBSCRIPTION_STATE_CANCELED', time() + 86400 * 10, 'fx_premium_yearly', $paid['id']);
Db::exec('UPDATE subscriptions SET last_checked_at = ?', [now(time() - 2 * 86400)]);
Billing::refreshDue(time());
check('cancelled but paid-up subscription keeps premium until expiry', Billing::isPremium(Db::one('SELECT * FROM users WHERE id = ?', [$paid['id']])));
$responses['tokA'] = play('SUBSCRIPTION_STATE_EXPIRED', time() - 60, 'fx_premium_yearly', $paid['id']);
Db::exec('UPDATE subscriptions SET last_checked_at = ?', [now(time() - 2 * 86400)]);
Billing::refreshDue(time());
check('expired subscription removes premium', !Billing::isPremium(Db::one('SELECT * FROM users WHERE id = ?', [$paid['id']])));
[$c, $r] = call(fn() => Signals::list(), null, $paidTok);
check('expired subscriber is locked again', $r['items'][0]['entry'] === null);

$responses['tokB'] = play('SUBSCRIPTION_STATE_ON_HOLD', time() + 86400, 'fx_premium_monthly', $paid['id']);
call(fn() => Billing::verify(), ['product_id' => 'fx_premium_monthly', 'purchase_token' => 'tokB'], $paidTok);
check('account on hold (payment failed) is not premium', !Billing::isPremium(Db::one('SELECT * FROM users WHERE id = ?', [$paid['id']])));
$responses['tokB'] = play('SUBSCRIPTION_STATE_ACTIVE', time() + 86400 * 30, 'fx_premium_monthly', $paid['id']);
$msg = ['message' => ['data' => base64_encode(json_encode(['packageName' => 'com.forexsignalslive.forex_signals', 'subscriptionNotification' => ['notificationType' => 1, 'purchaseToken' => 'tokB']]))]];
[$c] = call(fn() => Billing::rtdn(), $msg, null, 5, ['secret' => 'wrong']);
check('Google notification with wrong secret is refused', $c === 403);
[$c] = call(fn() => Billing::rtdn(), $msg, null, 5, ['secret' => 'rtdn-secret']);
check('Google notification (payment recovered) restores premium instantly', $c === 200 && Billing::isPremium(Db::one('SELECT * FROM users WHERE id = ?', [$paid['id']])));

echo "== Push targeting ==\n";
call(fn() => Billing::saveDevice(), ['token' => 'dev-paid'], $paidTok);
call(fn() => Billing::saveDevice(), ['token' => 'dev-free'], $freeTok);
$tokens = Billing::premiumDeviceTokens('EUR/USD');
check('full-level push goes only to premium devices', $tokens === ['dev-paid'], json_encode($tokens));
Db::exec("UPDATE users SET notify_pairs = ? WHERE id = ?", [json_encode(['XAU/USD']), $paid['id']]);
check('premium push respects followed pairs', Billing::premiumDeviceTokens('EUR/USD') === []);
$fresh = Db::one('SELECT * FROM signals WHERE id = ?', [$sid]);
[$subj, $html, $text] = EmailTemplates::lockedSignal($fresh, 'Free', 'https://x/unsub');
check('free-user signal email contains no levels', !str_contains($html . $text, '1.0842') && !str_contains($html . $text, '1.0811') && str_contains($subj, 'EUR/USD'));

echo "== Launch switch ==\n";
$GLOBALS['__CONFIG']['premium_enforced'] = false;
[$c, $r] = call(fn() => Signals::list(), null, $freeTok, 0);
check('before launch, old app still gets live signals with full levels', $c === 200 && $r['items'][0]['entry'] === 1.0842);
$GLOBALS['__CONFIG']['premium_enforced'] = true;
[$c, $r] = call(fn() => Signals::list(), null, $freeTok, 0);
check('after launch, old app is asked to update', $c === 426);

echo "\nRESULT: $pass passed, $fail failed\n";
@unlink(__DIR__ . '/billing.db');
@unlink(__DIR__ . '/billing_config.php');
exit($fail ? 1 : 0);
