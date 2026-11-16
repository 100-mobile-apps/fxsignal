<?php
declare(strict_types=1);

// Independent admin session. The app login and API remain unchanged.
$config = require dirname(__DIR__) . '/api/src/config.php';
$hash = (string)($config['web_admin_password_hash'] ?? '');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
session_name('fx_web_admin');
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sql(PDO $db, string $query): array { return $db->query($query)->fetchAll(PDO::FETCH_ASSOC); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid form token'); }
    if (($_POST['action'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); header('Location: /web/admin.php', true, 303); exit; }
    if (($_POST['action'] ?? '') === 'login') {
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true); $_SESSION['admin'] = true;
        } else { $error = 'Incorrect admin password'; sleep(1); }
    }
}
$ready = $hash !== '' && !str_contains($hash, 'REPLACE_ME');
if (!$ready) http_response_code(503);
$db = null; $users = []; $signals = []; $userCount = 0; $signalCount = 0;
if ($ready && !empty($_SESSION['admin'])) {
    try {
        $c = $config['db'];
        $db = new PDO('mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $signalCount = (int)$db->query('SELECT COUNT(*) FROM signals')->fetchColumn();
        $users = sql($db, 'SELECT id,name,email,created_at FROM users ORDER BY id DESC LIMIT 20');
        $signals = sql($db, 'SELECT id,pair,direction,status,opened_at FROM signals ORDER BY id DESC LIMIT 20');
    } catch (Throwable $exception) { error_log('Web admin read failed: ' . $exception->getMessage()); $error = 'Could not load the overview. Please try again.'; }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · FX Signals Live</title><style>
:root{font-family:system-ui,sans-serif;color:#eaf2f9;background:#091321}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1100px;margin:auto;padding:30px 18px}header{border-bottom:1px solid #284159}header .wrap{display:flex;justify-content:space-between;align-items:center}a{color:#75e4cd;text-decoration:none}.panel{background:#102238;border:1px solid #2b4056;border-radius:16px;padding:25px;margin:18px 0}.login{max-width:420px;margin:70px auto}input,button{font:inherit;border-radius:9px;padding:11px;background:#091b2d;border:1px solid #40576c;color:#fff}input[type=password]{display:block;width:100%;margin:12px 0}button{background:#65e1c4;color:#09212a;font-weight:700;cursor:pointer}.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}.stats strong{display:block;font-size:2rem}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px;text-align:left;border-bottom:1px solid #30465a}th{color:#9cb3c5}small,.muted{color:#a9bfd0}.error{color:#ffd0d0}h1{letter-spacing:-.04em}@media(max-width:620px){.stats{grid-template-columns:1fr}}
</style></head><body><header><div class="wrap"><strong>FX Signals Live · Admin</strong><a href="/web/">Website</a></div></header><main class="wrap">
<?php if (!$ready): ?><div class="panel login"><h1>Admin setup needed</h1><p>Configure <code>web_admin_password_hash</code> in the private backend config to enable this panel.</p></div>
<?php elseif (empty($_SESSION['admin'])): ?><div class="panel login"><h1>Admin sign in</h1><p class="muted">This is separate from the user account.</p><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login"><label for="password">Admin password</label><input id="password" name="password" type="password" autocomplete="current-password" required><button>Sign in</button></form></div>
<?php else: ?><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h1>Overview</h1><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button>Sign out</button></form></div><?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><div class="stats"><div class="panel"><small>Registered users</small><strong><?=h($userCount)?></strong></div><div class="panel"><small>Published signals</small><strong><?=h($signalCount)?></strong></div></div><div class="panel table-wrap"><h2>Recent users</h2><table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th></tr></thead><tbody><?php foreach ($users as $u): ?><tr><td><?=h($u['id'])?></td><td><?=h($u['name'])?></td><td><?=h($u['email'])?></td><td><?=h($u['created_at'])?></td></tr><?php endforeach; ?></tbody></table></div><div class="panel table-wrap"><h2>Recent signals</h2><table><thead><tr><th>ID</th><th>Market</th><th>Direction</th><th>Status</th><th>Opened</th></tr></thead><tbody><?php foreach ($signals as $s): ?><tr><td><?=h($s['id'])?></td><td><?=h($s['pair'])?></td><td><?=h($s['direction'])?></td><td><?=h($s['status'])?></td><td><?=h($s['opened_at'])?></td></tr><?php endforeach; ?></tbody></table></div><p class="muted">Read-only overview. Signals remain controlled by the existing engine.</p><?php endif; ?></main></body></html>
