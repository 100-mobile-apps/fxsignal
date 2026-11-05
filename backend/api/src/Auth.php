<?php
declare(strict_types=1);

final class Auth
{
    /** Returns the logged-in user row or throws 401. */
    public static function user(): array
    {
        $token = Http::bearer();
        $payload = $token ? Jwt::decode($token) : null;
        if (!$payload) throw new ApiError('Please log in again.', 401);
        $user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $payload['sub']]);
        if (!$user || (int) $user['token_version'] !== (int) ($payload['v'] ?? -1)) {
            throw new ApiError('Your session has expired. Please log in again.', 401);
        }
        return $user;
    }

    public static function tokenFor(array $user): string
    {
        return Jwt::encode(['sub' => (int) $user['id'], 'v' => (int) $user['token_version']]);
    }

    public static function publicUser(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'created_at' => iso($u['created_at']),
            'email_verified' => $u['email_verified_at'] !== null,
            'notifications' => [
                'signals' => (bool) $u['notify_signals'],
                'results' => (bool) $u['notify_results'],
                'reports' => (bool) $u['notify_reports'],
                'pairs' => user_pairs($u),
                // false: the user follows the best recent markets, which change as results come in.
                'pairs_chosen' => user_pairs_source($u) === 'chosen',
                'email' => [
                    'signals' => (bool) $u['email_signals'],
                    'results' => (bool) $u['email_results'],
                    'daily' => (bool) $u['email_daily'],
                    'weekly' => (bool) $u['email_weekly'],
                    'monthly' => (bool) $u['email_monthly'],
                ],
            ],
            'watchlist' => $u['watchlist'] ? json_decode($u['watchlist'], true) : default_pairs(),
            'preferences' => [
                'strategy' => MarketRank::effectiveStrategy($u['strategy'] ?? null),
                'strategy_chosen' => ($u['strategy'] ?? null) !== null,
                'account_size' => $u['account_size'] !== null ? (float) $u['account_size'] : 10000.0,
                'risk_pct' => $u['risk_pct'] !== null ? (float) $u['risk_pct'] : 1.0,
            ],
            'default_pairs' => default_pairs(),
        ];
    }

    private static function session(array $user, int $status = 200): void
    {
        Http::send(['token' => self::tokenFor($user), 'user' => self::publicUser($user)], $status);
    }

    public static function validEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new ApiError('Enter a valid email address.', 422);
        }
        return $email;
    }

    public static function validPassword(string $pw): string
    {
        if (strlen($pw) < 8) throw new ApiError('Password must be at least 8 characters.', 422);
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            throw new ApiError('Password must contain letters and numbers.', 422);
        }
        return $pw;
    }

    public static function validName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new ApiError('Enter your name (2–60 characters).', 422);
        return $name;
    }

    public static function register(): void
    {
        $b = Http::body();
        $name = self::validName((string) ($b['name'] ?? ''));
        $email = self::validEmail((string) ($b['email'] ?? ''));
        $pw = self::validPassword((string) ($b['password'] ?? ''));
        if (empty($b['accepted_terms'])) throw new ApiError('Please accept the Terms and Risk Disclaimer.', 422);
        Http::rateLimit('register:' . Http::ip(), 10, 3600);
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ApiError('An account with this email already exists. Try logging in.', 409);
        }
        $id = Db::insert(
            'INSERT INTO users (name, email, password_hash, created_at, last_login_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $email, password_hash($pw, PASSWORD_DEFAULT), now(), now()]
        );
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        self::sendCode($user, 'verify');
        self::session($user, 201);
    }

    /** Creates a 6-digit code (verify or reset), emails it immediately. */
    public static function sendCode(array $user, string $purpose): bool
    {
        $code = self::newCode($user, $purpose, 900);
        [$subject, $html, $text] = EmailTemplates::code($user['name'], $code, $purpose);
        return Mailer::sendNow($user['email'], $subject, $html, $text);
    }

    private static function newCode(array $user, string $purpose, int $ttl): string
    {
        $code = (string) random_int(100000, 999999);
        Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [now(), $user['id'], $purpose]);
        Db::insert(
            'INSERT INTO password_resets (user_id, purpose, code_hash, attempts, expires_at, created_at) VALUES (?, ?, ?, 0, ?, ?)',
            [$user['id'], $purpose, password_hash($code, PASSWORD_DEFAULT), now(time() + $ttl), now()]
        );
        return $code;
    }

    /**
     * Reminds unverified users to verify (so they get email alerts): 1, 3 and 7 days after sign-up, then stops.
     * Each reminder carries a fresh code valid for 48 hours and goes through the mail queue.
     */
    public static function sendVerifyReminders(int $nowTs, int $max = 30): array
    {
        $due = [1 => 86400, 2 => 3 * 86400, 3 => 7 * 86400];   // codes already sent => age since sign-up for the next one
        $rows = Db::all(
            "SELECT u.*, COUNT(p.id) AS sent, MAX(p.created_at) AS last_sent
             FROM users u LEFT JOIN password_resets p ON p.user_id = u.id AND p.purpose = 'verify'
             WHERE u.email_verified_at IS NULL GROUP BY u.id ORDER BY u.created_at"
        );
        $n = 0;
        foreach ($rows as $u) {
            if ($n >= $max) break;
            $sent = max(1, (int) $u['sent']);              // the sign-up email counts as the first
            if (!isset($due[$sent])) continue;             // 3 reminders already sent
            $age = $nowTs - (int) strtotime($u['created_at'] . ' UTC');
            $since = $u['last_sent'] ? $nowTs - (int) strtotime($u['last_sent'] . ' UTC') : PHP_INT_MAX;
            if ($age < $due[$sent] || $since < 86400) continue;
            $code = self::newCode($u, 'verify', 48 * 3600);
            [$subject, $html, $text] = EmailTemplates::code($u['name'], $code, 'remind');
            Mailer::queue((int) $u['id'], $u['email'], $subject, $html, $text);
            $n++;
        }
        return $n ? ["verify reminders: $n queued"] : [];
    }

    /** Checks a code; throws on failure. */
    private static function useCode(array $user, string $purpose, string $code): void
    {
        $row = Db::one(
            'SELECT * FROM password_resets WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$user['id'], $purpose, now()]
        );
        if (!$row || (int) $row['attempts'] >= 5) throw new ApiError('This code has expired. Request a new one.', 400);
        if (!password_verify(trim($code), $row['code_hash'])) {
            Db::exec('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            throw new ApiError('Incorrect code. Check your email and try again.', 400);
        }
        Db::exec('UPDATE password_resets SET used_at = ? WHERE id = ?', [now(), $row['id']]);
    }

    public static function verify(): void
    {
        $user = self::user();
        if ($user['email_verified_at'] === null) {
            self::useCode($user, 'verify', (string) (Http::body()['code'] ?? ''));
            Db::exec('UPDATE users SET email_verified_at = ? WHERE id = ?', [now(), $user['id']]);
        }
        Http::send(['user' => self::publicUser(Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]))]);
    }

    public static function resendVerification(): void
    {
        $user = self::user();
        if ($user['email_verified_at'] !== null) {
            Http::send(['ok' => true, 'message' => 'Your email is already verified.']);
            return;
        }
        Http::rateLimit('verify:' . $user['id'], 3, 900);
        $sent = self::sendCode($user, 'verify');
        if (!$sent) throw new ApiError('We couldn\'t send the email right now. Please try again in a few minutes.', 503);
        Http::send(['ok' => true, 'message' => "We sent a new 6-digit code to {$user['email']}."]);
    }

    public static function login(): void
    {
        $b = Http::body();
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $pw = (string) ($b['password'] ?? '');
        Http::rateLimit('login:' . Http::ip() . ':' . $email, 8, 900);
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($pw, $user['password_hash'])) {
            throw new ApiError('Incorrect email or password.', 401);
        }
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
        self::session($user);
    }

    public static function forgot(): void
    {
        $email = self::validEmail((string) (Http::body()['email'] ?? ''));
        Http::rateLimit('forgot:' . $email, 3, 900);
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user) self::sendCode($user, 'reset');
        // Same response either way so emails can't be enumerated.
        Http::send(['ok' => true, 'message' => 'If an account exists for this email, a 6-digit code has been sent.']);
    }

    public static function reset(): void
    {
        $b = Http::body();
        $email = self::validEmail((string) ($b['email'] ?? ''));
        $code = trim((string) ($b['code'] ?? ''));
        $pw = self::validPassword((string) ($b['password'] ?? ''));
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user) throw new ApiError('This code has expired. Request a new one.', 400);
        self::useCode($user, 'reset', $code);
        // Receiving the code proves the email address belongs to the user.
        Db::exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $user['id']]);
        Db::exec('UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
        self::session(Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]));
    }
}
