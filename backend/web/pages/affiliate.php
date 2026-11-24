<?php
declare(strict_types=1);

/* Affiliate dashboard: join, referral link, stats, earnings, payouts, own broker link. */

function page_affiliate(): void
{
    $u = require_user();
    $aff = Affiliates::forUser((int) $u['id']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = (string) ($_POST['do'] ?? '');
        try {
            if ($do === 'join') {
                Affiliates::join($u, (string) ($_POST['code'] ?? ''));
                $a = Affiliates::forUser((int) $u['id']);
                flash('success', $a['status'] === 'active' ? 'Welcome to the affiliate programme! Share your link to start earning.' : 'Thanks for joining. Your account will be reviewed shortly.');
            } elseif (!$aff) {
                throw new ApiError('Join the programme first.', 403);
            } elseif ($do === 'payout_details') {
                Affiliates::savePayoutDetails((int) $u['id'], (string) ($_POST['method'] ?? ''), (string) ($_POST['details'] ?? ''));
                flash('success', 'Payout details saved.');
            } elseif ($do === 'payout') {
                Http::rateLimit('payout:' . $u['id'], 5, 3600);
                Affiliates::requestPayout((int) $u['id']);
                flash('success', 'Payout requested. You will get an email when it has been sent.');
            } elseif ($do === 'broker') {
                $name = trim((string) ($_POST['broker_name'] ?? ''));
                $url = trim((string) ($_POST['broker_url'] ?? ''));
                if ($url === '') {
                    Db::exec('UPDATE affiliates SET broker_name = NULL, broker_url = NULL, broker_status = NULL, updated_at = ? WHERE user_id = ?', [now(), (int) $u['id']]);
                    flash('success', 'Broker link removed.');
                } else {
                    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new ApiError('Enter the broker name.', 422);
                    $url = Affiliates::validBrokerUrl($url);
                    Db::exec("UPDATE affiliates SET broker_name = ?, broker_url = ?, broker_status = 'pending', updated_at = ? WHERE user_id = ?", [$name, $url, now(), (int) $u['id']]);
                    flash('success', 'Broker link sent for review. It goes live once approved.');
                }
            }
        } catch (ApiError $e) {
            flash('error', $e->getMessage());
        }
        redirect('/app/affiliate');
    }

    [$u, $pub] = app_start('Affiliate', '/app/affiliate');
    $set = Affiliates::settings();
    if (!$aff) {
        ?>
<div class="pagehead"><div><h1>Affiliate programme</h1><p class="muted">Earn <?= (int) $set['rate'] ?>% of every Premium payment from people you refer, for as long as they stay subscribed.</p></div></div>
<div class="grid2">
  <div class="card"><h3>How it works</h3><ul class="ticks">
    <li>Get a personal link and code for the website and the Android app</li>
    <li>Visitors are remembered for <?= (int) $set['cookie_days'] ?> days</li>
    <li><?= (int) $set['rate'] ?>% recurring commission on website and Google Play payments</li>
    <li>Add your own broker IB link: your referrals see it on "Open a broker account"</li>
    <li>Withdraw from $<?= e(number_format((float) $set['min_payout'], 0)) ?> by bank transfer, USDT or PayPal</li></ul>
    <a class="link small" href="/affiliate-terms" target="_blank">Read the affiliate terms</a></div>
  <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="join"><h3>Join now, it's free</h3>
    <?php if (!Affiliates::enabled()): ?><p class="note">The programme is closed for new affiliates right now.</p>
    <?php elseif (!$pub['email_verified']): ?><p class="note">Verify your email first. <a class="link" href="/verify">Enter your code</a></p>
    <?php else: ?>
      <label>Choose your code (optional)<input name="code" maxlength="12" pattern="[A-Za-z0-9]{4,12}" placeholder="e.g. <?= e(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $u['name']) ?: 'TRADER', 0, 8))) ?>" style="text-transform:uppercase"><small class="muted">4–12 letters and numbers. Leave empty and we'll make one.</small></label>
      <label class="check"><input type="checkbox" required> I agree to the affiliate terms: no spam, no fake reviews, no promises of profit.</label>
      <button class="btn btn-gold btn-block">Join the programme</button>
    <?php endif ?></form>
</div>
<?php
        app_end();
        return;
    }

    $id = (int) $u['id'];
    $bal = Affiliates::balances($id);
    $st = Affiliates::stats($id);
    $link = site_url('/r/' . $aff['code']);
    $rate = Affiliates::rateFor($aff);
    $comms = Db::all('SELECT c.*, p.plan, p.source, u.name AS ref_name FROM commissions c JOIN payments p ON p.id = c.payment_id
        LEFT JOIN users u ON u.id = c.referred_user_id WHERE c.affiliate_id = ? ORDER BY c.id DESC LIMIT 30', [$id]);
    $payouts = Db::all('SELECT * FROM payouts WHERE affiliate_id = ? ORDER BY id DESC LIMIT 20', [$id]);
    $waiting = Db::one("SELECT id FROM payouts WHERE affiliate_id = ? AND status = 'requested'", [$id]);
    $share = 'Free forex & gold signals with entry, stop loss and take profit, and every result tracked: ' . $link;
    ?>
<div class="pagehead"><div><h1>Affiliate dashboard</h1><p class="muted">You earn <?= e(rtrim(rtrim(number_format($rate, 1), '0'), '.')) ?>% of every payment from people you refer.</p></div>
  <span class="pill <?= $aff['status'] === 'active' ? 'pill-live' : 'pill-gold' ?>"><?= e(ucfirst($aff['status'])) ?></span></div>
<?php if ($aff['status'] === 'pending'): ?><div class="note">Your affiliate account is waiting for approval. Your link starts tracking once it is approved.</div><?php endif ?>
<?php if ($aff['status'] === 'blocked'): ?><div class="note">Your affiliate account is suspended. Contact swalafblog@gmail.com.</div><?php endif ?>
<div class="card linkcard">
  <div><span class="muted small">Your link</span><div class="copyrow"><input readonly value="<?= e($link) ?>" id="reflink"><button class="btn btn-gold btn-sm" data-copy="<?= e($link) ?>">Copy</button></div></div>
  <div><span class="muted small">Your code (for the app)</span><div class="copyrow"><input readonly value="<?= e($aff['code']) ?>" class="mono"><button class="btn btn-ghost btn-sm" data-copy="<?= e($aff['code']) ?>">Copy</button></div></div>
  <div class="sharebtns">
    <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode($share)) ?>">Share on WhatsApp</a>
    <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= e(rawurlencode($link)) ?>&text=<?= e(rawurlencode('Free forex & gold signals with entry, SL and TP')) ?>">Telegram</a>
    <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= e(rawurlencode($share)) ?>">X</a>
    <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= e(play_url($aff['code'])) ?>">App link</a>
  </div>
</div>
<div class="statgrid five">
  <div class="card kpi"><span>Link visits</span><b><?= number_format($st['visits']) ?></b></div>
  <div class="card kpi"><span>Sign-ups</span><b><?= number_format($st['signups']) ?></b></div>
  <div class="card kpi"><span>Premium now</span><b class="gold"><?= number_format($st['premium']) ?></b></div>
  <div class="card kpi"><span>Broker clicks</span><b><?= number_format($st['broker_clicks']) ?></b></div>
  <div class="card kpi"><span>Lifetime earnings</span><b class="up"><?= e(money_usd($bal['lifetime'])) ?></b></div>
</div>
<div class="grid2">
  <div class="card"><h3>Balance</h3>
    <div class="kv"><span>Available to withdraw</span><b class="up big"><?= e(money_usd($bal['available'])) ?></b></div>
    <div class="kv"><span>Pending (<?= (int) $set['hold_days'] ?>-day refund window)</span><b><?= e(money_usd($bal['pending'])) ?></b></div>
    <div class="kv"><span>Payout requested</span><b><?= e(money_usd($bal['requested'])) ?></b></div>
    <div class="kv"><span>Paid to you</span><b><?= e(money_usd($bal['paid'])) ?></b></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="payout">
      <button class="btn btn-gold btn-block" <?= $waiting || $bal['available'] < (float) $set['min_payout'] || !$aff['payout_details'] ? 'disabled' : '' ?>>Request payout</button></form>
    <p class="muted small"><?= $waiting ? 'Your payout request is being processed.' : 'Minimum payout $' . e(number_format((float) $set['min_payout'], 2)) . '. ' . (!$aff['payout_details'] ? 'Add your payout details first.' : '') ?></p>
  </div>
  <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="payout_details"><h3>Payout details</h3>
    <label>Method<select name="method"><?php foreach (Affiliates::PAYOUT_METHODS as $k => $lab): ?><option value="<?= e($k) ?>" <?= $aff['payout_method'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach ?></select></label>
    <label>Details<textarea name="details" rows="3" maxlength="500" placeholder="Bank name, account number and account name — or USDT TRC20 address — or PayPal email" required><?= e((string) $aff['payout_details']) ?></textarea></label>
    <button class="btn btn-ghost btn-sm">Save details</button><p class="muted small">Naira bank payouts use the exchange rate on the day of payment.</p></form>
</div>
<form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="broker">
  <div class="row-between"><h3>Your broker link</h3>
    <?php if ($aff['broker_status']): ?><span class="pill <?= $aff['broker_status'] === 'approved' ? 'pill-live' : 'pill-gold' ?>"><?= e(ucfirst($aff['broker_status'])) ?></span><?php endif ?></div>
  <p class="muted small">Are you an introducing broker (IB) or partner with a broker? Add your referral link. Once approved, the people you referred see it on "Open a broker account", so the broker pays you directly.</p>
  <div class="two"><label>Broker name<input name="broker_name" maxlength="60" value="<?= e((string) $aff['broker_name']) ?>" placeholder="e.g. Exness"></label>
  <label>Your referral link<input name="broker_url" type="url" maxlength="300" value="<?= e((string) $aff['broker_url']) ?>" placeholder="https://..."></label></div>
  <button class="btn btn-ghost btn-sm">Save broker link</button> <span class="muted small">Leave the link empty to remove it.</span>
</form>
<div class="card tablecard"><h3 class="pad-h">Commissions</h3>
<table class="tbl"><thead><tr><th>Date</th><th>Referral</th><th class="hide-s">Plan</th><th class="r">Commission</th><th>Status</th></tr></thead><tbody>
<?php foreach ($comms as $c): ?>
  <tr><td class="muted small"><?= e(date_local(iso($c['created_at']), 'j M Y')) ?></td><td><?= e(mask_name((string) $c['ref_name'])) ?></td>
    <td class="hide-s small"><?= e(ucfirst((string) $c['plan'])) ?> · <?= e($c['source'] === 'play' ? 'app' : 'website') ?></td>
    <td class="r mono"><b><?= e(money_usd((float) $c['amount_usd'])) ?></b></td>
    <td><span class="status st-<?= e(['pending' => 'live', 'approved' => 'win', 'paid' => 'win', 'reversed' => 'loss'][$c['status']] ?? '') ?>"><?= e(['pending' => 'Pending until ' . date_local(iso($c['available_at']), 'j M'), 'approved' => $c['payout_id'] ? 'In payout' : 'Available', 'paid' => 'Paid', 'reversed' => 'Refunded'][$c['status']] ?? $c['status']) ?></span></td></tr>
<?php endforeach ?>
<?php if (!$comms): ?><tr><td colspan="5" class="muted">No commissions yet. Share your link: when someone you referred buys Premium, your commission appears here.</td></tr><?php endif ?>
</tbody></table></div>
<?php if ($payouts): ?>
<div class="card tablecard"><h3 class="pad-h">Payouts</h3><table class="tbl"><thead><tr><th>Requested</th><th class="r">Amount</th><th>Method</th><th>Status</th><th class="hide-s">Reference</th></tr></thead><tbody>
<?php foreach ($payouts as $p): ?>
  <tr><td class="small"><?= e(date_local(iso($p['created_at']), 'j M Y')) ?></td><td class="r mono"><b><?= e(money_usd((float) $p['amount_usd'])) ?></b></td><td class="small"><?= e(Affiliates::PAYOUT_METHODS[$p['method']] ?? $p['method']) ?></td>
    <td><span class="status st-<?= $p['status'] === 'paid' ? 'win' : ($p['status'] === 'rejected' ? 'loss' : 'live') ?>"><?= e(ucfirst($p['status'])) ?></span><?php if ($p['admin_note']): ?><small class="muted"> <?= e($p['admin_note']) ?></small><?php endif ?></td>
    <td class="hide-s small mono"><?= e((string) $p['reference']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php
    app_end();
}

/** "Abdullahi A." style name, so affiliates don't see who their referrals are. */
function mask_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (!$parts || $parts[0] === '') return 'Deleted account';
    $first = mb_substr($parts[0], 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($parts[0]) - 1)));
    return $first . (isset($parts[1]) ? ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '');
}
