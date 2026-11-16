<?php
declare(strict_types=1);
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
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid form token'); }
    if (($_POST['action'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); header('Location: /web/admin.php', true, 303); exit; }
    if (($_POST['action'] ?? '') === 'login') {
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true); $_SESSION['admin'] = true;
            header('Location: /web/admin.php', true, 303); exit;
        }
        $error = 'Incorrect admin password'; sleep(1);
    }
}
$ready = $hash !== '' && !str_contains($hash, 'REPLACE_ME');
if (!$ready) http_response_code(503);
$view = in_array($_GET['view'] ?? '', ['overview','users','signals'], true) ? $_GET['view'] : 'overview';
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$pageNumber = max(1, min(10000, (int)($_GET['p'] ?? 1)));
$limit = 25; $offset = ($pageNumber - 1) * $limit;
$users = $signals = $statusCounts = [];
$userCount = $signalCount = $activeCount = $recentCount = $totalRows = 0;
if ($ready && !empty($_SESSION['admin'])) {
    try {
        $c = $config['db'];
        $db = new PDO('mysql:host='.$c['host'].';dbname='.$c['name'].';charset=utf8mb4', $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $signalCount = (int)$db->query('SELECT COUNT(*) FROM signals')->fetchColumn();
        $activeCount = (int)$db->query("SELECT COUNT(*) FROM signals WHERE status IN ('active','running','open')")->fetchColumn();
        $recentCount = (int)$db->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        if ($view === 'overview') {
            $users = $db->query('SELECT id,name,email,created_at FROM users ORDER BY id DESC LIMIT 8')->fetchAll();
            $signals = $db->query("SELECT s.id, s.pair, s.direction, s.status, s.opened_at FROM signals s ORDER BY s.id DESC LIMIT 8")->fetchAll();
            $statusCounts = $db->query('SELECT status, COUNT(*) AS total FROM signals GROUP BY status ORDER BY total DESC')->fetchAll();
        } elseif ($view === 'users') {
            $where = $search !== '' ? ' WHERE name LIKE :q1 OR email LIKE :q2' : '';
            $stmt = $db->prepare('SELECT COUNT(*) FROM users'.$where);
            if ($search !== '') $stmt->execute(['q1'=>'%'.$search.'%', 'q2'=>'%'.$search.'%']); else $stmt->execute();
            $totalRows = (int)$stmt->fetchColumn();
            $stmt = $db->prepare('SELECT id,name,email,created_at FROM users'.$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);
            if ($search !== '') $stmt->execute(['q1'=>'%'.$search.'%', 'q2'=>'%'.$search.'%']); else $stmt->execute();
            $users = $stmt->fetchAll();
        } else {
            $where = $search !== '' ? ' WHERE CAST(s.id AS CHAR) LIKE :q OR s.status LIKE :status' : '';
            $stmt = $db->prepare('SELECT COUNT(*) FROM signals s'.$where);
            if ($search !== '') $stmt->execute(['q'=>'%'.$search.'%', 'status'=>'%'.$search.'%']); else $stmt->execute();
            $totalRows = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("SELECT s.id, s.pair, s.direction, s.status, s.opened_at FROM signals s".$where.' ORDER BY s.id DESC LIMIT '.$limit.' OFFSET '.$offset);
            if ($search !== '') $stmt->execute(['q'=>'%'.$search.'%', 'status'=>'%'.$search.'%']); else $stmt->execute();
            $signals = $stmt->fetchAll();
        }
    } catch (Throwable $exception) {
        error_log('Web admin read failed: '.$exception->getMessage());
        $error = 'Unable to load data. Please try again.';
    }
}
function urlFor(string $view, string $q = '', int $p = 1): string { return '/web/admin.php?'.http_build_query(['view'=>$view,'q'=>$q,'p'=>$p]); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=h(ucfirst($view))?> · FX Signals Live Admin</title><style>
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#eaf2f9;background:#081421}*{box-sizing:border-box}body{margin:0}a{color:inherit;text-decoration:none}button,input{font:inherit}button{cursor:pointer}.shell{display:grid;grid-template-columns:240px 1fr;min-height:100vh}.sidebar{background:#0c2032;border-right:1px solid #294055;padding:30px 20px}.brand{display:block;font-weight:800;font-size:1.1rem;margin-bottom:8px}.side-note{color:#8da7ba;font-size:.78rem;margin-bottom:35px}.side-nav{display:grid;gap:8px}.side-nav a{padding:12px 13px;border-radius:10px;color:#adbfce}.side-nav a:hover,.side-nav a.active{background:#204058;color:#fff}.side-bottom{margin-top:40px;color:#8da7ba;font-size:.85rem}.side-bottom a{display:block;margin:12px 0}.content{min-width:0;padding:30px clamp(20px,4vw,60px) 70px;max-width:1500px;width:100%}.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;padding-bottom:25px;border-bottom:1px solid #294055}.topbar small{color:#8da7ba}.topbar form button{border:1px solid #3a5267;background:transparent;color:#eaf2f9;border-radius:10px;padding:10px 16px}.eyebrow{color:#81dbc5;text-transform:uppercase;letter-spacing:.16em;font-size:.72rem;font-weight:800}.heading{display:flex;justify-content:space-between;align-items:end;gap:20px;margin:32px 0 20px}h1{font-size:clamp(2rem,3vw,2.9rem);letter-spacing:-.05em;margin:7px 0}h2{font-size:1.15rem;letter-spacing:-.02em;margin:0}.muted{color:#a9bdcc;line-height:1.6}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}.card{background:#10273b;border:1px solid #2b455a;border-radius:18px;padding:22px}.card small{display:block;color:#9cb4c4}.card strong{display:block;margin-top:12px;font-size:2rem}.two{display:grid;grid-template-columns:1fr 1fr;gap:20px}.panel{background:#10273b;border:1px solid #2b455a;border-radius:18px;padding:22px;margin-top:20px;min-width:0}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:14px}.panel-head a{color:#81dbc5;font-size:.86rem}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{padding:13px 12px;text-align:left;border-bottom:1px solid #294358;font-size:.88rem}th{color:#8ea9bc;font-weight:600}tr:last-child td{border-bottom:0}.badge{display:inline-block;padding:5px 9px;border-radius:7px;background:#193e45;color:#9de8d5;font-size:.76rem}.search{display:flex;gap:10px;margin:20px 0}.search input,.login input{background:#0b1b2b;border:1px solid #3e596c;border-radius:10px;color:#fff;padding:12px 14px;min-height:44px}.search input{width:min(400px,100%)}.btn{background:#81dbc5;color:#05212a;border:0;border-radius:10px;padding:12px 18px;font-weight:800}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:18px;color:#9cb4c4}.pager a{border:1px solid #3e596c;border-radius:9px;padding:9px 13px}.login{max-width:450px;margin:12vh auto;background:#10273b;border:1px solid #2b455a;border-radius:22px;padding:35px}.login input{display:block;width:100%;margin:14px 0}.login .btn{width:100%;margin-top:10px}.error{color:#ffc4c4}.status-list{display:grid;gap:12px;margin-top:18px}.status-list div{display:flex;justify-content:space-between;border-bottom:1px solid #294358;padding-bottom:10px}.empty{color:#9cb4c4;padding:25px 0}.fine{font-size:.84rem;color:#8ea9bc;margin-top:30px}@media(max-width:900px){.shell{grid-template-columns:1fr}.sidebar{padding:18px}.side-nav{display:flex;overflow-x:auto}.side-nav a{white-space:nowrap}.side-note,.side-bottom{display:none}.content{padding:18px}.cards{grid-template-columns:repeat(2,1fr)}.two{grid-template-columns:1fr}}@media(max-width:540px){.cards{grid-template-columns:1fr 1fr}.card{padding:16px}.search{flex-wrap:wrap}.search input{width:100%}.heading{display:block}}
</style></head><body>
<?php if (!$ready): ?><div class="login"><span class="eyebrow">Private administration</span><h1>Admin setup needed</h1><p class="muted">Configure a separate admin password hash in the private backend settings before using this panel.</p><a href="/">Return to website</a></div>
<?php elseif (empty($_SESSION['admin'])): ?><div class="login"><span class="eyebrow">FX Signals Live</span><h1>Admin sign in</h1><p class="muted">For platform administrators only. User accounts sign in on the website.</p><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?><form method="post" action="/web/admin.php"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login"><label for="password">Admin password</label><input id="password" name="password" type="password" autocomplete="current-password" required><button class="btn">Sign in</button></form><p class="fine"><a href="/">← Back to website</a></p></div>
<?php else: ?><div class="shell"><aside class="sidebar"><strong class="brand">FX Signals Live</strong><div class="side-note">ADMIN WORKSPACE</div><nav class="side-nav" aria-label="Admin navigation"><a class="<?=$view==='overview'?'active':''?>" href="<?=h(urlFor('overview'))?>">Overview</a><a class="<?=$view==='users'?'active':''?>" href="<?=h(urlFor('users'))?>">Users</a><a class="<?=$view==='signals'?'active':''?>" href="<?=h(urlFor('signals'))?>">Signals</a></nav><div class="side-bottom"><a href="/">View website ↗</a><span>Protected administration</span></div></aside><main class="content"><div class="topbar"><div><strong>Administration</strong><br><small>Read-only data from the existing platform</small></div><form method="post" action="/web/admin.php"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button>Sign out</button></form></div><div class="heading"><div><span class="eyebrow">FX Signals Live</span><h1><?=h(ucfirst($view))?></h1><p class="muted"><?=$view==='overview'?'A snapshot of accounts and published signals':($view==='users'?'Search registered accounts':'Review published signal records')?></p></div></div><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if ($view === 'overview'): ?><div class="cards"><div class="card"><small>Registered users</small><strong><?=h($userCount)?></strong></div><div class="card"><small>Joined in 30 days</small><strong><?=h($recentCount)?></strong></div><div class="card"><small>All signals</small><strong><?=h($signalCount)?></strong></div><div class="card"><small>Active signals</small><strong><?=h($activeCount)?></strong></div></div><div class="two"><section class="panel"><div class="panel-head"><h2>Recent users</h2><a href="<?=h(urlFor('users'))?>">View all →</a></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Joined</th></tr></thead><tbody><?php foreach ($users as $u): ?><tr><td><?=h($u['name'])?></td><td><?=h($u['email'])?></td><td><?=h($u['created_at'])?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$users): ?><p class="empty">No accounts to display.</p><?php endif; ?></section><section class="panel"><div class="panel-head"><h2>Recent signals</h2><a href="<?=h(urlFor('signals'))?>">View all →</a></div><div class="table-wrap"><table><thead><tr><th>Market</th><th>Direction</th><th>Status</th></tr></thead><tbody><?php foreach ($signals as $s): ?><tr><td><?=h($s['market'] ?? $s['pair'])?></td><td><?=h($s['direction'])?></td><td><span class="badge"><?=h($s['status'])?></span></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$signals): ?><p class="empty">No signals to display.</p><?php endif; ?></section></div><section class="panel"><h2>Signal status</h2><div class="status-list"><?php foreach ($statusCounts as $row): ?><div><span><?=h($row['status'])?></span><strong><?=h($row['total'])?></strong></div><?php endforeach; ?></div></section>
<?php else: ?><form class="search" method="get" action="/web/admin.php"><input type="hidden" name="view" value="<?=h($view)?>"><label class="sr-only" for="q">Search <?=$view?></label><input id="q" name="q" value="<?=h($search)?>" placeholder="<?=$view==='users'?'Search name or email':'Search signal ID or status'?>"><button class="btn">Search</button></form><section class="panel"><div class="panel-head"><h2><?=$view==='users'?'Registered users':'Signal records'?></h2><span class="muted"><?=h($totalRows)?> results</span></div><div class="table-wrap"><table><thead><?php if ($view==='users'): ?><tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th></tr><?php else: ?><tr><th>ID</th><th>Market</th><th>Direction</th><th>Status</th><th>Opened</th></tr><?php endif; ?></thead><tbody><?php if ($view==='users'): foreach ($users as $u): ?><tr><td><?=h($u['id'])?></td><td><?=h($u['name'])?></td><td><?=h($u['email'])?></td><td><?=h($u['created_at'])?></td></tr><?php endforeach; else: foreach ($signals as $s): ?><tr><td><?=h($s['id'])?></td><td><?=h($s['market'] ?? $s['pair'])?></td><td><?=h($s['direction'])?></td><td><span class="badge"><?=h($s['status'])?></span></td><td><?=h($s['opened_at'])?></td></tr><?php endforeach; endif; ?></tbody></table></div><?php if (!$users && !$signals): ?><p class="empty">No matching records. Try a different search.</p><?php endif; ?><div class="pager"><span>Page <?=h($pageNumber)?></span><div><?php if ($pageNumber > 1): ?><a href="<?=h(urlFor($view,$search,$pageNumber-1))?>">← Previous</a><?php endif; ?> <?php if ($pageNumber*$limit < $totalRows): ?><a href="<?=h(urlFor($view,$search,$pageNumber+1))?>">Next →</a><?php endif; ?></div></div></section><?php endif; ?><p class="fine">Data is displayed from the existing backend. Signal publishing and account changes remain in their established systems.</p></main></div><?php endif; ?></body></html>
