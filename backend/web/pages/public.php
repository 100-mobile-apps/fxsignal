<?php
declare(strict_types=1);

function page_not_found(): void
{
    http_response_code(404);
    page_start(['title' => 'Page not found · ' . BRAND, 'noindex' => true]);
    public_header();
    echo '<main class="wrap narrow center pad"><h1>Page not found</h1><p class="muted">The page you were looking for does not exist.</p><a class="btn btn-gold" href="/">Go home</a></main>';
    site_footer();
}

/** Recent closed signals (public: closed signals are never locked). */
function recent_closed(int $limit): array
{
    $rows = Db::all("SELECT * FROM signals WHERE status = 'closed' ORDER BY closed_at DESC, id DESC LIMIT $limit");
    return array_map(fn($r) => Signals::format($r), $rows);
}

function org_jsonld(): array
{
    return [
        ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Swiftmynd Technologies Ltd', 'url' => site_url('/'),
         'logo' => site_url('/assets/icon-512.png'), 'email' => 'swalafblog@gmail.com'],
        ['@context' => 'https://schema.org', '@type' => 'MobileApplication', 'name' => BRAND, 'operatingSystem' => 'Android',
         'applicationCategory' => 'FinanceApplication', 'url' => site_url('/'), 'downloadUrl' => play_url(),
         'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
         'publisher' => ['@type' => 'Organization', 'name' => 'Swiftmynd Technologies Ltd']],
    ];
}

const HOME_FAQ = [
    ['Are the forex signals really free?', 'Yes. Forex signals up to 70% strength are free with full entry, stop loss and take profit. Stronger setups, gold (XAU/USD) and crypto are Premium. Free members can also unlock one Premium signal every two weeks.'],
    ['How are results tracked?', 'Every signal is recorded by the server the moment it is published and closed by fixed rules at TP2, TP1, stop loss or after 5 days. Nothing is edited or deleted, so the track record shows wins and losses.'],
    ['Which markets do you cover?', 'EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, USD/CHF, NZD/USD, gold (XAU/USD), Bitcoin and Ethereum on the 4-hour chart.'],
    ['How do I get alerts?', 'Install the Android app for instant push notifications, and turn on email alerts in your account. You choose which markets and which alerts you want.'],
    ['Can I use the website and the app together?', 'Yes. It is the same account: log in with the same email and password on the website and in the app. Premium works on both.'],
    ['Is this financial advice?', 'No. Signals are automated technical analysis for information and education. Trading on margin is risky; only trade with money you can afford to lose.'],
];

function page_home(): void
{
    $sum = Reports::summary();
    $closed90 = Db::all("SELECT pair, pips, closed_at FROM signals WHERE status = 'closed' AND closed_at >= ?", [now(time() - 90 * 86400)]);
    $st = Stats::compute($closed90, (int) $sum['running']);
    $recent = recent_closed(6);
    $live = Db::all("SELECT * FROM signals WHERE status = 'running' ORDER BY opened_at DESC LIMIT 4");
    $prices = price_info();
    $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], HOME_FAQ)];
    page_start([
        'title' => 'Free Forex Signals & Gold Signals with Entry, SL & TP · ' . BRAND,
        'description' => 'Free forex signals and gold (XAU/USD) signals with entry, stop loss and take profit. Every result tracked openly, daily/weekly/monthly reports, alerts and lot size calculators.',
        'canonical' => '/',
        'jsonld' => array_merge(org_jsonld(), [$faq]),
    ], 'home');
    public_header('/');
    ?>
<main>
<section class="hero"><div class="wrap hero-grid">
  <div>
    <span class="eyebrow">Forex · Gold · Crypto signals</span>
    <h1>Free forex signals with <span class="gold">entry, stop loss &amp; take profit</span></h1>
    <p class="lead">Clear trade plans on the major pairs, gold and crypto, published by a rules-based engine and tracked in public, win or loss. Use it on the web or get instant alerts in the Android app.</p>
    <div class="cta-row">
      <a class="btn btn-gold btn-lg" href="/register">Start free</a>
      <a class="playbadge" href="<?= e(play_url(referral_cookie() ?: null)) ?>" rel="noopener"><?= icon('play') ?><span><small>Get it on</small>Google Play</span></a>
    </div>
    <ul class="ticks"><li>No card needed</li><li>Same account on web and app</li><li>Cancel Premium anytime</li></ul>
  </div>
  <div class="card hero-card">
    <div class="row-between"><span class="muted small">Track record · all time</span><span class="pill pill-live"><i class="dot"></i> <?= (int) $sum['running'] ?> live</span></div>
    <div class="kpis">
      <div><b><?= e(number_format((float) $sum['all']['win_rate'], 1)) ?>%</b><span>Win rate</span></div>
      <div><b class="<?= pip_class($sum['all']['pips']) ?>"><?= e(fmt_pips($sum['all']['pips'])) ?></b><span>Pips</span></div>
      <div><b><?= number_format((int) $sum['all']['closed']) ?></b><span>Signals closed</span></div>
    </div>
    <div class="muted small" style="margin:6px 0 4px">Growth in pips, last 90 days</div>
    <?= equity_chart($st['equity'], 170) ?>
    <a class="link small" href="/results">See every result →</a>
  </div>
</div></section>

<?php if ($live): ?>
<section class="wrap section">
  <div class="sec-head"><h2>Live signals right now</h2><a class="link" href="/register">Sign up free to see the levels →</a></div>
  <div class="grid4">
  <?php foreach ($live as $r): $s = Signals::format($r); $buy = $s['direction'] === 'BUY'; ?>
    <a class="card mini-sig" href="/register"><div class="sig-top"><?= flag($s['pair']) ?><div class="sig-name"><b><?= e($s['pair']) ?></b> <span class="dir <?= $buy ? 'buy' : 'sell' ?>"><?= e($s['direction']) ?></span><small>Opened <?= e(ago($s['opened_at'])) ?></small></div></div>
      <div class="sig-levels"><div><small>Entry</small><b class="blur">1.08350</b></div><div><small>SL</small><b class="blur">1.07990</b></div><div><small>TP</small><b class="blur">1.08950</b></div></div>
      <div class="muted small">⚡ <?= (int) $s['confidence'] ?>% strength</div></a>
  <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<section class="wrap section">
  <div class="sec-head"><h2>Latest results</h2><a class="link" href="/results">Full track record →</a></div>
  <?= results_table($recent) ?>
</section>

<section class="wrap section">
  <h2 class="center">Everything you need to follow a signal</h2>
  <div class="grid3 features">
    <div class="card"><h3>Full trade plan</h3><p>Entry, stop loss and two take-profit targets for every signal, with the reasons and a strength score.</p></div>
    <div class="card"><h3>Chart for every signal</h3><p>See the setup on the candlestick chart with the risk and reward zones marked.</p></div>
    <div class="card"><h3>Every result tracked</h3><p>Win rate, pips, profit factor and growth curve, plus daily, weekly and monthly reports.</p></div>
    <div class="card"><h3>Gold &amp; crypto</h3><p>XAU/USD, Bitcoin and Ethereum signals alongside the seven major forex pairs.</p></div>
    <div class="card"><h3>Alerts your way</h3><p>Push notifications in the app and email alerts for new signals, TP/SL hits and reports.</p></div>
    <div class="card"><h3>Risk tools</h3><p>Lot size, pip value and risk-reward calculators so one loss never hurts too much.</p></div>
  </div>
</section>

<section class="wrap section">
  <h2 class="center">How it works</h2>
  <div class="grid3 steps">
    <div><span class="stepn">1</span><h3>Create a free account</h3><p class="muted">Sign up on the website or in the app. It is the same account everywhere.</p></div>
    <div><span class="stepn">2</span><h3>Pick your markets</h3><p class="muted">Choose the pairs you trade and the alerts you want.</p></div>
    <div><span class="stepn">3</span><h3>Follow the plan</h3><p class="muted">Enter at the entry price, set the stop loss and targets, and size the trade with the calculator.</p></div>
  </div>
</section>

<section class="wrap section">
  <div class="card premium-band">
    <div><h2>Go Premium</h2><p class="muted">Unlock every live signal, including gold, crypto and the strongest forex setups, plus instant alerts and the trading bot in the app.</p></div>
    <div class="premium-prices">
      <?php if ($prices['card_ready']): ?>
        <div><b><?= e(money_local((float) $prices['web']['monthly_amount'], $prices['web']['currency'])) ?></b><span>/ month</span></div>
        <div><b><?= e(money_local((float) $prices['web']['yearly_amount'], $prices['web']['currency'])) ?></b><span>/ year</span></div>
      <?php else: ?>
        <div><b><?= e($prices['play']['launch_monthly'] ?: $prices['play']['monthly']) ?></b><span>/ month in the app</span></div>
      <?php endif ?>
      <a class="btn btn-gold" href="/pricing">See plans</a>
    </div>
  </div>
</section>

<section class="wrap section">
  <div class="card affiliate-band"><div><h2>Earn 30% on every subscription you refer</h2><p class="muted">Share your link, earn recurring commission on every Premium payment, and add your own broker link so the people you refer open accounts through you.</p></div><a class="btn btn-ghost" href="/affiliates">Join the affiliate programme</a></div>
</section>

<section class="wrap section narrow">
  <h2 class="center">Questions</h2>
  <?php foreach (HOME_FAQ as [$q, $a]): ?><details class="faq"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach ?>
</section>
</main>
<?php
    site_footer();
}

function results_table(array $signals, bool $link = false): string
{
    if (!$signals) return '<div class="card empty">No closed signals yet. The first results appear here as soon as a signal hits its target or stop loss.</div>';
    $h = '<div class="card tablecard"><table class="tbl"><thead><tr><th>Market</th><th>Direction</th><th class="hide-s">Entry</th><th class="hide-s">Close</th><th>Result</th><th class="r">Pips</th><th class="hide-s r">Closed</th></tr></thead><tbody>';
    foreach ($signals as $s) {
        [$label, $cls] = result_label($s);
        $pairCell = flag($s['pair']) . ' <b>' . e($s['pair']) . '</b>';
        if ($link) $pairCell = '<a href="/app/signal/' . (int) $s['id'] . '">' . $pairCell . '</a>';
        $h .= '<tr><td>' . $pairCell . '</td><td><span class="dir ' . ($s['direction'] === 'BUY' ? 'buy' : 'sell') . '">' . e($s['direction']) . '</span></td>'
            . '<td class="hide-s mono">' . e(fmt_price($s['pair'], $s['entry'])) . '</td><td class="hide-s mono">' . e(fmt_price($s['pair'], $s['close_price'])) . '</td>'
            . '<td><span class="status st-' . e($cls) . '">' . e($label) . '</span></td><td class="r mono ' . pip_class($s['pips']) . '"><b>' . e(fmt_pips($s['pips'])) . '</b></td>'
            . '<td class="hide-s r muted">' . e(date_local($s['closed_at'], 'j M, H:i')) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}

/** Stats block shared by the public Results page and the member Results page. */
function stats_block(array $st): string
{
    ob_start(); ?>
<div class="statgrid">
  <div class="card stat-ring"><?= ring((float) $st['win_rate']) ?><div><div class="muted small">Total pips</div><div class="big <?= pip_class($st['total_pips']) ?>"><?= e(fmt_pips($st['total_pips'])) ?></div>
    <div class="small"><span class="up">● <?= (int) $st['wins'] ?> wins</span> &nbsp; <span class="down">● <?= (int) $st['losses'] ?> losses</span></div></div></div>
  <div class="card kpi"><span>Signals closed</span><b><?= number_format((int) $st['total']) ?></b></div>
  <div class="card kpi"><span>Profit factor</span><b class="gold"><?= $st['profit_factor'] === null ? '—' : e(number_format((float) $st['profit_factor'], 2)) ?></b></div>
  <div class="card kpi"><span>Average win</span><b class="up"><?= e(fmt_pips($st['avg_win_pips'])) ?></b></div>
  <div class="card kpi"><span>Average loss</span><b class="down"><?= e(fmt_pips(-(float) $st['avg_loss_pips'])) ?></b></div>
  <div class="card kpi"><span>Best market</span><b><?= e($st['best_pair'] ?? '—') ?></b></div>
  <div class="card kpi"><span>Running now</span><b><?= (int) $st['running'] ?></b></div>
</div>
<div class="grid2">
  <div class="card"><h3>Growth in pips</h3><p class="muted small">Cumulative result of every closed signal</p><?= equity_chart($st['equity']) ?></div>
  <div class="card"><h3>Pips by month</h3><p class="muted small">Last 12 months</p><?= month_bars($st['monthly']) ?></div>
</div>
<div class="card tablecard"><h3 class="pad-h">By market</h3><table class="tbl"><thead><tr><th>Market</th><th class="r">Signals</th><th class="r">Win rate</th><th class="r">Pips</th></tr></thead><tbody>
<?php foreach ($st['by_pair'] as $p): ?><tr><td><?= flag($p['pair']) ?> <b><?= e($p['pair']) ?></b></td><td class="r"><?= (int) $p['total'] ?></td><td class="r"><?= e(number_format((float) $p['win_rate'], 1)) ?>%</td><td class="r mono <?= pip_class($p['pips']) ?>"><b><?= e(fmt_pips($p['pips'])) ?></b></td></tr><?php endforeach ?>
<?php if (!$st['by_pair']): ?><tr><td colspan="4" class="muted">No closed signals in this period.</td></tr><?php endif ?>
</tbody></table></div>
<?php
    return (string) ob_get_clean();
}

function period_tabs(int $days, string $base): string
{
    $h = '<div class="seg">';
    foreach ([7 => '7D', 30 => '30D', 90 => '90D', 0 => 'All'] as $d => $label) {
        $h .= '<a class="' . ($d === $days ? 'on' : '') . '" href="' . e($base . '?days=' . $d) . '">' . $label . '</a>';
    }
    return $h . '</div>';
}

function stats_for(int $days): array
{
    $params = [];
    $where = "status = 'closed'";
    if ($days > 0) { $where .= ' AND closed_at >= ?'; $params[] = now(time() - $days * 86400); }
    $closed = Db::all("SELECT pair, pips, closed_at FROM signals WHERE $where", $params);
    $running = (int) Db::one("SELECT COUNT(*) AS n FROM signals WHERE status = 'running'")['n'];
    return Stats::compute($closed, $running);
}

function page_results_public(): void
{
    $days = (int) ($_GET['days'] ?? 90);
    if (!in_array($days, [7, 30, 90, 0], true)) $days = 90;
    $st = stats_for($days);
    page_start([
        'title' => 'Forex Signals Results & Track Record (Win Rate, Pips) · ' . BRAND,
        'description' => 'The full track record of our forex and gold signals: win rate, total pips, profit factor, growth curve, monthly results and every closed trade. Wins and losses, nothing deleted.',
        'canonical' => '/results',
    ]);
    public_header('/results');
    ?>
<main class="wrap section">
  <div class="pagehead"><div><h1>Track record</h1><p class="muted">Every signal is recorded automatically and closed by fixed rules. Wins and losses, nothing edited or deleted.</p></div><?= period_tabs($days, '/results') ?></div>
  <?= stats_block($st) ?>
  <h2>Latest closed signals</h2>
  <?= results_table(recent_closed(30)) ?>
  <p class="muted small">Results are in pips before spread and commission. Past results do not guarantee future results. <a class="link" href="/how-it-works">How results are recorded</a></p>
</main>
<?php
    site_footer();
}

function page_how(): void
{
    page_start([
        'title' => 'How Our Forex & Gold Signals Work (Strategy and Rules) · ' . BRAND,
        'description' => 'The rules behind every signal: markets and timeframe, the range reversal strategy for currency pairs, trend pullbacks for gold, and how results are recorded.',
        'canonical' => '/how-it-works',
    ]);
    public_header('/how-it-works');
    $sections = [
        ['Markets and timeframe', 'The engine scans EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, USD/CHF, NZD/USD, gold (XAU/USD), Bitcoin and Ethereum on the 4-hour chart, using only fully closed candles so signals never repaint.'],
        ['Currency pairs: range reversal', 'In a calm, ranging market (ADX below 20), a candle that closes outside the 20-period Bollinger Band (2 standard deviations) with RSI at 35 or lower (buy) or 65 or higher (sell) signals a likely move back toward the average. Stop loss is 2 × ATR(14) from entry, TP1 is 1 × ATR and TP2 is 2 × ATR.'],
        ['Gold: trend pullback', 'Gold tends to trend, so a signal needs the trend to line up (price against the 200 EMA, 20 EMA against 50 EMA, MACD and RSI, scored out of 100, 65% or more required) and price must have pulled back to within 1 × ATR of the 20 EMA. Stop loss is 1.5 × ATR, TP1 1.5 × ATR and TP2 3 × ATR.'],
        ['How results are recorded', 'Every published signal is tracked automatically on the server. If the stop loss and a target are touched in the same candle, the stop loss is counted first. After TP1 the stop moves to entry; the trade then closes at TP2 (full win) or back at entry (TP1 win). Open signals close at market after 5 days. Signals are never edited or deleted after publishing.'],
        ['Strength score', 'Each signal shows how many conditions lined up, as a percentage. Forex signals up to 70% strength are free; stronger forex setups, gold and crypto are Premium.'],
        ['Pips', 'Results are shown in pips before spread and commission. 1 pip = 0.0001 (0.01 for JPY pairs, 0.10 for gold and Ethereum, 1.00 for Bitcoin).'],
    ];
    echo '<main class="wrap narrow section"><h1>How the signals work</h1><p class="lead muted">A rules-based engine, not guesswork. Here is exactly what it does.</p>';
    foreach ($sections as [$t, $b]) echo '<div class="card prose"><h2>' . e($t) . '</h2><p>' . e($b) . '</p></div>';
    echo '<div class="center pad"><a class="btn btn-gold btn-lg" href="/register">Start free</a></div></main>';
    site_footer();
}

function page_pricing(): void
{
    $p = price_info();
    page_start([
        'title' => 'Pricing: Free Forex Signals and Premium · ' . BRAND,
        'description' => 'Free forex signals with full levels, or Premium for every live signal including gold and crypto. Pay by card, bank transfer or USSD on the website, or in the Android app.',
        'canonical' => '/pricing',
    ]);
    public_header('/pricing');
    $cur = $p['web']['currency'];
    ?>
<main class="wrap section">
  <div class="center"><h1>Simple pricing</h1><p class="lead muted">Start free. Upgrade when you want every signal.</p></div>
  <div class="grid3 plans">
    <div class="card plan"><h3>Free</h3><div class="price"><b>$0</b><span>forever</span></div><ul class="ticks">
      <li>Forex signals up to 70% strength with full levels</li><li>One Premium signal unlocked every two weeks</li><li>Full track record and reports</li><li>Calculators and market sessions</li><li>Email alerts</li></ul>
      <a class="btn btn-ghost" href="/register">Create free account</a></div>
    <div class="card plan plan-hot"><span class="ribbon">Best value</span><h3>Premium yearly</h3>
      <div class="price"><b><?= e($p['card_ready'] ? money_local((float) $p['web']['yearly_amount'], $cur) : ($p['play']['launch_yearly'] ?: $p['play']['yearly'])) ?></b><span>/ year</span></div>
      <ul class="ticks"><li>Every live signal unlocked</li><li>Gold (XAU/USD), Bitcoin and Ethereum</li><li>Strongest forex setups</li><li>Instant push alerts with full levels</li><li>Trading bot in the app</li></ul>
      <a class="btn btn-gold" href="/app/premium?plan=yearly">Get yearly</a></div>
    <div class="card plan"><h3>Premium monthly</h3>
      <div class="price"><b><?= e($p['card_ready'] ? money_local((float) $p['web']['monthly_amount'], $cur) : ($p['play']['launch_monthly'] ?: $p['play']['monthly'])) ?></b><span>/ month</span></div>
      <ul class="ticks"><li>Everything in Premium</li><li>Cancel anytime</li><li>Works on the website and the app</li></ul>
      <a class="btn btn-ghost" href="/app/premium?plan=monthly">Get monthly</a></div>
  </div>
  <p class="center muted small">On the website you pay with Paystack (card, bank transfer or USSD). In the Android app you pay with Google Play. Either way, Premium unlocks on both.</p>
</main>
<?php
    site_footer();
}

function page_affiliates_public(): void
{
    $s = Affiliates::settings();
    page_start([
        'title' => 'Forex Signals Affiliate Programme: Earn ' . (int) $s['rate'] . '% Recurring · ' . BRAND,
        'description' => 'Share free forex signals and earn ' . (int) $s['rate'] . '% recurring commission on every Premium payment you refer, plus your own broker referral link.',
        'canonical' => '/affiliates',
    ]);
    public_header('/affiliates');
    ?>
<main class="wrap section">
  <div class="center narrow"><span class="eyebrow">Affiliate programme</span><h1>Earn <span class="gold"><?= (int) $s['rate'] ?>% recurring</span> sharing free forex signals</h1>
    <p class="lead muted">People join for the free signals. When they upgrade, you earn <?= (int) $s['rate'] ?>% of every payment for as long as they stay subscribed.</p>
    <a class="btn btn-gold btn-lg" href="/app/affiliate">Join now, it's free</a></div>
  <div class="grid3 steps">
    <div><span class="stepn">1</span><h3>Get your link</h3><p class="muted">Join from your account and get a personal link and code for the website and the Android app.</p></div>
    <div><span class="stepn">2</span><h3>Share it</h3><p class="muted">WhatsApp groups, Telegram, YouTube, TikTok, your blog. Visitors are remembered for <?= (int) $s['cookie_days'] ?> days.</p></div>
    <div><span class="stepn">3</span><h3>Get paid</h3><p class="muted">Earn on website and Google Play payments. Withdraw from $<?= e(number_format((float) $s['min_payout'], 0)) ?> by bank transfer, USDT or PayPal.</p></div>
  </div>
  <div class="grid2">
    <div class="card"><h3>Your own broker link</h3><p class="muted">Already an introducing broker (IB) or partner with a broker? Add your broker referral link. After a quick review, everyone you refer sees it on the "Open a broker account" button, so the broker pays you directly.</p></div>
    <div class="card"><h3>Real-time dashboard</h3><p class="muted">See visits, sign-ups, Premium referrals, pending and available earnings, and every payout.</p></div>
  </div>
  <div class="narrow">
    <details class="faq"><summary>When do commissions become available?</summary><p>After a <?= (int) $s['hold_days'] ?>-day refund window. Refunded payments don't earn commission.</p></details>
    <details class="faq"><summary>Do I earn on renewals?</summary><p>Yes, <?= (int) $s['rate'] ?>% of every payment the people you referred make, monthly or yearly, for as long as they stay subscribed.</p></details>
    <details class="faq"><summary>Does it work for app installs?</summary><p>Yes. Your Google Play link carries your code, and new users can also type your code when they sign up in the app.</p></details>
    <details class="faq"><summary>Rules</summary><p>No spam, no fake reviews, no paid ads on our brand name, no promises of profits, and you can't refer yourself. See the <a class="link" href="/affiliate-terms">affiliate terms</a>.</p></details>
  </div>
</main>
<?php
    site_footer();
}

function page_tools_public(): void
{
    page_start([
        'title' => 'Free Lot Size Calculator & Pip Value Calculator (Forex, Gold) · ' . BRAND,
        'description' => 'Free forex lot size calculator, pip value calculator and risk-reward calculator for EUR/USD, GBP/USD, USD/JPY, gold (XAU/USD), Bitcoin and more.',
        'canonical' => '/tools',
    ]);
    public_header('/tools');
    echo '<main class="wrap section"><h1>Free trading calculators</h1><p class="muted">Size every trade so one loss never hurts. Values are for a USD account.</p>';
    echo tools_html();
    echo '</main>';
    site_footer();
}

/** Lot size, pip value and risk-reward calculators (run in the browser, see app.js). */
function tools_html(): string
{
    $prices = [];
    try {
        foreach (Db::all('SELECT symbol, price FROM prices') as $r) $prices[$r['symbol']] = (float) $r['price'];
    } catch (Throwable $e) {}
    $pairs = (array) config('pairs', []);
    $opts = '';
    foreach ($pairs as $p) $opts .= '<option value="' . e($p) . '" data-pip="' . e((string) pip_size($p)) . '" data-price="' . e((string) ($prices[$p] ?? '')) . '">' . e($p) . '</option>';
    ob_start(); ?>
<div class="grid3 calc">
  <form class="card" data-calc="lot" onsubmit="return false">
    <h3>Lot size calculator</h3>
    <label>Market<select name="pair"><?= $opts ?></select></label>
    <label>Account balance (USD)<input name="balance" type="number" inputmode="decimal" value="1000" min="0" step="any"></label>
    <div class="two"><label>Risk %<input name="risk" type="number" inputmode="decimal" value="1" min="0" step="any"></label>
    <label>Stop loss (pips)<input name="sl" type="number" inputmode="decimal" value="30" min="0" step="any"></label></div>
    <div class="result"><span>Position size</span><b data-out="lots">—</b></div>
    <div class="kv"><span>Amount at risk</span><b data-out="risk" class="down">—</b></div>
    <div class="kv"><span>Pip value (1 lot)</span><b data-out="pv">—</b></div>
  </form>
  <form class="card" data-calc="pip" onsubmit="return false">
    <h3>Pip value calculator</h3>
    <label>Market<select name="pair"><?= $opts ?></select></label>
    <label>Lot size<input name="lots" type="number" inputmode="decimal" value="0.10" min="0" step="any"></label>
    <label>Pips<input name="pips" type="number" inputmode="decimal" value="10" step="any"></label>
    <div class="result"><span>Profit / loss</span><b data-out="pl">—</b></div>
    <div class="kv"><span>Pip value (your size)</span><b data-out="pv">—</b></div>
  </form>
  <form class="card" data-calc="rr" onsubmit="return false">
    <h3>Risk-reward calculator</h3>
    <label>Market<select name="pair"><?= $opts ?></select></label>
    <label>Entry<input name="entry" type="number" inputmode="decimal" step="any" placeholder="1.08500"></label>
    <div class="two"><label>Stop loss<input name="sl" type="number" inputmode="decimal" step="any" placeholder="1.08200"></label>
    <label>Take profit<input name="tp" type="number" inputmode="decimal" step="any" placeholder="1.09100"></label></div>
    <div class="result"><span>Risk : reward</span><b data-out="rr">—</b></div>
    <div class="kv"><span>Risk</span><b data-out="riskp" class="down">—</b></div>
    <div class="kv"><span>Reward</span><b data-out="rewp" class="up">—</b></div>
  </form>
</div>
<p class="muted small">Most professionals risk 0.5–2% of their account per trade. Round the lot size down to what your broker allows; contract sizes can differ by broker.</p>
<?php
    return (string) ob_get_clean();
}

function page_legal(array $m): void
{
    require_once __DIR__ . '/legal.php';
    [$title, $sections] = legal_doc($m[1]);
    page_start(['title' => "$title · " . BRAND, 'description' => "$title for " . BRAND . '.', 'canonical' => '/' . $m[1]]);
    public_header();
    echo '<main class="wrap narrow section prose"><h1>' . e($title) . '</h1><p class="muted small">Last updated: November 2026</p>';
    foreach ($sections as [$h, $p]) echo '<h2>' . e($h) . '</h2><p>' . e($p) . '</p>';
    echo '</main>';
    site_footer();
}

function page_sitemap(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $urls = ['/' => '1.0', '/results' => '0.9', '/how-it-works' => '0.7', '/pricing' => '0.8', '/tools' => '0.8', '/affiliates' => '0.7',
        '/register' => '0.6', '/login' => '0.4', '/terms' => '0.2', '/privacy' => '0.2', '/risk' => '0.2', '/affiliate-terms' => '0.2'];
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u => $pri) echo '<url><loc>' . e(site_url($u)) . '</loc><priority>' . $pri . '</priority></url>';
    echo '</urlset>';
}

/** /r/CODE → remember the referrer and go to the home page. */
function page_ref(array $m): void
{
    $_GET['ref'] = $m['code'];
    capture_referral();
    redirect('/');
}

/** Tracked "Open a broker account" link. */
function page_go_broker(): void
{
    $u = current_user();
    $b = $u ? Affiliates::brokerFor($u) : null;
    if (!$b && ($code = referral_cookie()) && ($a = Affiliates::activeByCode($code)) && $a['broker_status'] === 'approved' && $a['broker_url']) {
        $b = ['name' => $a['broker_name'], 'url' => $a['broker_url'], 'affiliate_id' => (int) $a['user_id']];
    }
    $b = $b ?? Affiliates::defaultBroker();
    if (!$b) redirect('/');
    if (!empty($b['affiliate_id']) && ($a = Affiliates::forUser((int) $b['affiliate_id']))) Affiliates::click($a, 'broker', Http::ip());
    header('Location: ' . $b['url'], true, 302);
    exit;
}
