<?php
// Signals tab, 30-day stats, alerts and the bot follow the viewer's markets:
// their own pick, else the best recent markets. ?markets=all shows everything.
putenv('FX_CONFIG=' . __DIR__ . '/test_config.php');
ini_set('display_errors', 'stderr');
@unlink(__DIR__ . '/test.db');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');
$GLOBALS['__CONFIG']['default_pairs'] = ['XAU/USD'];
$GLOBALS['__CONFIG']['strategy_order'] = ['classic']; // these checks are about markets, not the default strategy
$GLOBALS['__CONFIG']['auto_default_pairs'] = true;
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; echo ($c ? '  PASS ' : '  FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function call(callable $f): array { ob_start(); try { $f(); } catch (ApiError $e) { ob_end_clean(); return ['err' => $e->getMessage(), 'code' => $e->status]; } return json_decode(ob_get_clean(), true) ?? []; }
function pairsOf(array $r): array { $p = array_values(array_unique(array_column($r['items'] ?? [], 'pair'))); sort($p); return $p; }
function asUser(int $id): void { $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::tokenFor(Db::one('SELECT * FROM users WHERE id = ?', [$id])); }

$t = now();
Db::exec("INSERT INTO users (name,email,password_hash,created_at,email_verified_at,email_signals) VALUES ('Best','b@x.com','x',?,?,1)", [$t, $t]);
Db::exec("INSERT INTO users (name,email,password_hash,created_at,email_verified_at,email_signals,notify_pairs) VALUES ('Own','o@x.com','x',?,?,1,?)", [$t, $t, json_encode(['EUR/USD'])]);
Db::exec("INSERT INTO users (name,email,password_hash,created_at,email_verified_at,email_signals,notify_pairs) VALUES ('Empty','e@x.com','x',?,?,1,?)", [$t, $t, '[]']);
foreach (['XAU/USD', 'USD/CAD', 'GBP/USD', 'EUR/USD', 'NZD/USD'] as $pair) {
    foreach (['running', 'closed'] as $st) {
        Db::exec("INSERT INTO signals (pair,timeframe,direction,entry,stop_loss,tp1,tp2,confidence,reasons,status,result,opened_at,expires_at,closed_at,strategy,published,candle_t,updated_at,pips) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)",
            [$pair, '1h', 'BUY', 1.1, 1.099, 1.101, 1.102, 70, '[]', $st, $st === 'closed' ? 'tp2' : null, $t, now(time() + 86400), $st === 'closed' ? now(time() - 3600) : null, 'classic', 1, $t, $st === 'closed' ? 15 : null]);
    }
}

echo "== Before any ranking: default market (gold) ==\n";
asUser(1);
$r = call(fn() => Signals::list());
ok(pairsOf($r) === ['XAU/USD'], 'new user sees only gold (' . implode(',', pairsOf($r)) . ')');
ok(($r['markets']['source'] ?? '') === 'default', 'source says default');

echo "== With best markets ranked ==\n";
Db::exec("INSERT INTO meta (k, v) VALUES ('market_rank', ?)", [json_encode(['top' => ['XAU/USD', 'USD/CAD', 'GBP/USD']])]);
(new ReflectionProperty('MarketRank', 'stored'))->setValue(null, null);
// default_pairs() caches per request; a fresh process would see the new ranking, so read user_pairs via a new user row.
$best = MarketRank::top();
ok($best === ['XAU/USD', 'USD/CAD', 'GBP/USD'], 'ranking stored');

echo "== Own pick ==\n";
asUser(2);
$r = call(fn() => Signals::list());
ok(pairsOf($r) === ['EUR/USD'], 'user who picked EUR/USD sees only EUR/USD');
ok(($r['markets']['source'] ?? '') === 'chosen', 'source says chosen');
$_GET['markets'] = 'all';
$r = call(fn() => Signals::list());
ok(count(pairsOf($r)) === 5 && ($r['markets']['mode'] ?? '') === 'all', 'markets=all shows every market');
unset($_GET['markets']);
$_GET['status'] = 'closed';
$r = call(fn() => Signals::list());
ok(pairsOf($r) === ['EUR/USD'], 'history follows the same markets');
unset($_GET['status']);
$s = call(fn() => Stats::show());
ok(($s['total'] ?? -1) === 1 && ($s['running'] ?? -1) === 1, '30-day stats count only own markets (total ' . ($s['total'] ?? '?') . ', running ' . ($s['running'] ?? '?') . ')');
$_GET['markets'] = 'all';
$s = call(fn() => Stats::show());
ok(($s['total'] ?? -1) === 5 && ($s['running'] ?? -1) === 5, 'stats with markets=all count every market');
unset($_GET['markets']);

echo "== Profile tells the app whether markets were picked ==\n";
$u2 = Auth::publicUser(Db::one('SELECT * FROM users WHERE id = 2'));
ok(($u2['notifications']['pairs_chosen'] ?? null) === true && $u2['notifications']['pairs'] === ['EUR/USD'], 'own pick: chosen = true');
$u1 = Auth::publicUser(Db::one('SELECT * FROM users WHERE id = 1'));
ok(($u1['notifications']['pairs_chosen'] ?? null) === false, 'no pick: chosen = false');
$u3 = Auth::publicUser(Db::one('SELECT * FROM users WHERE id = 3'));
ok(($u3['notifications']['pairs_chosen'] ?? null) === false && $u3['notifications']['pairs'] !== [], 'empty pick falls back to best markets, not nothing');

echo "== Alerts follow the same markets ==\n";
$m = new ReflectionMethod('Notify', 'recipients');
$names = fn($pair) => array_column($m->invoke(null, 'email_signals', $pair), 'name');
ok(in_array('Own', $names('EUR/USD'), true) && !in_array('Own', $names('XAU/USD'), true), 'own pick gets EUR/USD alerts only');
ok(in_array('Best', $names('XAU/USD'), true) && !in_array('Best', $names('EUR/USD'), true), 'no pick gets default-market alerts only');

echo "== Best markets are re-picked week by week (no hindsight) ==\n";
$wk = Results::weekStart(strtotime('2026-10-15 12:00:00 UTC'));
ok(gmdate('Y-m-d H:i', $wk) === '2026-10-12 00:00', 'week starts Monday 00:00 UTC');
$rowsFor = function (string $pair, int $n, float $pips, string $closed) {
    $o = [];
    for ($i = 0; $i < $n; $i++) $o[] = ['pair' => $pair, 'entry' => 1.1, 'stop_loss' => 1.099, 'tp1' => 1.101, 'tp2' => 1.102,
        'pips' => $pips, 'closed_at' => $closed, 'direction' => 'BUY'];
    return $o;
};
$hist = array_merge($rowsFor('EUR/USD', 8, 12, '2026-10-05 10:00:00'), $rowsFor('NZD/USD', 8, -12, '2026-10-05 10:00:00'),
    $rowsFor('USD/JPY', 3, 30, '2026-10-05 10:00:00'));
ok(MarketRank::pickAt($hist, $wk) === ['EUR/USD'], 'pick uses only the 14 days before: winner with 8+ trades (' . implode(',', MarketRank::pickAt($hist, $wk)) . ')');
ok(MarketRank::pickAt($hist, strtotime('2026-10-05 00:00:00 UTC')) === ['XAU/USD'], 'nothing qualifies yet: falls back to gold');
ok(MarketRank::pickAt($hist, strtotime('2026-11-30 00:00:00 UTC')) === ['XAU/USD'], 'old trades drop out of the window');

echo "== Default strategy ==\n";
$GLOBALS['__CONFIG']['strategy_order'] = null; unset($GLOBALS['__CONFIG']['strategy_order']);
(new ReflectionProperty('MarketRank', 'stored'))->setValue(null, ['best_strategy' => null]);
ok(MarketRank::bestStrategy() === 'steady', 'nothing proven live yet: Steady is the default, not classic (' . MarketRank::bestStrategy() . ')');
(new ReflectionProperty('MarketRank', 'stored'))->setValue(null, ['best_strategy' => 'high_reward']);
ok(MarketRank::bestStrategy() === 'high_reward', 'a strategy proven live (20+ results, positive) takes over');

echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
