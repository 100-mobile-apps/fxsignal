<?php
declare(strict_types=1);

/**
 * Premium subscriptions sold through Google Play Billing.
 *
 * Security model
 *  - The app never decides who is premium. After a purchase it sends the
 *    purchase token here; the server asks Google (Play Developer API) whether
 *    the token is real, which product it is for, and until when it is paid.
 *  - Every purchase carries an obfuscated account id (an HMAC of the user id)
 *    that the app sets at checkout. A token is only accepted for the account
 *    that bought it, and a token can belong to one account only, so a
 *    subscription cannot be shared by logging in to different accounts.
 *  - Locked data (entry, stop loss, take profit of live signals) is removed on
 *    the server for non-premium users, so a modified app cannot reveal it.
 *  - Subscriptions are re-checked with Google by the cron job, and instantly
 *    when Google sends a Real-time developer notification (cancel, refund,
 *    renewal, payment failure).
 */
final class Billing
{
    /** Test hook: replaces the Google API call (fn(string $token): array). */
    public static $fetcher = null;

    private const ENTITLED = ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'SUBSCRIPTION_STATE_CANCELED'];

    // ---- entitlement --------------------------------------------------------

    /**
     * Locking is switched on with config 'premium_enforced' => true once the app
     * update with the paywall is live on Google Play. Until then everyone keeps
     * full access, so current users are never asked to update to a version that
     * isn't in the store yet.
     */
    public static function enforced(): bool
    {
        return (bool) config('premium_enforced', false);
    }

    /**
     * Lock applies to this user: everyone once 'premium_enforced' is true, or only the
     * accounts in 'premium_test_emails' before launch (to test the paywall safely).
     */
    public static function enforcedFor(array $user): bool
    {
        if (self::enforced()) return true;
        $tests = array_map('strtolower', (array) config('premium_test_emails', []));
        return $tests && in_array(strtolower((string) ($user['email'] ?? '')), $tests, true);
    }

    /** Premium-only markets (e.g. gold, crypto) — locked whatever the signal strength. */
    public static function premiumMarkets(): array
    {
        return (array) config('premium_markets', ['XAU/USD', 'BTC/USD', 'ETH/USD']);
    }

    /** Signals at or below this strength are free on the other markets. */
    public static function freeMaxStrength(): int
    {
        return (int) config('free_max_strength', 70);
    }

    /** Does this signal need Premium? (ignores who is asking) */
    public static function signalIsPremium(array $signal): bool
    {
        return in_array($signal['pair'], self::premiumMarkets(), true)
            || (int) $signal['confidence'] > self::freeMaxStrength();
    }

    /** Should this live signal's levels be hidden from this user? */
    public static function lockedFor(array $user, array $signal): bool
    {
        return $signal['status'] === 'running' && self::signalIsPremium($signal) && !self::isPremium($user);
    }

    public static function isPremium(array $user, ?int $now = null): bool
    {
        if (!self::enforcedFor($user)) return true;
        if (self::isComplimentary($user)) return true;
        $until = $user['premium_until'] ?? null;
        return $until !== null && strtotime($until . ' UTC') > ($now ?? time());
    }

    private static function isComplimentary(array $user): bool
    {
        $list = array_map('strtolower', (array) config('premium_emails', []));
        return $list && in_array(strtolower((string) ($user['email'] ?? '')), $list, true);
    }

    /** Value the app passes to Google Play as obfuscatedAccountId (max 64 chars). */
    public static function accountTag(int $userId): string
    {
        return substr(hash_hmac('sha256', "play-account:$userId", (string) config('jwt_secret')), 0, 64);
    }

    public static function publicFields(array $u): array
    {
        $premium = self::isPremium($u);
        return [
            'premium' => $premium,
            'premium_until' => self::isComplimentary($u) || !self::enforcedFor($u) ? null : iso($u['premium_until'] ?? null),
            'premium_plan' => $premium ? ($u['premium_plan'] ?? (self::isComplimentary($u) || !self::enforcedFor($u) ? 'complimentary' : null)) : null,
            'billing_account_id' => self::accountTag((int) $u['id']),
        ];
    }

    // ---- API: POST /billing/verify ------------------------------------------

    public static function verify(): void
    {
        $user = Auth::user();
        Http::rateLimit('billing:' . $user['id'], 20, 3600);
        $b = Http::body();
        $productId = (string) ($b['product_id'] ?? '');
        $token = (string) ($b['purchase_token'] ?? '');
        if (!in_array($productId, self::products(), true)) throw new ApiError('Unknown subscription product.', 422);
        if ($token === '' || strlen($token) > 1000) throw new ApiError('Missing purchase token.', 422);

        $owner = Db::one('SELECT user_id FROM subscriptions WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($owner && (int) $owner['user_id'] !== (int) $user['id']) {
            throw new ApiError('This Google Play subscription is already linked to another Forex Signals Live account. Log in with that account to use it.', 409);
        }

        $data = self::fetch($token);
        $tag = $data['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;
        if ($tag !== null && !hash_equals(self::accountTag((int) $user['id']), (string) $tag)) {
            throw new ApiError('This subscription was bought from a different account. Log in with the account you subscribed with.', 409);
        }
        if (!self::lineItem($data, $productId)) throw new ApiError('Purchase does not match the subscription product.', 422);

        self::store((int) $user['id'], $productId, $token, $data);
        self::acknowledge($productId, $token, $data);
        $fresh = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        Http::send(['user' => Auth::publicUser($fresh)]);
    }

    // ---- API: POST /billing/rtdn?secret=... (Google Pub/Sub push) -------------

    public static function rtdn(): void
    {
        $secret = (string) (config('play', [])['rtdn_secret'] ?? '');
        if ($secret === '' || !hash_equals($secret, (string) ($_GET['secret'] ?? ''))) {
            throw new ApiError('Forbidden', 403);
        }
        $msg = Http::body()['message']['data'] ?? '';
        $n = json_decode((string) base64_decode((string) $msg, true), true) ?: [];
        $token = (string) ($n['subscriptionNotification']['purchaseToken'] ?? '');
        if ($token !== '' && ($n['packageName'] ?? '') === self::package()) {
            $row = Db::one('SELECT * FROM subscriptions WHERE token_hash = ?', [hash('sha256', $token)]);
            // Never trust the notification itself: always re-read the state from Google.
            if ($row) {
                try { self::refresh($row); } catch (Throwable $e) { error_log('RTDN refresh failed: ' . $e->getMessage()); }
            }
        }
        Http::send(['ok' => true]); // 200 so Pub/Sub stops retrying
    }

    // ---- API: PUT /me/device, DELETE /me/device -------------------------------

    public static function saveDevice(): void
    {
        $user = Auth::user();
        $token = trim((string) (Http::body()['token'] ?? ''));
        if ($token === '' || strlen($token) > 400) throw new ApiError('Invalid device token.', 422);
        Db::exec('DELETE FROM devices WHERE token = ?', [$token]);
        Db::exec('INSERT INTO devices (user_id, token, updated_at) VALUES (?, ?, ?)', [$user['id'], $token, now()]);
        // Keep at most 5 devices per account.
        $old = Db::all('SELECT id FROM devices WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 100 OFFSET 5', [$user['id']]);
        foreach ($old as $o) Db::exec('DELETE FROM devices WHERE id = ?', [$o['id']]);
        Http::send(['ok' => true]);
    }

    public static function deleteDevice(): void
    {
        $user = Auth::user();
        Db::exec('DELETE FROM devices WHERE user_id = ? AND token = ?', [$user['id'], (string) (Http::body()['token'] ?? '')]);
        Http::send(['ok' => true]);
    }

    /** Device tokens of premium users who want push alerts for this pair. */
    public static function premiumDeviceTokens(string $pair): array
    {
        $rows = Db::all('SELECT d.token, u.id, u.email, u.premium_until, u.notify_pairs FROM devices d JOIN users u ON u.id = d.user_id
                         WHERE u.notify_signals = 1');
        $out = [];
        foreach ($rows as $r) {
            if (!self::isPremium($r)) continue;
            $pairs = $r['notify_pairs'] ? json_decode($r['notify_pairs'], true) : null;
            if (is_array($pairs) && !in_array($pair, $pairs, true)) continue;
            $out[] = $r['token'];
        }
        return array_values(array_unique($out));
    }

    public static function dropDevice(string $token): void
    {
        Db::exec('DELETE FROM devices WHERE token = ?', [$token]);
    }

    // ---- cron ---------------------------------------------------------------

    /** Re-checks subscriptions that are about to renew/expire or were not checked for a day. */
    public static function refreshDue(int $now, int $max = 25): array
    {
        if (!self::configured() && self::$fetcher === null) return [];
        $rows = Db::all(
            "SELECT * FROM subscriptions WHERE state NOT IN ('REPLACED', 'SUBSCRIPTION_STATE_EXPIRED')
             AND ((expires_at IS NOT NULL AND expires_at < ?) OR last_checked_at < ?)
             ORDER BY last_checked_at LIMIT $max",
            [now($now + 6 * 3600), now($now - 86400)]
        );
        $log = [];
        foreach ($rows as $row) {
            try {
                $state = self::refresh($row, $now);
                $log[] = "billing: subscription #{$row['id']} (user {$row['user_id']}) $state";
            } catch (Throwable $e) {
                Db::exec('UPDATE subscriptions SET last_checked_at = ? WHERE id = ?', [now($now), $row['id']]);
                $log[] = "billing: subscription #{$row['id']} check failed: " . $e->getMessage();
            }
        }
        // Users whose paid time ran out without a renewal.
        $expired = Db::exec('UPDATE users SET premium_plan = NULL WHERE premium_until IS NOT NULL AND premium_until < ? AND premium_plan IS NOT NULL', [now($now)]);
        if ($expired) $log[] = "billing: $expired subscription(s) ended";
        return $log;
    }

    // ---- internals ------------------------------------------------------------

    private static function refresh(array $row, ?int $now = null): string
    {
        $data = self::fetch(self::token($row));
        self::store((int) $row['user_id'], $row['product_id'], self::token($row), $data, $now);
        return (string) ($data['subscriptionState'] ?? 'unknown');
    }

    private static function store(int $userId, string $productId, string $token, array $data, ?int $now = null): void
    {
        $now = $now ?? time();
        $state = (string) ($data['subscriptionState'] ?? 'UNKNOWN');
        $item = self::lineItem($data, $productId);
        $expiry = isset($item['expiryTime']) ? strtotime((string) $item['expiryTime']) : false;
        $expiresAt = $expiry ? now($expiry) : null;
        $hash = hash('sha256', $token);
        $plan = $item['offerDetails']['basePlanId'] ?? (str_contains($productId, 'year') ? 'yearly' : 'monthly');
        $auto = !empty($item['autoRenewingPlan']['autoRenewEnabled']) ? 1 : 0;
        $sealed = self::seal($token);

        if (Db::one('SELECT id FROM subscriptions WHERE token_hash = ?', [$hash])) {
            Db::exec('UPDATE subscriptions SET product_id = ?, plan = ?, state = ?, expires_at = ?, auto_renewing = ?, order_id = ?,
                      test_purchase = ?, last_checked_at = ?, updated_at = ? WHERE token_hash = ?',
                [$productId, $plan, $state, $expiresAt, $auto, $data['latestOrderId'] ?? null,
                 isset($data['testPurchase']) ? 1 : 0, now($now), now($now), $hash]);
        } else {
            Db::exec('INSERT INTO subscriptions (user_id, product_id, plan, purchase_token, token_hash, state, expires_at, auto_renewing,
                      order_id, test_purchase, last_checked_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $productId, $plan, $sealed, $hash, $state, $expiresAt, $auto, $data['latestOrderId'] ?? null,
                 isset($data['testPurchase']) ? 1 : 0, now($now), now($now), now($now)]);
        }
        // An upgrade/downgrade or resubscribe replaces the previous token.
        if (!empty($data['linkedPurchaseToken'])) {
            Db::exec("UPDATE subscriptions SET state = 'REPLACED', updated_at = ? WHERE token_hash = ? AND user_id = ?",
                [now($now), hash('sha256', (string) $data['linkedPurchaseToken']), $userId]);
        }
        self::recompute($userId, $now);
    }

    /** premium_until = the latest paid-through time of the user's entitled subscriptions. */
    private static function recompute(int $userId, int $now): void
    {
        $best = null;
        $plan = null;
        foreach (Db::all('SELECT * FROM subscriptions WHERE user_id = ?', [$userId]) as $s) {
            if (!in_array($s['state'], self::ENTITLED, true) || !$s['expires_at']) continue;
            if ($best === null || $s['expires_at'] > $best) { $best = $s['expires_at']; $plan = $s['plan']; }
        }
        if ($best !== null && strtotime($best . ' UTC') <= $now) $plan = null;
        Db::exec('UPDATE users SET premium_until = ?, premium_plan = ? WHERE id = ?', [$best, $plan, $userId]);
    }

    private static function lineItem(array $data, string $productId): ?array
    {
        foreach ((array) ($data['lineItems'] ?? []) as $item) {
            if (($item['productId'] ?? '') === $productId) return $item;
        }
        return null;
    }

    private static function fetch(string $token): array
    {
        if (self::$fetcher !== null) return (self::$fetcher)($token);
        if (!self::configured()) throw new ApiError('Subscriptions are not set up on the server yet. Please try again later.', 503);
        $c = config('play');
        $at = GoogleAuth::accessToken($c['service_account_file'], 'https://www.googleapis.com/auth/androidpublisher');
        $url = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/' . rawurlencode(self::package())
            . '/purchases/subscriptionsv2/tokens/' . rawurlencode($token);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $at"], CURLOPT_TIMEOUT => 20]);
        $res = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404 || $code === 410 || $code === 400) throw new ApiError('Google Play did not recognise this purchase.', 422);
        if ($code !== 200) {
            error_log("Play API $code: $res");
            throw new ApiError('Could not confirm the purchase with Google Play right now. Please try again shortly.', 502);
        }
        return json_decode($res, true) ?: [];
    }

    private static function acknowledge(string $productId, string $token, array $data): void
    {
        if (($data['acknowledgementState'] ?? '') !== 'ACKNOWLEDGEMENT_STATE_PENDING' || self::$fetcher !== null || !self::configured()) return;
        try {
            $c = config('play');
            $at = GoogleAuth::accessToken($c['service_account_file'], 'https://www.googleapis.com/auth/androidpublisher');
            $url = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/' . rawurlencode(self::package())
                . '/purchases/subscriptions/' . rawurlencode($productId) . '/tokens/' . rawurlencode($token) . ':acknowledge';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $at", 'Content-Type: application/json'], CURLOPT_TIMEOUT => 15]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            error_log('Play acknowledge failed: ' . $e->getMessage()); // the app also acknowledges
        }
    }

    private static function configured(): bool
    {
        $c = config('play', []);
        return !empty($c['package_name']) && !empty($c['service_account_file']) && is_file($c['service_account_file']);
    }

    private static function package(): string
    {
        return (string) (config('play', [])['package_name'] ?? '');
    }

    private static function products(): array
    {
        return (array) (config('play', [])['products'] ?? ['fx_premium_monthly', 'fx_premium_yearly']);
    }

    // Purchase tokens are stored encrypted (AES-256-GCM, key derived from jwt_secret).
    private static function key(): string
    {
        return hash('sha256', 'purchase-token:' . config('jwt_secret'), true);
    }

    private static function seal(string $token): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($token, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    private static function token(array $row): string
    {
        $raw = base64_decode((string) $row['purchase_token'], true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Stored purchase token is unreadable');
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($pt === false) throw new RuntimeException('Stored purchase token is unreadable');
        return $pt;
    }
}
