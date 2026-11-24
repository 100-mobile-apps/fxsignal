<?php
declare(strict_types=1);

/* Member area: signals, signal detail, history, results, reports, markets, tools, account. */

function all_pairs(): array { return (array) config('pairs', []); }

function page_signals(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user();
        $pairs = array_values(array_intersect(all_pairs(), (array) ($_POST['pairs'] ?? [])));
        [$status, $data] = api('PUT', '/me/signal-pairs', [], ['pairs' => $pairs ?: []]);
        $status < 400 ? flash('success', 'Saved. You will see signals for the markets you picked.') : flash('error', (string) ($data['error'] ?? 'Could not save.'));
        redirect('/app');
    }
    [$u, $pub] = app_start('Signals', '/app');
    $data = api_ok('GET', '/signals', ['status' => 'running']);
    $sum = Reports::summary();
    $mine = $pub['signal_pairs'] ?? null;
    $hour = (int) (new DateTimeImmutable('now', Reports::tz()))->format('G');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    ?>
<div class="pagehead"><div><p class="muted"><?= e($greet) ?></p><h1><?= e(explode(' ', (string) $u['name'])[0]) ?></h1></div>
  <span class="pill pill-live"><i class="dot"></i> <span data-updated>Live</span></span></div>
<div class="card perf">
  <div class="row-between"><span><b class="gold">✓</b> Track record · this month</span><a class="link small" href="/app/results">View results →</a></div>
  <div class="kpis">
    <div><b class="gold"><?= e(number_format((float) $sum['month']['win_rate'], 0)) ?>%</b><span>Win rate</span></div>
    <div><b class="<?= pip_class($sum['month']['pips']) ?>"><?= e(fmt_pips($sum['month']['pips'])) ?></b><span>Pips</span></div>
    <div><b><?= (int) $sum['month']['closed'] ?></b><span>Closed</span></div>
    <div><b><?= (int) $sum['running'] ?></b><span>Active</span></div>
  </div>
</div>
<div class="row-between toolbar">
  <div class="seg"><a class="on" href="/app">Active (<?= count($data['items']) ?>)</a><a href="/app/history">History</a></div>
  <details class="filter"><summary class="btn btn-ghost btn-sm">Markets<?= $mine !== null ? ' (' . count($mine) . ')' : '' ?></summary>
    <form method="post" class="card filterbox"><?= csrf_field() ?><p class="small muted">Show signals for these markets (also used for your alerts):</p>
      <div class="chips"><?php foreach (all_pairs() as $p): ?><label class="chip"><input type="checkbox" name="pairs[]" value="<?= e($p) ?>" <?= $mine === null || in_array($p, $mine, true) ? 'checked' : '' ?>> <?= e($p) ?></label><?php endforeach ?></div>
      <button class="btn btn-gold btn-sm">Save</button></form></details>
</div>
<?php if (!$data['items']): ?>
  <div class="card empty"><h3>No active signals right now</h3><p class="muted">The engine checks the markets every few minutes and publishes a signal as soon as a setup meets the rules. Turn on alerts so you don't miss it.</p>
  <a class="btn btn-ghost btn-sm" href="/app/account#alerts">Alert settings</a> <a class="btn btn-ghost btn-sm" href="/app/history">See recent results</a></div>
<?php else: ?>
  <div class="siggrid"><?php foreach ($data['items'] as $s) echo signal_card($s, $pub); ?></div>
<?php endif ?>
<?php if (!$pub['premium'] && array_filter($data['items'], fn($s) => $s['locked'])): ?>
  <div class="card premium-band"><div><h3>Unlock every signal</h3><p class="muted">Gold, crypto and the strongest forex setups are Premium.<?php if ($pub['free_unlock_days'] && !$pub['free_unlock_at']): ?> You also have a free unlock available: open a locked signal to use it.<?php endif ?></p></div><a class="btn btn-gold" href="/app/premium">Go Premium</a></div>
<?php endif ?>
<?php
    app_end();
}

function page_history(): void
{
    [$u, $pub] = app_start('History', '/app');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pair = in_array($_GET['pair'] ?? '', all_pairs(), true) ? (string) $_GET['pair'] : '';
    $q = ['status' => 'closed', 'page' => $page, 'all' => 1];
    if ($pair !== '') $q['pair'] = $pair;
    $data = api_ok('GET', '/signals', $q);
    ?>
<div class="pagehead"><div><h1>Signal history</h1><p class="muted">Every closed signal, newest first. Wins and losses.</p></div></div>
<div class="row-between toolbar">
  <div class="seg"><a href="/app">Active</a><a class="on" href="/app/history">History</a></div>
  <form method="get" class="inline"><select name="pair" data-autosubmit><option value="">All markets</option>
    <?php foreach (all_pairs() as $p): ?><option <?= $p === $pair ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach ?></select><noscript><button class="btn btn-ghost btn-sm">Filter</button></noscript></form>
</div>
<?= results_table($data['items'], true) ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(['page' => $page - 1] + ($pair ? ['pair' => $pair] : []))) ?>">← Newer</a><?php endif ?>
  <?php if ($data['has_more']): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(['page' => $page + 1] + ($pair ? ['pair' => $pair] : []))) ?>">Older →</a><?php endif ?>
</div>
<?php
    app_end();
}

function page_signal(array $m): void
{
    $id = (int) $m['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user();
        [$status, $data] = api('POST', "/signals/$id/unlock");
        $status < 400 ? flash('success', 'Signal unlocked. Good luck with the trade.') : flash('error', (string) ($data['error'] ?? 'Could not unlock this signal.'));
        redirect("/app/signal/$id");
    }
    [$st, $data] = api('GET', "/signals/$id");
    if ($st === 401) require_user();
    [$u, $pub] = app_start('Signal', '/app');
    if ($st >= 400) {
        echo '<div class="card empty"><h3>Signal not found</h3><a class="btn btn-ghost btn-sm" href="/app">Back to signals</a></div>';
        app_end();
        return;
    }
    $s = $data['signal'];
    $buy = $s['direction'] === 'BUY';
    $running = $s['status'] === 'running';
    [$label, $cls] = result_label($s);
    $pips = $running ? $s['live_pips'] : $s['pips'];
    $levels = $s['locked'] ? null : ['entry' => $s['entry'], 'stop_loss' => $s['stop_loss'], 'tp1' => $s['tp1'], 'tp2' => $s['tp2']];
    $dp = price_decimals($s['pair']);
    $pipSize = pip_size($s['pair']);
    ?>
<div class="pagehead"><div><a class="link small" href="<?= $running ? '/app' : '/app/history' ?>">← Back</a><h1><?= flag($s['pair']) ?> <?= e($s['pair']) ?> <span class="dir <?= $buy ? 'buy' : 'sell' ?>"><?= e($s['direction']) ?></span> <span class="status st-<?= e($cls) ?>"><?= e($label) ?></span></h1>
  <p class="muted"><?= e(strtoupper((string) $s['timeframe'])) ?> chart · opened <?= e(date_local($s['opened_at'])) ?> · ⚡ <?= (int) $s['confidence'] ?>% strength<?= $s['premium'] ? ' · Premium signal' : '' ?></p></div></div>
<div class="detail-grid">
  <div>
    <div class="card"><?= candle_chart((array) ($s['candles'] ?? []), $s['pair'], $levels, $s['current_price']) ?></div>
    <?php if (!$s['locked']): ?>
    <div class="card"><h3>Trade progress</h3><?= progress_bar($s) ?></div>
    <?php endif ?>
    <?php if ($s['reasons']): ?><div class="card"><h3>Why this signal</h3><ul class="ticks"><?php foreach ($s['reasons'] as $r): ?><li><?= e(is_array($r) ? implode(' ', $r) : $r) ?></li><?php endforeach ?></ul></div><?php endif ?>
  </div>
  <div>
    <div class="card bigresult"><span class="muted small"><?= $running ? 'Floating result' : 'Final result' ?></span>
      <?php if ($s['locked']): ?><b class="muted"><?= icon('lock') ?> Locked</b>
      <?php else: ?><b class="<?= pip_class($pips) ?>" data-live="<?= (int) $s['id'] ?>"><?= e(fmt_pips($pips)) ?> <small>pips</small></b><?php endif ?>
      <span class="muted small"><?= $running ? 'Current price' : 'Close price' ?>: <b class="mono"><?= e(fmt_price($s['pair'], $running ? $s['current_price'] : $s['close_price'])) ?></b></span></div>
    <?php if ($s['locked']): ?>
      <div class="card lockcard"><span class="lockico big"><?= icon('lock') ?></span><h3>Premium signal</h3>
        <p class="muted">Entry, stop loss and targets for this signal are for Premium members.</p>
        <a class="btn btn-gold btn-block" href="/app/premium">Unlock with Premium</a>
        <?php if ($pub['free_unlock_days']): ?>
          <?php if (!$pub['free_unlock_at']): ?>
            <form method="post"><?= csrf_field() ?><button class="btn btn-ghost btn-block">Use my free unlock</button></form>
            <p class="muted small center">Free members get one Premium signal every <?= (int) $pub['free_unlock_days'] ?> days.</p>
          <?php else: ?><p class="muted small center">Your next free unlock: <?= e(date_local($pub['free_unlock_at'], 'j M')) ?></p><?php endif ?>
        <?php endif ?>
      </div>
    <?php else: ?>
      <div class="card"><h3>Trade levels</h3>
        <?php foreach (['entry' => 'Entry', 'stop_loss' => 'Stop loss', 'tp1' => 'Take profit 1', 'tp2' => 'Take profit 2'] as $k => $lab):
            $dist = abs((float) $s[$k] - (float) $s['entry']) / $pipSize; ?>
          <div class="levelrow lv-<?= e($k) ?>"><span><?= e($lab) ?><?php if ($k !== 'entry'): ?><small class="muted"> <?= e(number_format($dist, 1)) ?> pips</small><?php endif ?></span>
            <b class="mono"><?= e(number_format((float) $s[$k], $dp, '.', '')) ?></b>
            <button class="iconbtn" data-copy="<?= e(number_format((float) $s[$k], $dp, '.', '')) ?>" aria-label="Copy <?= e($lab) ?>"><?= icon('copy') ?></button></div>
        <?php endforeach ?>
        <p class="muted small">After TP1, move your stop loss to the entry price to make the trade risk-free.</p>
        <a class="btn btn-ghost btn-sm" href="/app/tools?pair=<?= e(rawurlencode($s['pair'])) ?>&sl=<?= e((string) round(abs((float) $s['entry'] - (float) $s['stop_loss']) / $pipSize, 1)) ?>">Position size for this trade</a>
      </div>
    <?php endif ?>
    <div class="card"><h3>Timeline</h3><ul class="timeline">
      <li><b>Published</b><span><?= e(date_local($s['opened_at'])) ?></span></li>
      <?php if ($s['tp1_hit_at']): ?><li class="ok"><b>TP1 reached</b><span><?= e(date_local($s['tp1_hit_at'])) ?></span></li><?php endif ?>
      <?php if (!$running): ?><li class="<?= e($cls === 'win' ? 'ok' : 'bad') ?>"><b>Closed: <?= e($label) ?></b><span><?= e(date_local($s['closed_at'])) ?></span></li>
      <?php else: ?><li><b>Expires if still open</b><span><?= e(date_local($s['expires_at'])) ?></span></li><?php endif ?>
    </ul></div>
  </div>
</div>
<p class="muted small">Signals are automated technical analysis for information only, not financial advice. Only trade with money you can afford to lose.</p>
<?php
    app_end();
}

function progress_bar(array $s): string
{
    $buy = $s['direction'] === 'BUY';
    $sl = (float) $s['stop_loss']; $tp2 = (float) $s['tp2'];
    $price = $s['status'] === 'running' ? (float) ($s['current_price'] ?? $s['entry']) : (float) ($s['close_price'] ?? $s['entry']);
    $pos = fn(float $v) => max(0, min(100, ($buy ? ($v - $sl) : ($sl - $v)) / max(1e-9, abs($tp2 - $sl)) * 100));
    $h = '<div class="progress"><div class="track"></div>';
    foreach (['entry' => 'Entry', 'tp1' => 'TP1'] as $k => $lab) $h .= '<span class="tick" style="left:' . svg_num($pos((float) $s[$k])) . '%"><i>' . $lab . '</i></span>';
    $h .= '<span class="knob" style="left:' . svg_num($pos($price)) . '%"></span>';
    return $h . '<span class="endl">SL</span><span class="endr">TP2</span></div>';
}

function page_results(): void
{
    app_start('Results', '/app/results', 'Track record', 'Every signal, recorded automatically. Wins and losses.');
    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 0], true)) $days = 30;
    echo '<div class="row-between toolbar"><div class="seg"><a class="on" href="/app/results">Overview</a><a href="/app/reports">Reports</a></div>' . period_tabs($days, '/app/results') . '</div>';
    echo stats_block(stats_for($days));
    echo '<p class="muted small">Pips before spread and commission. Past results do not guarantee future results. <a class="link" href="/how-it-works">How results are recorded</a></p>';
    app_end();
}

function page_reports(): void
{
    app_start('Reports', '/app/results', 'Track record', 'Daily, weekly and monthly performance. Times in ' . Reports::tz()->getName() . '.');
    $period = in_array($_GET['period'] ?? '', Reports::PERIODS, true) ? (string) $_GET['period'] : 'daily';
    $data = api_ok('GET', '/reports', ['period' => $period]);
    $open = (string) ($_GET['open'] ?? '');
    $sum = $data['summary'];
    ?>
<div class="row-between toolbar"><div class="seg"><a href="/app/results">Overview</a><a class="on" href="/app/reports">Reports</a></div></div>
<div class="grid3 kpis3">
  <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $k => $lab): $r = $sum[$k]; ?>
  <div class="card kpi"><span><?= e($lab) ?></span><b class="<?= pip_class($r['pips']) ?>"><?= e(fmt_pips($r['pips'])) ?></b><small class="muted"><?= (int) $r['wins'] ?>W · <?= (int) $r['losses'] ?>L</small></div>
  <?php endforeach ?>
</div>
<div class="card note">All time: <?= number_format((int) $sum['all']['closed']) ?> signals · <?= e(number_format((float) $sum['all']['win_rate'], 1)) ?>% win rate · <b class="<?= pip_class($sum['all']['pips']) ?>"><?= e(fmt_pips($sum['all']['pips'], true)) ?></b></div>
<div class="seg seg-wide"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $p => $lab): ?><a class="<?= $p === $period ? 'on' : '' ?>" href="?period=<?= $p ?>"><?= $lab ?></a><?php endforeach ?></div>
<div class="reportlist">
<?php foreach ($data['items'] as $r):
    $isOpen = $open === $r['key'];
    $wr = $r['closed'] ? (int) $r['wins'] * 100 / (int) $r['closed'] : 0; ?>
  <div class="card report <?= $isOpen ? 'open' : '' ?>">
    <a class="report-head" href="?<?= e(http_build_query(['period' => $period] + ($isOpen ? [] : ['open' => $r['key']]))) ?>#r-<?= e($r['key']) ?>" id="r-<?= e($r['key']) ?>">
      <div><b><?= e($r['label']) ?></b><small class="muted"><?= (int) $r['wins'] ?> won · <?= (int) $r['losses'] ?> lost · <?= (int) $r['opened'] ?> new</small></div>
      <div class="r"><b class="<?= pip_class($r['pips']) ?>"><?= e(fmt_pips($r['pips'])) ?></b><small class="muted"><?= $r['closed'] ? e(number_format($wr, 0)) . '% win' : 'no closed trades' ?></small></div></a>
    <div class="wl"><i class="w" style="width:<?= svg_num($wr) ?>%"></i></div>
    <?php if ($isOpen):
        $list = $r['closed'] ? api_ok('GET', '/signals', ['status' => 'closed', 'all' => 1, 'from' => $r['start'], 'to' => $r['end']])['items'] : []; ?>
      <?= $list ? results_table($list, true) : '<p class="muted small">No signals closed in this period.</p>' ?>
    <?php endif ?>
  </div>
<?php endforeach ?>
</div>
<?php
    app_end();
}

function page_markets(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user();
        api('PUT', '/me/watchlist', [], ['symbols' => array_values((array) ($_POST['symbols'] ?? []))]);
        flash('success', 'Watchlist saved.');
        redirect('/app/markets');
    }
    [$u, $pub] = app_start('Markets', '/app/markets', 'Markets', 'Prices, trading sessions and your watchlist.');
    $items = api_ok('GET', '/market/prices')['items'];
    $watch = (array) ($pub['watchlist'] ?? all_pairs());
    $nowUtc = (int) gmdate('G') * 60 + (int) gmdate('i');
    $sessions = [['Sydney', 21 * 60, 6 * 60], ['Tokyo', 0, 9 * 60], ['London', 7 * 60, 16 * 60], ['New York', 12 * 60, 21 * 60]];
    $weekend = in_array((int) gmdate('N'), [6], true) || ((int) gmdate('N') === 7 && $nowUtc < 21 * 60) || ((int) gmdate('N') === 5 && $nowUtc >= 21 * 60);
    ?>
<div class="grid2">
  <div class="card"><div class="row-between"><h3>Trading sessions</h3><span class="muted small">UTC now <?= e(gmdate('H:i')) ?></span></div>
    <?php foreach ($sessions as [$name, $start, $end]):
        $open = !$weekend && ($start < $end ? ($nowUtc >= $start && $nowUtc < $end) : ($nowUtc >= $start || $nowUtc < $end));
        $left = $start / 1440 * 100; $width = (($end - $start + 1440) % 1440) / 1440 * 100; ?>
      <div class="session"><b><?= e($name) ?></b><div class="sbar"><?php if ($start < $end): ?><i style="left:<?= svg_num($left) ?>%;width:<?= svg_num($width) ?>%"></i><?php else: ?><i style="left:<?= svg_num($left) ?>%;width:<?= svg_num(100 - $left) ?>%"></i><i style="left:0;width:<?= svg_num($end / 1440 * 100) ?>%"></i><?php endif ?>
        <em style="left:<?= svg_num($nowUtc / 1440 * 100) ?>%"></em></div><span class="<?= $open ? 'up' : 'muted' ?> small"><?= $open ? 'Open' : 'Closed' ?></span></div>
    <?php endforeach ?>
    <p class="muted small"><?= $weekend ? 'The forex market is closed for the weekend.' : 'The London–New York overlap (12:00–16:00 UTC) is usually the busiest time.' ?></p></div>
  <form method="post" class="card"><?= csrf_field() ?><div class="row-between"><h3>Watchlist</h3><button class="btn btn-ghost btn-sm">Save</button></div>
    <div class="chips"><?php foreach (all_pairs() as $p): ?><label class="chip"><input type="checkbox" name="symbols[]" value="<?= e($p) ?>" <?= in_array($p, $watch, true) ? 'checked' : '' ?>> <?= e($p) ?></label><?php endforeach ?></div></form>
</div>
<div class="card tablecard"><table class="tbl"><thead><tr><th>Market</th><th class="r">Price</th><th class="r">Change</th><th class="hide-s r">High / low</th><th class="hide-s">24h</th></tr></thead><tbody>
<?php foreach ($items as $it): if (!in_array($it['symbol'], $watch, true)) continue; ?>
  <tr><td><?= flag($it['symbol']) ?> <b><?= e($it['symbol']) ?></b></td><td class="r mono"><b><?= e(fmt_price($it['symbol'], $it['price'])) ?></b></td>
    <td class="r <?= $it['change_pct'] >= 0 ? 'up' : 'down' ?>"><?= e(($it['change_pct'] >= 0 ? '+' : '') . number_format((float) $it['change_pct'], 2)) ?>%</td>
    <td class="hide-s r mono muted small"><?= e(fmt_price($it['symbol'], $it['high'])) ?> / <?= e(fmt_price($it['symbol'], $it['low'])) ?></td>
    <td class="hide-s"><?= sparkline((array) $it['spark'], $it['change_pct'] >= 0) ?></td></tr>
<?php endforeach ?>
<?php if (!$items): ?><tr><td colspan="5" class="muted">Prices appear after the first engine run.</td></tr><?php endif ?>
</tbody></table></div>
<?php
    app_end();
}

function page_tools(): void
{
    app_start('Tools', '/app/tools', 'Tools', 'Size every trade so one loss never hurts. Values are for a USD account.');
    echo tools_html();
    app_end();
}

function page_account(): void
{
    $u = require_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = (string) ($_POST['do'] ?? '');
        $ok = fn(string $msg, array $r) => $r[0] < 400 ? flash('success', $msg) : flash('error', (string) ($r[1]['error'] ?? 'Could not save.'));
        if ($do === 'profile') {
            $ok('Name saved.', api('PUT', '/me', [], ['name' => (string) ($_POST['name'] ?? '')]));
        } elseif ($do === 'password') {
            $r = api('PUT', '/me/password', [], ['current_password' => (string) ($_POST['current_password'] ?? ''), 'new_password' => (string) ($_POST['new_password'] ?? '')]);
            $ok('Password changed.', $r);
            if ($r[0] < 400) sign_in(Db::one('SELECT * FROM users WHERE id = ?', [(int) $u['id']]));
        } elseif ($do === 'alerts') {
            $email = [];
            foreach (['signals', 'results', 'daily', 'weekly', 'monthly', 'marketing'] as $k) $email[$k] = !empty($_POST["email_$k"]);
            $ok('Alert settings saved.', api('PUT', '/me/notifications', [], ['email' => $email]));
        } elseif ($do === 'cancel_web') {
            try { Paystack::cancel((int) $u['id']); flash('success', 'Auto-renew is off. Premium stays on until the end of the period you paid for.'); }
            catch (ApiError $e) { flash('error', $e->getMessage()); }
        } elseif ($do === 'delete') {
            if (($_POST['confirm'] ?? '') !== 'DELETE') { flash('error', 'Type DELETE to confirm.'); redirect('/app/account'); }
            $r = api('DELETE', '/me', [], ['password' => (string) ($_POST['password'] ?? '')]);
            if ($r[0] < 400) { $_SESSION = []; session_regenerate_id(true); flash('success', 'Your account and data have been deleted.'); redirect('/'); }
            flash('error', (string) ($r[1]['error'] ?? 'Could not delete the account.'));
        }
        redirect('/app/account');
    }
    [$u, $pub] = app_start('Account', '/app/account', 'Account');
    $n = $pub['notifications']['email'];
    $web = Paystack::current((int) $u['id']);
    $toggles = ['signals' => ['New signals', 'Full trade plan for every new signal'], 'results' => ['Trade results', 'When a signal wins or loses'],
        'daily' => ['Daily report', 'Every trading day after the New York close'], 'weekly' => ['Weekly report', 'Saturday morning: the full week'],
        'monthly' => ['Monthly report', 'On the 1st: last month\'s performance'], 'marketing' => ['Tips and offers', 'Occasional product news (at most twice a week)']];
    ?>
<div class="grid2">
  <div>
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="profile"><h3>Profile</h3>
      <label>Name<input name="name" value="<?= e($u['name']) ?>" maxlength="60" required></label>
      <label>Email<input value="<?= e($u['email']) ?>" disabled></label>
      <p class="small"><?= $pub['email_verified'] ? '<span class="up">✓ Verified</span>' : '<a class="link" href="/verify">Verify your email</a>' ?> · Member since <?= e(date_local($pub['created_at'], 'j M Y')) ?></p>
      <button class="btn btn-ghost btn-sm">Save name</button></form>
    <div class="card"><h3>Plan</h3>
      <?php if ($pub['premium']): ?>
        <p><span class="pill pill-gold">Premium</span> <?= $pub['premium_until'] ? 'until ' . e(date_local($pub['premium_until'], 'j M Y')) : '' ?></p>
        <?php if ($web && in_array($web['status'], ['active', 'attention'], true)): ?>
          <p class="muted small">Website subscription (<?= e($web['plan']) ?>), renews automatically.</p>
          <form method="post" data-confirm="Turn off auto-renew? Premium stays on until the end of the paid period."><?= csrf_field() ?><input type="hidden" name="do" value="cancel_web"><button class="btn btn-ghost btn-sm">Turn off auto-renew</button></form>
        <?php elseif (str_contains((string) $pub['premium_plan'], 'fx_') || in_array($pub['premium_plan'], ['monthly', 'yearly'], true)): ?>
          <p class="muted small">Bought in the Android app. Manage it in Google Play → Payments &amp; subscriptions.</p>
        <?php endif ?>
      <?php else: ?><p>Free plan</p><a class="btn btn-gold btn-sm" href="/app/premium">Go Premium</a><?php endif ?>
    </div>
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="password"><h3>Change password</h3>
      <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
      <label>New password<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
      <button class="btn btn-ghost btn-sm">Change password</button><p class="muted small">You will be logged out of the app and other browsers.</p></form>
  </div>
  <div>
    <form method="post" class="card form" id="alerts"><?= csrf_field() ?><input type="hidden" name="do" value="alerts"><h3>Email alerts</h3>
      <?php if (!$pub['email_verified']): ?><p class="note small">Verify your email to receive alerts.</p><?php endif ?>
      <?php foreach ($toggles as $k => [$t, $d]): ?>
        <label class="switchrow"><span><b><?= e($t) ?></b><small class="muted"><?= e($d) ?></small></span><input type="checkbox" class="switch" name="email_<?= e($k) ?>" value="1" <?= !empty($n[$k]) ? 'checked' : '' ?>></label>
      <?php endforeach ?>
      <button class="btn btn-gold btn-sm">Save alerts</button>
      <p class="muted small">Instant push notifications are in the Android app. Pick your markets on the <a class="link" href="/app">Signals</a> page.</p></form>
    <div class="card"><h3>Get the app</h3><p class="muted small">Same account, instant push alerts and the trading bot.</p>
      <a class="playbadge" href="<?= e(play_url()) ?>" rel="noopener"><?= icon('play') ?><span><small>Get it on</small>Google Play</span></a></div>
    <form method="post" class="card form danger" data-confirm="Delete your account and all your data? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="do" value="delete"><h3>Delete account</h3>
      <p class="muted small">Deletes your account, settings and alerts. A Google Play subscription must be cancelled in Google Play.</p>
      <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
      <label>Type DELETE<input name="confirm" required autocomplete="off"></label>
      <button class="btn btn-danger btn-sm">Delete my account</button></form>
  </div>
</div>
<?php
    app_end();
}

/** Live floating pips for the open signals (polled by app.js every 30 s). */
function page_live_json(): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!current_user()) { http_response_code(401); echo '{"error":"login"}'; return; }
    [$status, $data] = api('GET', '/signals', ['status' => 'running', 'all' => 1]);
    $out = [];
    foreach ($data['items'] ?? [] as $s) {
        if (!$s['locked']) $out[(string) $s['id']] = ['pips' => $s['live_pips'], 'text' => fmt_pips($s['live_pips']), 'cls' => pip_class($s['live_pips'])];
    }
    echo json_encode(['items' => $out, 'at' => gmdate('H:i')]);
}
