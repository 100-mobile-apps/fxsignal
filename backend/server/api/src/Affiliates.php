<?php
declare(strict_types=1);

/**
 * Affiliate programme.
 *
 *  - A user joins and gets a referral code; their link is https://SITE/?ref=CODE.
 *  - People who sign up through the link (website cookie, or the code typed in
 *    the app) are stored in users.referred_by.
 *  - Every payment those users make (Paystack on the website or a Google Play
 *    renewal) earns the affiliate a commission (default 30%, recurring).
 *  - Commissions are held for `hold_days` (refund window), then become
 *    available; the affiliate requests a payout and the admin pays it manually.
 *  - Affiliates can add their own broker referral link. After the admin
 *    approves it, the users they referred see that link on the
 *    "Open a broker account" button, so the broker pays the affiliate directly.
 *
 * All amounts are kept in USD.
 */
final class Affiliates
{
    public const STATUSES = ['pending', 'active', 'blocked'];
    public const PAYOUT_METHODS = ['bank' => 'Bank transfer (Nigeria)', 'usdt' => 'USDT (TRC20)', 'paypal' => 'PayPal', 'other' => 'Other'];

    public static function settings(): array
    {
        return (array) config('affiliate', []) + [
            'enabled' => true,
            'rate' => 30,          // % of each payment
            'hold_days' => 14,     // refund window before a commission can be paid out
            'min_payout' => 10,    // USD
            'cookie_days' => 60,   // how long a referral link visit is remembered
            'auto_approve' => true,
        ];
    }

    public static function enabled(): bool
    {
        return (bool) self::settings()['enabled'];
    }

    public static function forUser(int $userId): ?array
    {
        return Db::one('SELECT * FROM affiliates WHERE user_id = ?', [$userId]);
    }

    public static function byCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4,20}$/', $code)) return null;
        return Db::one('SELECT * FROM affiliates WHERE code = ?', [$code]);
    }

    /** A code that can refer people right now. */
    public static function activeByCode(string $code): ?array
    {
        $a = self::byCode($code);
        return $a && $a['status'] === 'active' && self::enabled() ? $a : null;
    }

    public static function rateFor(array $aff): float
    {
        return $aff['rate'] !== null ? (float) $aff['rate'] : (float) self::settings()['rate'];
    }

    /** Joins the programme. Returns the affiliate row. */
    public static function join(array $user, ?string $wanted = null): array
    {
        if (!self::enabled()) throw new ApiError('The affiliate programme is closed right now.', 403);
        if ($existing = self::forUser((int) $user['id'])) return $existing;
        if ($user['email_verified_at'] === null) throw new ApiError('Verify your email address first.', 403);
        $code = null;
        if ($wanted !== null && trim($wanted) !== '') {
            $w = strtoupper(trim($wanted));
            if (!preg_match('/^[A-Z0-9]{4,12}$/', $w)) throw new ApiError('Your code can use 4–12 letters and numbers.', 422);
            if (self::byCode($w)) throw new ApiError('That code is taken. Try another one.', 409);
            $code = $w;
        }
        if ($code === null) {
            $base = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $user['name']));
            $base = substr($base !== '' ? $base : 'FX', 0, 6);
            for ($i = 0; $i < 20 && $code === null; $i++) {
                $try = $base . random_int(10, 999);
                if (!self::byCode($try)) $code = $try;
            }
            $code = $code ?? strtoupper(bin2hex(random_bytes(4)));
        }
        $status = self::settings()['auto_approve'] ? 'active' : 'pending';
        Db::exec('INSERT INTO affiliates (user_id, code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [(int) $user['id'], $code, $status, now(), now()]);
        return self::forUser((int) $user['id']);
    }

    /**
     * Links a new or recent account to the affiliate who referred it.
     * Allowed once, within 30 days of sign-up, before the first payment, and never to yourself.
     */
    public static function attach(int $userId, string $code, string $via = 'link'): bool
    {
        $aff = self::activeByCode($code);
        if (!$aff || (int) $aff['user_id'] === $userId) return false;
        $u = Db::one('SELECT id, email, referred_by, created_at FROM users WHERE id = ?', [$userId]);
        if (!$u || $u['referred_by'] !== null) return false;
        if (strtotime($u['created_at'] . ' UTC') < time() - 30 * 86400) return false;
        if (Db::one("SELECT id FROM payments WHERE user_id = ? AND status = 'paid' LIMIT 1", [$userId])) return false;
        Db::exec('UPDATE users SET referred_by = ?, referred_at = ? WHERE id = ? AND referred_by IS NULL', [(int) $aff['user_id'], now(), $userId]);
        return true;
    }

    /** PUT /me/referrer {code} — for the app's "Have a referral code?" field. */
    public static function apiSetReferrer(): void
    {
        $u = Auth::user();
        $code = (string) (Http::body()['code'] ?? '');
        Http::rateLimit('referrer:' . $u['id'], 10, 3600);
        if ($u['referred_by'] !== null) throw new ApiError('Your account is already linked to a referral code.', 409);
        if (!self::attach((int) $u['id'], $code, 'code')) throw new ApiError('This referral code can\'t be used. Check it and try again.', 422);
        Account::me();
    }

    /** Records a referral-link visit (one per IP per affiliate per day). */
    public static function click(array $aff, string $kind, string $ip): void
    {
        $hash = substr(hash('sha256', $ip . '|' . config('jwt_secret')), 0, 16);
        $seen = Db::one('SELECT id FROM referral_clicks WHERE affiliate_id = ? AND kind = ? AND ip_hash = ? AND created_at >= ?',
            [(int) $aff['user_id'], $kind, $hash, now(time() - 86400)]);
        if (!$seen) Db::exec('INSERT INTO referral_clicks (affiliate_id, kind, ip_hash, created_at) VALUES (?, ?, ?, ?)',
            [(int) $aff['user_id'], $kind, $hash, now()]);
    }

    // ---- broker link ---------------------------------------------------------

    public static function defaultBroker(): ?array
    {
        $b = (array) config('broker_default', []);
        return !empty($b['url']) ? ['name' => (string) ($b['name'] ?? 'our partner broker'), 'url' => (string) $b['url'], 'affiliate_id' => null] : null;
    }

    /** The broker link this user should see: their referrer's approved link, otherwise the default. */
    public static function brokerFor(array $user): ?array
    {
        if (!empty($user['referred_by'])) {
            $a = self::forUser((int) $user['referred_by']);
            if ($a && $a['status'] === 'active' && $a['broker_status'] === 'approved' && $a['broker_url']) {
                return ['name' => (string) ($a['broker_name'] ?: 'our partner broker'), 'url' => (string) $a['broker_url'], 'affiliate_id' => (int) $a['user_id']];
            }
        }
        return self::defaultBroker();
    }

    public static function validBrokerUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || strlen($url) > 300) {
            throw new ApiError('Enter the full broker link starting with https://', 422);
        }
        return $url;
    }

    // ---- money ---------------------------------------------------------------

    /** Called for every new payment. Creates the referrer's commission (once per payment). */
    public static function onPayment(int $paymentId): ?int
    {
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'paid' || (int) $p['test'] === 1 || (float) $p['amount_usd'] <= 0) return null;
        $u = Db::one('SELECT id, name, referred_by FROM users WHERE id = ?', [(int) $p['user_id']]);
        if (!$u || !$u['referred_by'] || (int) $u['referred_by'] === (int) $u['id']) return null;
        $aff = self::forUser((int) $u['referred_by']);
        if (!$aff || $aff['status'] !== 'active' || !self::enabled()) return null;
        if (Db::one('SELECT id FROM commissions WHERE payment_id = ?', [$paymentId])) return null;
        $rate = self::rateFor($aff);
        $amount = round((float) $p['amount_usd'] * $rate / 100, 2);
        if ($amount <= 0) return null;
        $hold = (int) self::settings()['hold_days'];
        $id = Db::insert('INSERT INTO commissions (affiliate_id, referred_user_id, payment_id, rate, amount_usd, status, available_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $aff['user_id'], (int) $u['id'], $paymentId, $rate, $amount, 'pending', now(time() + $hold * 86400), now()]);
        try {
            $owner = Db::one('SELECT id, name, email, email_marketing FROM users WHERE id = ?', [(int) $aff['user_id']]);
            if ($owner) {
                [$subject, $html, $text] = EmailTemplates::commission((string) $owner['name'], $amount, (string) $p['plan'], $hold);
                Mailer::queue((int) $owner['id'], (string) $owner['email'], $subject, $html, $text);
            }
        } catch (Throwable $e) {
            error_log('commission email failed: ' . $e->getMessage());
        }
        return $id;
    }

    /** A refunded payment takes its commission back (unless it was already paid out). */
    public static function onRefund(int $paymentId): void
    {
        Db::exec("UPDATE commissions SET status = 'reversed' WHERE payment_id = ? AND status IN ('pending', 'approved') AND payout_id IS NULL", [$paymentId]);
    }

    /** Commissions past their hold period become available. Run by cron and on page views. */
    public static function mature(?int $now = null): int
    {
        return Db::exec("UPDATE commissions SET status = 'approved' WHERE status = 'pending' AND available_at <= ?", [now($now ?? time())]);
    }

    public static function balances(int $affId): array
    {
        self::mature();
        $sum = fn(string $where, array $p = []) => round((float) (Db::one("SELECT COALESCE(SUM(amount_usd), 0) AS s FROM commissions WHERE affiliate_id = ? AND $where", array_merge([$affId], $p))['s'] ?? 0), 2);
        return [
            'pending' => $sum("status = 'pending'"),
            'available' => $sum("status = 'approved' AND payout_id IS NULL"),
            'requested' => $sum("status = 'approved' AND payout_id IS NOT NULL"),
            'paid' => $sum("status = 'paid'"),
            'lifetime' => $sum("status IN ('pending', 'approved', 'paid')"),
        ];
    }

    public static function stats(int $affId): array
    {
        $n = fn(string $sql, array $p) => (int) (Db::one($sql, $p)['n'] ?? 0);
        return [
            'visits' => $n("SELECT COUNT(*) AS n FROM referral_clicks WHERE affiliate_id = ? AND kind = 'visit'", [$affId]),
            'broker_clicks' => $n("SELECT COUNT(*) AS n FROM referral_clicks WHERE affiliate_id = ? AND kind = 'broker'", [$affId]),
            'signups' => $n('SELECT COUNT(*) AS n FROM users WHERE referred_by = ?', [$affId]),
            'premium' => $n('SELECT COUNT(*) AS n FROM users WHERE referred_by = ? AND premium_until > ?', [$affId, now()]),
            'paying_ever' => $n("SELECT COUNT(DISTINCT p.user_id) AS n FROM payments p JOIN users u ON u.id = p.user_id WHERE u.referred_by = ? AND p.status = 'paid'", [$affId]),
        ];
    }

    public static function savePayoutDetails(int $affId, string $method, string $details): void
    {
        if (!isset(self::PAYOUT_METHODS[$method])) throw new ApiError('Choose a payout method.', 422);
        $details = trim($details);
        if (mb_strlen($details) < 6 || mb_strlen($details) > 500) throw new ApiError('Enter your payout details (account number and name, wallet address or PayPal email).', 422);
        Db::exec('UPDATE affiliates SET payout_method = ?, payout_details = ?, updated_at = ? WHERE user_id = ?', [$method, $details, now(), $affId]);
    }

    /** Moves every available commission into a new payout request. */
    public static function requestPayout(int $affId): int
    {
        $aff = self::forUser($affId);
        if (!$aff || $aff['status'] !== 'active') throw new ApiError('Your affiliate account is not active.', 403);
        if (!$aff['payout_method'] || !$aff['payout_details']) throw new ApiError('Add your payout details first.', 422);
        if (Db::one("SELECT id FROM payouts WHERE affiliate_id = ? AND status = 'requested'", [$affId])) {
            throw new ApiError('You already have a payout request waiting. It will be processed soon.', 409);
        }
        $bal = self::balances($affId);
        $min = (float) self::settings()['min_payout'];
        if ($bal['available'] < $min) throw new ApiError(sprintf('The minimum payout is $%s. You have $%s available.', self::money($min), self::money($bal['available'])), 422);
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $id = Db::insert('INSERT INTO payouts (affiliate_id, amount_usd, method, details, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$affId, $bal['available'], $aff['payout_method'], $aff['payout_details'], 'requested', now()]);
            Db::exec("UPDATE commissions SET payout_id = ? WHERE affiliate_id = ? AND status = 'approved' AND payout_id IS NULL", [$id, $affId]);
            $total = (float) Db::one('SELECT COALESCE(SUM(amount_usd), 0) AS s FROM commissions WHERE payout_id = ?', [$id])['s'];
            Db::exec('UPDATE payouts SET amount_usd = ? WHERE id = ?', [round($total, 2), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Admin: mark a payout as paid (or rejected, which releases its commissions). */
    public static function processPayout(int $payoutId, bool $paid, string $reference, string $note): void
    {
        $p = Db::one('SELECT * FROM payouts WHERE id = ?', [$payoutId]);
        if (!$p || $p['status'] !== 'requested') throw new ApiError('This payout was already processed.', 409);
        if ($paid) {
            Db::exec("UPDATE payouts SET status = 'paid', reference = ?, admin_note = ?, processed_at = ? WHERE id = ?", [mb_substr($reference, 0, 120), mb_substr($note, 0, 255), now(), $payoutId]);
            Db::exec("UPDATE commissions SET status = 'paid' WHERE payout_id = ?", [$payoutId]);
            $u = Db::one('SELECT id, name, email FROM users WHERE id = ?', [(int) $p['affiliate_id']]);
            if ($u) {
                [$subject, $html, $text] = EmailTemplates::payoutPaid((string) $u['name'], (float) $p['amount_usd'], $reference);
                Mailer::queue((int) $u['id'], (string) $u['email'], $subject, $html, $text);
            }
        } else {
            Db::exec("UPDATE payouts SET status = 'rejected', admin_note = ?, processed_at = ? WHERE id = ?", [mb_substr($note, 0, 255), now(), $payoutId]);
            Db::exec('UPDATE commissions SET payout_id = NULL WHERE payout_id = ?', [$payoutId]);
        }
    }

    public static function money(float $v): string
    {
        return number_format($v, 2);
    }
}
