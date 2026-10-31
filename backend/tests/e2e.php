<?php
/**
 * End-to-end test against a real MySQL/MariaDB database, the HTTP API and an SMTP server.
 *   1. Two users sign up through the API (one verifies their email, one doesn't)
 *   2. 25 days of hourly cron runs on simulated prices (signals open, hit TP/SL, reports go out)
 *   3. Checks tracking, reports and which emails each user received
 * Usage: FX_CONFIG=tests/mysql_config.php php tests/e2e.php http://127.0.0.1:8091
 */
putenv('FX_CONFIG=' . __DIR__ . '/mysql_config.php');
require __DIR__ . '/../api/src/bootstrap.php';

$API = $argv[1] ?? 'http://127.0.0.1:8091';
$BOX = __DIR__ . '/mailbox';
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $what . ($extra !== '' ? " — $extra" : '') . "\n";
}
function api(string $method, string $path, ?array $body = null, ?string $token = null): array {
    global $API;
    $ch = curl_init($API . $path);
    $h = ['Content-Type: application/json'];
    if ($token) $h[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string) $res, true) ?? $res];
}
function mails(): array {
    global $BOX;
    $out = [];
    foreach (glob("$BOX/*.eml") as $f) {
        $raw = file_get_contents($f);
        preg_match('/^To: (.+)$/mi', $raw, $to);
        preg_match('/^Subject: (.+)$/mi', $raw, $sub);
        $subject = trim($sub[1] ?? '');
        if (preg_match('/=\?UTF-8\?B\?(.+)\?=/', $subject, $m)) $subject = base64_decode($m[1]);
        preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $parts);
        $out[] = ['file' => $f, 'to' => trim($to[1] ?? ''), 'subject' => $subject,
                  'text' => base64_decode(preg_replace('/\s+/', '', $parts[1][0] ?? '')),
                  'html' => base64_decode(preg_replace('/\s+/', '', $parts[1][1] ?? '')),
                  'unsub' => preg_match('/^List-Unsubscribe: <(.+)>/mi', $raw, $u) ? $u[1] : null];
    }
    return $out;
}

echo "== 1. Accounts (API + email) ==\n";
[$c, $r] = api('POST', '/auth/register', ['name' => 'Ada Verified', 'email' => 'ada@example.com', 'password' => 'secret123', 'accepted_terms' => true]);
check('register returns 201 + token', $c === 201 && !empty($r['token']));
check('new account starts unverified', ($r['user']['email_verified'] ?? null) === false);
$tokenA = $r['token'];
$m = array_values(array_filter(mails(), fn($m) => $m['to'] === 'ada@example.com'));
check('verification email delivered via SMTP', count($m) === 1, $m[0]['subject'] ?? '');
preg_match('/code: (\d{6})/', $m[0]['text'] ?? '', $code);
[$c, $r] = api('POST', '/auth/verify', ['code' => '000000'], $tokenA);
check('wrong code rejected', $c === 400);
[$c, $r] = api('POST', '/auth/verify', ['code' => $code[1] ?? ''], $tokenA);
check('correct code verifies email', $c === 200 && $r['user']['email_verified'] === true);
[$c, $r] = api('PUT', '/me/notifications', ['email' => ['daily' => true]], $tokenA);
check('email preferences saved', $c === 200 && $r['user']['notifications']['email']['daily'] === true && $r['user']['notifications']['email']['signals'] === true);

[$c, $r] = api('POST', '/auth/register', ['name' => 'Ben Unverified', 'email' => 'ben@example.com', 'password' => 'secret123', 'accepted_terms' => true]);
$tokenB = $r['token'];
[$c, $r] = api('POST', '/auth/login', ['email' => 'ben@example.com', 'password' => 'nope1234']);
check('wrong password rejected', $c === 401);
[$c, $r] = api('GET', '/signals');
check('signals require login', $c === 401);

echo "\n== 2. 25 days of hourly cron runs on simulated prices ==\n";
$base = ['EUR/USD' => 1.085, 'GBP/USD' => 1.27, 'USD/JPY' => 150, 'AUD/USD' => .66, 'USD/CAD' => 1.36, 'USD/CHF' => .88, 'NZD/USD' => .61, 'XAU/USD' => 2350];
mt_srand(7);
$endHour = intdiv(time(), 3600) * 3600;
$days = 25;
$hours = 24 * $days + 300;
$start = $endHour - ($hours - 1) * 3600;
$series = [];
foreach ($base as $pair => $p) {
    $vol = $p * 0.0015; $drift = 0; $out = [];
    for ($i = 0; $i < $hours; $i++) {
        if ($i % 60 === 0) $drift = (mt_rand() / mt_getrandmax() - 0.5) * $vol * 0.6;
        $g = sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
        $o = $p; $cl = $o + $drift + $g * $vol;
        $out[] = ['t' => $start + $i * 3600, 'o' => $o, 'h' => max($o, $cl) + mt_rand() / mt_getrandmax() * $vol * .6,
                  'l' => min($o, $cl) - mt_rand() / mt_getrandmax() * $vol * .6, 'c' => $cl];
        $p = $cl;
    }
    $series[$pair] = $out;
}
$events = ['NEW' => 0, 'closed' => 0, 'TP1 hit' => 0, 'report' => 0];
for ($h = 300; $h < $hours; $h++) {
    $now = $start + $h * 3600 + 30 * 60; // runs at :30, inside the forming candle
    $log = Cron::run($now, function ($pair) use ($series, $h) {
        $c = array_slice($series[$pair], $h - 299, 300);
        $last = &$c[299];
        $last['c'] = ($last['o'] + $last['c']) / 2; // half-formed candle
        $last['h'] = max($last['o'], $last['c']) + ($last['h'] - max($last['o'], $series[$pair][$h]['c'])) / 2;
        $last['l'] = min($last['o'], $last['c']) - (min($last['o'], $series[$pair][$h]['c']) - $last['l']) / 2;
        return $c;
    }, false);
    foreach ($log as $l) foreach ($events as $k => $_) if (strpos($l, $k) !== false) $events[$k]++;
}
Mailer::processQueue(10000);
echo "  cron events: " . json_encode($events) . "\n";

$sig = Db::one("SELECT COUNT(*) n, SUM(status='closed') closed, SUM(status='running') running FROM signals");
check('signals were published and tracked', $sig['n'] > 20, "{$sig['n']} signals, {$sig['closed']} closed, {$sig['running']} running");
$bad = Db::one("SELECT COUNT(*) n FROM signals WHERE status='closed' AND (result IS NULL OR pips IS NULL OR closed_at IS NULL)");
check('every closed signal has a result, pips and close time', (int) $bad['n'] === 0);
$wrong = Db::one("SELECT COUNT(*) n FROM signals WHERE (result='sl' AND pips >= 0) OR (result IN ('tp1','tp2') AND pips <= 0)");
check('TP results are wins and SL results are losses', (int) $wrong['n'] === 0);
$pr = Db::one('SELECT COUNT(*) n FROM prices');
check('market prices updated for all pairs', (int) $pr['n'] === 8);

echo "\n== 3. Reports (API) ==\n";
foreach (['daily', 'weekly', 'monthly'] as $p) {
    [$c, $r] = api('GET', "/reports?period=$p", null, $tokenA);
    $items = $r['items'] ?? [];
    $sumClosed = array_sum(array_column($items, 'closed'));
    $withData = array_values(array_filter($items, fn($i) => $i['closed'] > 0));
    check("$p report", $c === 200 && count($items) > 0, count($items) . " periods, $sumClosed closed signals, latest with data: "
        . ($withData ? "{$withData[0]['label']} {$withData[0]['wins']}W/{$withData[0]['losses']}L {$withData[0]['pips']} pips" : 'none'));
    if ($p === 'daily') {
        $weekend = array_filter($items, fn($i) => in_array((new DateTime($i['start']))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('N'), ['6', '7']));
        check('daily report skips weekends', count($weekend) === 0);
    }
}
[$c, $r] = api('GET', '/reports?period=monthly', null, $tokenA);
$all = $r['summary']['all'];
$db = Db::one("SELECT COUNT(*) n, ROUND(SUM(pips),1) p FROM signals WHERE status='closed'");
check('all-time summary matches database', $all['closed'] == $db['n'] && abs($all['pips'] - $db['p']) < 0.2,
    "{$all['closed']} closed, {$all['win_rate']}% win rate, {$all['pips']} pips");
$row = $r['items'][0];
[$c, $s] = api('GET', '/signals?status=closed&from=' . urlencode($row['start']) . '&to=' . urlencode($row['end']), null, $tokenA);
check('report drill-down lists that period\'s signals', $c === 200 && count($s['items']) === min(20, $row['closed']), "{$row['label']}: {$row['closed']} signals");

echo "\n== 4. Emails received ==\n";
$all = mails();
$ada = array_values(array_filter($all, fn($m) => $m['to'] === 'ada@example.com'));
$ben = array_values(array_filter($all, fn($m) => $m['to'] === 'ben@example.com'));
$kinds = ['new signal' => 0, 'TP1 reached' => 0, 'hit TP2' => 0, 'stopped out' => 0, 'closed' => 0, 'expired' => 0, 'Daily report' => 0, 'Weekly report' => 0, 'Monthly report' => 0];
foreach ($ada as $m) foreach ($kinds as $k => $_) if (stripos($m['subject'], $k) !== false) { $kinds[$k]++; break; }
echo '  Ada (verified): ' . count($ada) . ' emails ' . json_encode($kinds) . "\n";
check('verified user gets new-signal emails', $kinds['new signal'] > 0);
check('verified user gets win and loss emails', $kinds['hit TP2'] + $kinds['closed'] > 0 && $kinds['stopped out'] > 0);
check('verified user gets daily + weekly reports', $kinds['Daily report'] > 0 && $kinds['Weekly report'] > 0);
check('unverified user gets only the verification email', count($ben) === 1, count($ben) . ' email(s)');
$withUnsub = array_filter($ada, fn($m) => $m['unsub']);
check('alert emails carry a one-click unsubscribe link', count($withUnsub) === count($ada) - 1);

$unsub = null;
foreach ($ada as $m) if (stripos($m['subject'], 'new signal') !== false) { $unsub = $m['unsub']; break; }
$ch = curl_init($unsub); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $page = curl_exec($ch);
$u = Db::one("SELECT email_signals FROM users WHERE email = 'ada@example.com'");
check('unsubscribe link turns off that email type', (int) $u['email_signals'] === 0 && strpos($page, 'unsubscribed') !== false);

// Save one sample of each email for review
@mkdir(__DIR__ . '/sample_emails');
$saved = [];
foreach ($ada as $m) {
    foreach (['verify' => 'Verify', 'signal' => 'new signal', 'tp1' => 'TP1 reached', 'win' => 'hit TP2', 'loss' => 'stopped out', 'daily' => 'Daily report', 'weekly' => 'Weekly report', 'monthly' => 'Monthly report'] as $k => $needle) {
        if (!isset($saved[$k]) && stripos($m['subject'], $needle) !== false) {
            file_put_contents(__DIR__ . "/sample_emails/$k.html", $m['html']);
            $saved[$k] = $m['subject'];
        }
    }
}
echo "\n  sample subjects:\n";
foreach ($saved as $k => $s) echo "   [$k] $s\n";
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
