<?php
declare(strict_types=1);

/* Premium on the website: Paystack checkout (card, bank transfer, USSD), return page, status. */

function page_premium(): void
{
    $u = require_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $plan = (string) ($_POST['plan'] ?? '');
        try {
            $url = Paystack::checkout($u, $plan, site_url('/app/premium/return'));
            header('Location: ' . $url, true, 303); // Paystack's hosted checkout page
            exit;
        } catch (ApiError $e) {
            flash('error', $e->getMessage());
            redirect('/app/premium');
        }
    }
    [$u, $pub] = app_start('Premium', '/app/premium');
    $p = price_info();
    $cur = $p['web']['currency'];
    $want = ($_GET['plan'] ?? '') === 'monthly' ? 'monthly' : 'yearly';
    $monthly = (float) $p['web']['monthly_amount'];
    $yearly = (float) $p['web']['yearly_amount'];
    $save = $monthly > 0 && $yearly > 0 ? (int) round((1 - $yearly / ($monthly * 12)) * 100) : 0;
    $web = Paystack::current((int) $u['id']);
    ?>
<div class="pagehead"><div><h1>Premium</h1><p class="muted">Every live signal with full entry, stop loss and targets, on the website and in the app.</p></div></div>
<?php if ($pub['premium']): ?>
  <div class="card premium-on"><span class="pill pill-gold">Premium is active</span>
    <p><?= $pub['premium_until'] ? 'Paid until <b>' . e(date_local($pub['premium_until'], 'j M Y')) . '</b>.' : 'Complimentary Premium.' ?>
    <?php if ($web && in_array($web['status'], ['active', 'attention'], true)): ?> Your website subscription renews automatically.<?php endif ?></p>
    <a class="btn btn-ghost btn-sm" href="/app">Go to signals</a> <a class="btn-text small" href="/app/account">Manage plan</a></div>
<?php endif ?>
<div class="grid2 premium-grid">
  <div class="card">
    <h3>What you get</h3>
    <ul class="ticks big">
      <li>Every live signal unlocked, including the strongest forex setups</li>
      <li>Gold (XAU/USD), Bitcoin and Ethereum signals</li>
      <li>Full levels in email alerts and app push notifications</li>
      <li>The Premium trading bot in the Android app</li>
      <li>One account: works on the website and in the app</li>
    </ul>
    <p class="muted small">Results, reports, tools and history stay free for everyone. We never promise profits: check the <a class="link" href="/app/results">track record</a> before you subscribe.</p>
  </div>
  <div class="card">
    <?php if (!$p['card_ready']): ?>
      <h3>Subscribe in the app</h3>
      <p class="muted">Website payments are not switched on yet. You can subscribe in the Android app with Google Play (<?= e($p['play']['launch_monthly'] ?: $p['play']['monthly']) ?>/month), and Premium will unlock here too.</p>
      <a class="playbadge" href="<?= e(play_url()) ?>" rel="noopener"><?= icon('play') ?><span><small>Get it on</small>Google Play</span></a>
    <?php else: ?>
      <form method="post" class="form plans-pick"><?= csrf_field() ?>
        <h3>Choose your plan</h3>
        <label class="planopt"><input type="radio" name="plan" value="yearly" <?= $want === 'yearly' ? 'checked' : '' ?>>
          <span><b>Yearly</b><?php if ($save > 0): ?> <em class="pill pill-gold">Save <?= $save ?>%</em><?php endif ?><small class="muted"><?= e(money_local($yearly / 12, $cur)) ?> a month, billed yearly</small></span>
          <strong><?= e(money_local($yearly, $cur)) ?></strong></label>
        <label class="planopt"><input type="radio" name="plan" value="monthly" <?= $want === 'monthly' ? 'checked' : '' ?>>
          <span><b>Monthly</b><small class="muted">Billed every month</small></span><strong><?= e(money_local($monthly, $cur)) ?></strong></label>
        <?php if (!$pub['email_verified']): ?><p class="note small">Verify your email first. <a class="link" href="/verify">Enter your code</a></p><?php endif ?>
        <button class="btn btn-gold btn-block btn-lg" <?= $pub['email_verified'] ? '' : 'disabled' ?>>Continue to secure payment</button>
        <p class="muted small center">Pay with card, bank transfer or USSD through Paystack. Renews automatically; turn off auto-renew anytime in Account. We never see your card details.</p>
      </form>
    <?php endif ?>
  </div>
</div>
<div class="narrow">
  <details class="faq"><summary>Can I cancel?</summary><p>Yes. Turn off auto-renew in Account; Premium stays on until the end of the period you paid for.</p></details>
  <details class="faq"><summary>I already pay in the app. Do I pay again here?</summary><p>No. Premium from Google Play unlocks the website automatically when you log in with the same email.</p></details>
  <details class="faq"><summary>Refunds</summary><p>If something went wrong with a payment, email swalafblog@gmail.com within 7 days.</p></details>
</div>
<?php
    app_end();
}

function page_premium_return(): void
{
    $u = require_user();
    if (!empty($_GET['cancelled'])) {
        flash('error', 'Payment cancelled. You have not been charged.');
        redirect('/app/premium');
    }
    $ref = (string) ($_GET['reference'] ?? ($_GET['trxref'] ?? ''));
    try {
        $status = Paystack::confirm($ref, (int) $u['id']);
    } catch (ApiError $e) {
        flash('error', $e->getMessage());
        redirect('/app/premium');
    }
    if ($status === 'success') {
        flash('success', 'Payment received. Premium is now active on the website and in the app. Thank you!');
        redirect('/app');
    }
    flash('error', $status === 'abandoned' || $status === 'failed'
        ? 'The payment did not go through. You have not been charged.'
        : 'Your payment is still being confirmed. Premium switches on automatically as soon as Paystack confirms it.');
    redirect('/app/premium');
}
