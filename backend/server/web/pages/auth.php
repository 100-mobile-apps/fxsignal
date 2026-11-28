<?php
declare(strict_types=1);

/* Log in, create account, verify email, forgot/reset password, log out. Same accounts as the app. */

function auth_page(string $title, string $heading, string $sub, callable $body): void
{
    page_start(['title' => "$title · " . BRAND, 'noindex' => $title !== 'Create a free account' && $title !== 'Log in'], 'auth');
    public_header();
    echo '<main class="wrap authwrap"><div class="card authcard"><h1>' . e($heading) . '</h1>';
    if ($sub !== '') echo '<p class="muted">' . e($sub) . '</p>';
    $body();
    echo '</div></main>';
    unset($_SESSION['old']);
    site_footer();
}

function after_login_target(): string
{
    $to = (string) ($_SESSION['after_login'] ?? '/app');
    unset($_SESSION['after_login']);
    return str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/app';
}

function page_login(): void
{
    if (current_user()) redirect('/app');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $_SESSION['old'] = ['email' => $email];
        [$status, $data] = api('POST', '/auth/login', [], ['email' => $email, 'password' => (string) ($_POST['password'] ?? '')], []);
        if ($status === 200 && !empty($data['user']['id'])) {
            sign_in(Db::one('SELECT * FROM users WHERE id = ?', [(int) $data['user']['id']]));
            redirect(after_login_target());
        }
        flash('error', (string) ($data['error'] ?? 'Could not log in.'));
        redirect('/login');
    }
    auth_page('Log in', 'Welcome back', 'Log in with the same email and password you use in the app.', function () { ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Email<input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-gold btn-block">Log in</button>
</form>
<p class="small center"><a class="link" href="/forgot">Forgot your password?</a></p>
<p class="small center muted">New here? <a class="link" href="/register">Create a free account</a></p>
<?php });
}

function page_register(): void
{
    if (current_user()) redirect('/app');
    $ref = referral_cookie();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['ref'] ?? '')));
        $_SESSION['old'] = ['name' => $name, 'email' => $email, 'ref' => $code];
        [$status, $data] = api('POST', '/auth/register', [], [
            'name' => $name, 'email' => $email, 'password' => (string) ($_POST['password'] ?? ''),
            'accepted_terms' => !empty($_POST['terms']), 'ref' => $code !== '' ? $code : $ref,
        ], []);
        if ($status === 201 && !empty($data['user']['id'])) {
            $user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $data['user']['id']]);
            sign_in($user);
            if ($code !== '' && empty($user['referred_by'])) flash('error', 'That referral code could not be used, but your account is ready.');
            flash('success', 'Account created. We sent a 6-digit code to ' . $user['email'] . '.');
            redirect('/verify');
        }
        flash('error', (string) ($data['error'] ?? 'Could not create your account.'));
        redirect('/register');
    }
    auth_page('Create a free account', 'Create your free account', 'Free forex signals with entry, stop loss and take profit. No card needed.', function () use ($ref) { ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Name<input name="name" value="<?= e(old('name')) ?>" required maxlength="60" autocomplete="name" autofocus></label>
  <label>Email<input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></label>
  <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"><small class="muted">At least 8 characters</small></label>
  <?php if ($ref): ?>
    <p class="note small">Invited with code <b><?= e($ref) ?></b></p>
  <?php else: ?>
    <details class="refbox"<?= old('ref') !== '' ? ' open' : '' ?>><summary class="small">Have a referral code?</summary>
      <label>Referral code<input name="ref" value="<?= e(old('ref')) ?>" maxlength="20" autocomplete="off" style="text-transform:uppercase"></label></details>
  <?php endif ?>
  <label class="check"><input type="checkbox" name="terms" value="1" required> I accept the <a class="link" href="/terms" target="_blank">Terms</a> and <a class="link" href="/risk" target="_blank">Risk disclaimer</a>, and understand signals are not financial advice.</label>
  <button class="btn btn-gold btn-block">Create account</button>
</form>
<p class="small center muted">Already have an account? <a class="link" href="/login">Log in</a></p>
<?php });
}

function page_verify(): void
{
    $u = require_user();
    if ($u['email_verified_at'] !== null) redirect('/app');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['do'] ?? '') === 'resend') {
            [$status, $data] = api('POST', '/auth/verify/resend');
            $status < 400 ? flash('success', 'A new code is on its way. Check your inbox and spam folder.') : flash('error', (string) ($data['error'] ?? 'Could not send a new code.'));
            redirect('/verify');
        }
        [$status, $data] = api('POST', '/auth/verify', [], ['code' => preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''))]);
        if ($status < 400) {
            flash('success', 'Email verified. Signal alerts are on.');
            redirect(after_login_target());
        }
        flash('error', (string) ($data['error'] ?? 'That code did not work.'));
        redirect('/verify');
    }
    auth_page('Verify your email', 'Check your email', 'Enter the 6-digit code we sent to ' . $u['email'] . '.', function () { ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code" class="codeinput" autofocus></label>
  <button class="btn btn-gold btn-block">Verify</button>
</form>
<form method="post" class="center"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="btn-text small">Send a new code</button></form>
<p class="small center"><a class="link" href="/app">Skip for now</a></p>
<?php });
}

function page_forgot(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        [$status, $data] = api('POST', '/auth/forgot', [], ['email' => $email], []);
        if ($status < 400) {
            $_SESSION['reset_email'] = $email;
            flash('success', 'If an account exists for this email, we sent a 6-digit code.');
            redirect('/reset');
        }
        flash('error', (string) ($data['error'] ?? 'Please try again.'));
        redirect('/forgot');
    }
    auth_page('Reset your password', 'Forgot your password?', 'Enter your email and we will send you a 6-digit code.', function () { ?>
<form method="post" class="form"><?= csrf_field() ?>
  <label>Email<input type="email" name="email" required autocomplete="email" autofocus></label>
  <button class="btn btn-gold btn-block">Send code</button></form>
<p class="small center"><a class="link" href="/login">Back to log in</a></p>
<?php });
}

function page_reset(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        [$status, $data] = api('POST', '/auth/reset', [], [
            'email' => $email, 'code' => preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')), 'password' => (string) ($_POST['password'] ?? ''),
        ], []);
        if ($status < 400) {
            unset($_SESSION['reset_email']);
            flash('success', 'Password changed. Log in with your new password.');
            redirect('/login');
        }
        $_SESSION['reset_email'] = $email;
        flash('error', (string) ($data['error'] ?? 'That code did not work.'));
        redirect('/reset');
    }
    $email = (string) ($_SESSION['reset_email'] ?? '');
    auth_page('Choose a new password', 'Choose a new password', 'Enter the code from your email and a new password.', function () use ($email) { ?>
<form method="post" class="form"><?= csrf_field() ?>
  <label>Email<input type="email" name="email" value="<?= e($email) ?>" required autocomplete="username"></label>
  <label>6-digit code<input name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" class="codeinput"></label>
  <label>New password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
  <button class="btn btn-gold btn-block">Change password</button></form>
<?php });
}

function page_logout(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/');
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('/');
}
