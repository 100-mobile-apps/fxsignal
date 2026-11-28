<?php
// Shared "Trading bot" page for the 100apps signal sites (FX, Indices, NFP).
// The apps no longer run bots; their Bot buttons open <site>/bot. This page sells
// the bot and collects requests: each one is saved (SQLite outside the web root)
// and emailed to the admin, and the visitor can continue on WhatsApp.
declare(strict_types=1);

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$SITES = [
    'fx' => [
        'hosts' => ['forexsignal.100apkgoals.com', 'forexsignal.bulksend.top'],
        'name' => 'Forex Signals Live', 'accent' => '#F5B83D',
        'what' => 'forex, gold and crypto signals',
        'brokers' => ['MT5 (any broker, via our Expert Advisor)', 'Deriv', 'Exness (MT5)', 'Other MT4/MT5 broker'],
        'data' => '/var/www/100apps/fxsignal/.bot_data',
    ],
    'indices' => [
        'hosts' => ['indicesignals.100apkgoals.com', 'indicesgnals.bulksend.top'],
        'name' => 'Indices Trading Signals', 'accent' => '#4F8CFF',
        'what' => 'US30, NAS100, SPX500, GER40, UK100 and JP225 signals',
        'brokers' => ['OANDA', 'MT5 (any broker, via our Expert Advisor)', 'Other MT4/MT5 broker'],
        'data' => '/var/www/100apps/indices/bot_data',
    ],
    'nfp' => [
        'hosts' => ['nfpnewssignal.100apkgoals.com', 'nfpnewssignal.bulksend.top'],
        'name' => 'NFP News Signals', 'accent' => '#16C784',
        'what' => 'NFP, CPI and FOMC news signals',
        'brokers' => ['OANDA', 'Capital.com', 'Deriv', 'MT4/MT5 (any broker)'],
        'data' => '/var/www/100apps/nfp/bot_data',
    ],
];
$key = 'fx';
foreach ($SITES as $k => $s) {
    if (in_array(preg_replace('/^www\./', '', $host), $s['hosts'], true)) { $key = $k; break; }
}
$S = $SITES[$key];
$ADMIN = 'swalafblog@gmail.com';
$WA = '2347011401387';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

session_start();
if (empty($_SESSION['bot_csrf'])) $_SESSION['bot_csrf'] = bin2hex(random_bytes(16));
$done = false; $err = '';
$src = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['src'] ?? $_POST['src'] ?? 'web'));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 80));
    $email = strtolower(trim(mb_substr((string)($_POST['email'] ?? ''), 0, 190)));
    $phone = preg_replace('/[^0-9+ ]/', '', mb_substr((string)($_POST['phone'] ?? ''), 0, 20));
    $broker = in_array($_POST['broker'] ?? '', $S['brokers'], true) ? $_POST['broker'] : 'Not sure yet';
    $acct = ($_POST['account'] ?? '') === 'live' ? 'live' : 'demo';
    if (!hash_equals($_SESSION['bot_csrf'], (string)($_POST['csrf'] ?? '')) || ($_POST['website'] ?? '') !== '') {
        $err = 'Please reload the page and try again.';
    } elseif (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter your name and a valid email address.';
    } else {
        try {
            if (!is_dir($S['data'])) @mkdir($S['data'], 0770, true);
            $db = new PDO('sqlite:' . $S['data'] . '/leads.sqlite');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE IF NOT EXISTS bot_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, phone TEXT,
                broker TEXT, account TEXT, src TEXT, ip TEXT, created_at TEXT)');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $c = $db->prepare("SELECT COUNT(*) FROM bot_leads WHERE ip = ? AND created_at > datetime('now','-1 hour')");
            $c->execute([$ip]);
            if ((int)$c->fetchColumn() >= 5) throw new RuntimeException('rate');
            $db->prepare("INSERT INTO bot_leads (name,email,phone,broker,account,src,ip,created_at) VALUES (?,?,?,?,?,?,?,datetime('now'))")
               ->execute([$name, $email, $phone, $broker, $acct, $src, $ip]);
            $total = (int)$db->query('SELECT COUNT(*) FROM bot_leads')->fetchColumn();
            @mail($ADMIN, "[{$S['name']}] Bot request from $name",
                "Name: $name\nEmail: $email\nWhatsApp/phone: " . ($phone ?: '-') . "\nBroker: $broker\nAccount: $acct\nCame from: $src\n\nTotal bot requests for this app: $total\n",
                "From: {$S['name']} <noreply@100apkgoals.com>\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8");
            $done = true;
        } catch (Throwable $e) {
            $err = $e->getMessage() === 'rate' ? 'Too many requests. Please try again later.' : 'Something went wrong. Please message us on WhatsApp instead.';
            if ($e->getMessage() !== 'rate') error_log('[bot page] ' . $e->getMessage());
        }
    }
}
$waText = rawurlencode("Hi, I want the {$S['name']} trading bot." . (isset($broker) ? " Broker: $broker." : ''));
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trading bot · <?= $h($S['name']) ?></title>
<meta name="description" content="Let the <?= $h($S['name']) ?> bot trade <?= $h($S['what']) ?> on your own broker account, with your own risk rules.">
<style>
:root{--bg:#0A0E14;--card:#121821;--line:#263041;--text:#E8EDF4;--mut:#8A96A8;--acc:<?= $h($S['accent']) ?>}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--acc)}.w{max-width:1040px;margin:0 auto;padding:0 16px}
header{display:flex;justify-content:space-between;align-items:center;padding:18px 0}header b{font-size:17px}
.hero{display:grid;grid-template-columns:1.1fr .9fr;gap:32px;align-items:start;padding:28px 0 40px}
h1{font-size:clamp(30px,5vw,46px);line-height:1.1;margin:0 0 14px}h1 span{color:var(--acc)}
.lead{color:var(--mut);font-size:18px;margin:0 0 22px}.pill{display:inline-block;border:1px solid var(--line);border-radius:99px;padding:4px 12px;color:var(--mut);font-size:13px;margin-bottom:16px}
.ticks{list-style:none;padding:0;margin:0}.ticks li{padding:6px 0 6px 28px;position:relative}.ticks li:before{content:"✓";position:absolute;left:0;color:var(--acc);font-weight:800}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px}
label{display:block;font-size:13px;color:var(--mut);margin:12px 0 6px}input,select{width:100%;padding:12px;border-radius:10px;border:1px solid var(--line);background:#0E131B;color:var(--text);font-size:15px}
.seg{display:flex;gap:8px}.seg label{flex:1;margin:0;border:1px solid var(--line);border-radius:10px;padding:10px;text-align:center;color:var(--text);cursor:pointer}.seg input{display:none}.seg input:checked+span{color:var(--acc);font-weight:700}
.btn{display:block;width:100%;margin-top:18px;padding:14px;border:0;border-radius:12px;background:var(--acc);color:#111;font-weight:800;font-size:16px;cursor:pointer;text-align:center;text-decoration:none}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text);margin-top:10px}
.err{background:#3a1518;border:1px solid #EA3943;border-radius:10px;padding:10px;margin-bottom:8px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:8px 0 40px}.grid h3{margin:6px 0;font-size:17px}.grid p{margin:0;color:var(--mut)}
.n{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:50%;background:var(--acc);color:#111;font-weight:800}
h2{font-size:26px;margin:0 0 14px}.small{font-size:13px;color:var(--mut)}footer{border-top:1px solid var(--line);padding:22px 0 40px;margin-top:20px}
.hp{position:absolute;left:-9999px}
@media(max-width:820px){.hero{grid-template-columns:1fr}.grid{grid-template-columns:1fr}}
</style></head><body>
<div class="w">
<header><b><?= $h($S['name']) ?></b><a href="/">Home</a></header>
<section class="hero">
  <div>
    <span class="pill">Trading bot · web only</span>
    <h1>Stop watching charts. <span>Let the bot trade the signals.</span></h1>
    <p class="lead">The <?= $h($S['name']) ?> bot places our <?= $h($S['what']) ?> on your own broker account, with the risk rules you choose.</p>
    <ul class="ticks">
      <li>Every signal placed for you, with its stop loss and take profit</li>
      <li>Your rules: risk per trade, daily loss limit, maximum open trades</li>
      <li>Your broker, your money: funds never leave your account</li>
      <li>Start on a free demo account and go live when you're ready</li>
      <li>We help you set it up, step by step</li>
    </ul>
  </div>
  <div class="card" id="get">
  <?php if ($done): ?>
    <h2>You're on the list 🎉</h2>
    <p>We'll email <b><?= $h($email) ?></b> with your setup steps. For the fastest setup, message us on WhatsApp now.</p>
    <a class="btn" href="https://wa.me/<?= $WA ?>?text=<?= $waText ?>">Continue on WhatsApp</a>
    <a class="btn ghost" href="/">Back to the signals</a>
  <?php else: ?>
    <h2>Get the bot</h2>
    <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
    <form method="post" action="/bot#get">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['bot_csrf']) ?>"><input type="hidden" name="src" value="<?= $h($src) ?>">
      <input class="hp" name="website" tabindex="-1" autocomplete="off">
      <label>Your name</label><input name="name" required maxlength="80" value="<?= $h($_POST['name'] ?? '') ?>">
      <label>Email</label><input type="email" name="email" required maxlength="190" value="<?= $h($_POST['email'] ?? '') ?>">
      <label>WhatsApp number (optional, for faster setup)</label><input name="phone" inputmode="tel" maxlength="20" value="<?= $h($_POST['phone'] ?? '') ?>">
      <label>Broker</label><select name="broker"><?php foreach ($S['brokers'] as $b): ?><option><?= $h($b) ?></option><?php endforeach; ?><option>Not sure yet</option></select>
      <label>Start with</label>
      <div class="seg"><label><input type="radio" name="account" value="demo" checked><span>Demo account</span></label><label><input type="radio" name="account" value="live"><span>Live account</span></label></div>
      <button class="btn">Get the trading bot</button>
      <a class="btn ghost" href="https://wa.me/<?= $WA ?>?text=<?= $waText ?>">Or chat on WhatsApp</a>
      <p class="small">Free to request. We never ask for your broker password.</p>
    </form>
  <?php endif; ?>
  </div>
</section>
<h2>How it works</h2>
<div class="grid">
  <div class="card"><span class="n">1</span><h3>Request the bot</h3><p>Tell us your broker and whether you want to start on demo or live.</p></div>
  <div class="card"><span class="n">2</span><h3>Connect your account</h3><p>We send you the setup: an API connection or our MT4/MT5 Expert Advisor.</p></div>
  <div class="card"><span class="n">3</span><h3>Set your rules</h3><p>Choose your risk per trade and limits. The bot trades every matching signal.</p></div>
</div>
<footer class="small">Trading carries a high risk of loss, and with leverage you can lose more than you expect. The bot follows fixed rules and many trades lose; past results do not predict future results. This is not financial advice. © <?= gmdate('Y') ?> <?= $h($S['name']) ?></footer>
</div>
</body></html>
