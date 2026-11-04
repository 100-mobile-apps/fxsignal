<?php
// Checks for the auto-trade bot endpoint: key handling, strategy/market filtering, closed list.
putenv('FX_CONFIG=' . __DIR__ . '/test_config.php');
// CLI "headers already sent" warnings must not land in the captured JSON output.
ini_set('display_errors', 'stderr');
@unlink(__DIR__ . '/test.db');
require __DIR__ . '/../api/src/bootstrap.php';
Db::pdo()->exec(require __DIR__ . '/sqlite_schema.php');
$GLOBALS['__CONFIG']['default_pairs'] = ['XAU/USD'];
$GLOBALS['__CONFIG']['auto_default_pairs'] = false;
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; echo ($c ? '  PASS ' : '  FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function call(callable $f): array { ob_start(); try { $f(); } catch (ApiError $e) { ob_end_clean(); return ['err' => $e->getMessage(), 'code' => $e->status]; } return json_decode(ob_get_clean(), true) ?? []; }

$t = now();
Db::exec("INSERT INTO users (name,email,password_hash,created_at,ea_key,risk_pct) VALUES ('A','a@x.com','x',?,?,?)", [$t, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 30]);
$ins = fn($pair, $strat, $status, $pub, $result = null, $closed = null) => Db::exec(
  "INSERT INTO signals (pair,timeframe,direction,entry,stop_loss,tp1,tp2,confidence,reasons,status,result,opened_at,expires_at,closed_at,strategy,published,candle_t,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)",
  [$pair, '1h', 'BUY', 2350.0, 2340.0, 2360.0, 2380.0, 70, '[]', $status, $result, $t, now(time() + 86400), $closed, $strat, $pub, $t]);
$ins('XAU/USD', 'classic', 'running', 1);
$ins('XAU/USD', 'steady', 'running', 1);
$ins('XAU/USD', 'classic', 'running', 0);
$ins('EUR/USD', 'classic', 'running', 1);
$ins('XAU/USD', 'classic', 'closed', 1, 'tp2', now(time() - 3600));
$ins('XAU/USD', 'classic', 'closed', 1, 'sl', now(time() - 9 * 86400));

echo "== EA endpoint ==\n";
$_SERVER['HTTP_X_EA_KEY'] = 'bad';
$r = call(fn() => Ea::signals()); ok(($r['code'] ?? 0) === 401, 'bad key rejected');
$_SERVER['HTTP_X_EA_KEY'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$r = call(fn() => Ea::signals());
ok(count($r['signals'] ?? []) === 1, 'only the published classic gold signal is returned (got ' . count($r['signals'] ?? []) . ')');
ok(($r['signals'][0]['pair'] ?? '') === 'XAU/USD' && $r['signals'][0]['stop_pips'] == 100.0, 'gold stop is 100 pips');
ok(($r['risk_pct'] ?? 0) == 30.0, 'risk from the app setting comes back');
ok(count($r['closed'] ?? []) === 1, 'only the recent closed signal is listed');
$_GET['pairs'] = 'EUR/USD,XAU/USD';
$r = call(fn() => Ea::signals()); ok(count($r['signals'] ?? []) === 2, 'pairs filter widens the list');
unset($_GET['pairs']);
Db::exec("UPDATE users SET strategy='steady' WHERE id=1");
$r = call(fn() => Ea::signals()); ok(count($r['signals'] ?? []) === 1 && $r['strategy'] === 'steady', 'follows the user\'s chosen strategy');
unset($_SERVER['HTTP_X_EA_KEY']); $_GET['key'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$r = call(fn() => Ea::signals()); ok(isset($r['signals']), 'key in query string also works');
echo "== Lists follow the viewer's strategy ==\n";
$u = Db::one('SELECT * FROM users WHERE id = 1');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::tokenFor($u);
Db::exec("UPDATE users SET strategy=NULL WHERE id=1");
$r = call(fn() => Signals::list());
$strats = array_unique(array_column($r['items'] ?? [], 'strategy'));
ok($strats === ['classic'] || $strats === [], 'default viewer sees only classic signals (' . implode(',', $strats) . ')');
Db::exec("UPDATE users SET strategy='steady' WHERE id=1");
$r = call(fn() => Signals::list());
ok(array_unique(array_column($r['items'] ?? [], 'strategy')) === ['steady'], 'viewer who chose steady sees steady signals');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
