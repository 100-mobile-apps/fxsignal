<?php
declare(strict_types=1);

/**
 * Premium on the website, paid with Paystack (card, bank transfer, USSD).
 *
 * Flow: the website asks Paystack for a checkout page (transaction/initialize
 * with the plan code, so Paystack creates a recurring subscription), the user
 * pays, Paystack redirects back and also calls the webhook. Both paths verify
 * the transaction with Paystack before anything is recorded, and payments are
 * stored once (unique reference), so it doesn't matter which arrives first.
 * Renewals arrive as charge.success webhooks for the same customer.
 *
 * Secret key: config.php → 'paystack' => ['secret_key' => 'sk_live_...'] (never in the database).
 */
final class Paystack
{
    /** Test hook: fn(string $method, string $path, ?array $body): array */
    public static $http = null;

    public static function secret(): string
    {
        return (string) (config('paystack', [])['secret_key'] ?? '');
    }

    public static function configured(): bool
    {
        return self::secret() !== '' || self::$http !== null;
    }

    public static function testMode(): bool
    {
        return str_starts_with(self::secret(), 'sk_test_');
    }

    public static function request(string $method, string $path, ?array $body = null): array
    {
        if (self::$http !== null) return (self::$http)($method, $path, $body);
        if (!self::configured()) throw new ApiError('Card payments are not set up yet. Please try again later.', 503);
        $ch = curl_init('https://api.paystack.co' . $path);
        $headers = ['Authorization: Bearer ' . self::secret(), 'Accept: application/json'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($raw, true) ?: [];
        if ($code < 200 || $code >= 300 || empty($json['status'])) {
            error_log("Paystack $method $path -> $code $raw");
            throw new ApiError((string) ($json['message'] ?? 'Payment service error. Please try again.'), $code >= 400 && $code < 500 ? 422 : 502);
        }
        return $json;
    }

    /** Smallest currency unit (kobo/cents). */
    private static function minor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Starts checkout; returns the Paystack page to send the user to. */
    public static function checkout(array $user, string $plan, string $callbackUrl): string
    {
        if (!in_array($plan, ['monthly', 'yearly'], true)) throw new ApiError('Choose monthly or yearly.', 422);
        if ($user['email_verified_at'] === null) throw new ApiError('Verify your email address before subscribing.', 403);
        Http::rateLimit('paystack:' . $user['id'], 10, 3600);
        $p = Payments::webPricing();
        $amount = (float) $p[$plan . '_amount'];
        if ($amount <= 0) throw new ApiError('This plan is not available right now.', 503);
        $body = [
            'email' => $user['email'],
            'amount' => self::minor($amount),
            'currency' => strtoupper((string) $p['currency']),
            'reference' => 'FXW' . $user['id'] . 'T' . bin2hex(random_bytes(6)),
            'callback_url' => $callbackUrl,
            'metadata' => ['user_id' => (int) $user['id'], 'plan' => $plan, 'cancel_action' => $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'cancelled=1'],
        ];
        if (!empty($p[$plan . '_plan'])) $body['plan'] = (string) $p[$plan . '_plan'];
        $res = self::request('POST', '/transaction/initialize', $body);
        $url = (string) ($res['data']['authorization_url'] ?? '');
        if (!str_starts_with($url, 'https://')) throw new ApiError('Could not start checkout. Please try again.', 502);
        return $url;
    }

    /** After the redirect back: verify with Paystack and record. Returns the payment status. */
    public static function confirm(string $reference, int $expectUserId): string
    {
        if (!preg_match('/^[A-Za-z0-9_.=-]{6,100}$/', $reference)) throw new ApiError('Unknown payment reference.', 422);
        $res = self::request('GET', '/transaction/verify/' . rawurlencode($reference));
        $data = (array) ($res['data'] ?? []);
        $uid = (int) ($data['metadata']['user_id'] ?? 0);
        if ($uid && $uid !== $expectUserId) throw new ApiError('This payment belongs to a different account.', 409);
        if (($data['status'] ?? '') === 'success') self::handleCharge($data);
        return (string) ($data['status'] ?? 'unknown');
    }

    /** Records a successful charge (first payment or renewal). */
    public static function handleCharge(array $data): ?int
    {
        if (($data['status'] ?? '') !== 'success') return null;
        $ref = (string) ($data['reference'] ?? '');
        if ($ref === '') return null;
        $customer = (array) ($data['customer'] ?? []);
        $custCode = (string) ($customer['customer_code'] ?? '');
        $user = self::findUser($data);
        if (!$user) {
            error_log("Paystack charge $ref: no matching user");
            return null;
        }
        $pricing = Payments::webPricing();
        $planCode = is_array($data['plan'] ?? null) ? (string) ($data['plan']['plan_code'] ?? '') : (string) ($data['plan'] ?? '');
        $plan = (string) ($data['metadata']['plan'] ?? '');
        if (!in_array($plan, ['monthly', 'yearly'], true)) {
            if ($planCode !== '' && $planCode === (string) $pricing['yearly_plan']) $plan = 'yearly';
            elseif ($planCode !== '' && $planCode === (string) $pricing['monthly_plan']) $plan = 'monthly';
            else $plan = (float) ($data['amount'] ?? 0) / 100 >= (float) $pricing['yearly_amount'] * 0.8 ? 'yearly' : 'monthly';
        }
        $amount = round((float) ($data['amount'] ?? 0) / 100, 2);
        $currency = strtoupper((string) ($data['currency'] ?? $pricing['currency']));
        $paidAt = isset($data['paid_at']) ? strtotime((string) $data['paid_at']) : false;
        $id = Payments::record((int) $user['id'], 'paystack', $ref, $plan, $amount, $currency, Payments::toUsd($amount, $currency),
            ($data['domain'] ?? '') === 'test', $paidAt ?: null);
        self::subscriptionRow((int) $user['id'], $plan, $custCode, null);
        Billing::recompute((int) $user['id'], time());
        return $id;
    }

    private static function findUser(array $data): ?array
    {
        $uid = (int) ($data['metadata']['user_id'] ?? 0);
        if ($uid) {
            $u = Db::one('SELECT * FROM users WHERE id = ?', [$uid]);
            if ($u) return $u;
        }
        $cust = (string) ($data['customer']['customer_code'] ?? '');
        if ($cust !== '') {
            $s = Db::one('SELECT user_id FROM web_subscriptions WHERE customer_code = ? ORDER BY id DESC LIMIT 1', [$cust]);
            if ($s && ($u = Db::one('SELECT * FROM users WHERE id = ?', [(int) $s['user_id']]))) return $u;
        }
        $email = strtolower((string) ($data['customer']['email'] ?? ''));
        return $email !== '' ? Db::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
    }

    /** Creates/updates the user's website subscription row. */
    private static function subscriptionRow(int $userId, string $plan, string $custCode, ?array $extra): void
    {
        $row = $custCode !== ''
            ? Db::one('SELECT * FROM web_subscriptions WHERE user_id = ? AND (customer_code = ? OR customer_code IS NULL) ORDER BY id DESC LIMIT 1', [$userId, $custCode])
            : Db::one('SELECT * FROM web_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        if (!$row) {
            Db::exec('INSERT INTO web_subscriptions (user_id, plan, customer_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $plan, $custCode ?: null, 'active', now(), now()]);
            $row = Db::one('SELECT * FROM web_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        }
        $set = ['plan' => $plan, 'customer_code' => $custCode ?: $row['customer_code'], 'paid_until' => Payments::webPaidUntil($userId), 'updated_at' => now()];
        foreach ((array) $extra as $k => $v) $set[$k] = $v;
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        Db::exec("UPDATE web_subscriptions SET $cols WHERE id = ?", array_merge(array_values($set), [(int) $row['id']]));
    }

    // ---- webhook: POST /api/billing/paystack -----------------------------------

    public static function webhook(): void
    {
        $raw = Http::$bodyOverride ?? (string) file_get_contents('php://input');
        $sig = (string) (Http::header('X-Paystack-Signature') ?? '');
        if (self::secret() === '' || $sig === '' || !hash_equals(hash_hmac('sha512', $raw, self::secret()), $sig)) {
            throw new ApiError('Invalid signature', 401);
        }
        $event = json_decode($raw, true) ?: [];
        $type = (string) ($event['event'] ?? '');
        $data = (array) ($event['data'] ?? []);
        try {
            self::handleEvent($type, $data);
        } catch (ApiError $e) {
            error_log("Paystack webhook $type: " . $e->getMessage());
        }
        Http::send(['ok' => true]);
    }

    public static function handleEvent(string $type, array $data): void
    {
        switch ($type) {
            case 'charge.success':
                // Never trust the webhook body for money: re-read the transaction from Paystack.
                $ref = (string) ($data['reference'] ?? '');
                if ($ref === '') return;
                $verified = (array) (self::request('GET', '/transaction/verify/' . rawurlencode($ref))['data'] ?? []);
                self::handleCharge($verified);
                return;
            case 'subscription.create':
            case 'subscription.not_renew':
            case 'subscription.disable':
            case 'invoice.payment_failed':
            case 'invoice.update':
                $sub = $type === 'subscription.create' || str_starts_with($type, 'subscription.') ? $data : (array) ($data['subscription'] ?? []);
                $cust = (string) ($data['customer']['customer_code'] ?? '');
                $user = self::findUser($data);
                if (!$user) return;
                $status = match ($type) {
                    'subscription.create' => 'active',
                    'subscription.not_renew' => 'non-renewing',
                    'subscription.disable' => 'cancelled',
                    'invoice.payment_failed' => 'attention',
                    default => null,
                };
                $extra = [];
                if (!empty($sub['subscription_code'])) $extra['subscription_code'] = (string) $sub['subscription_code'];
                if (!empty($sub['email_token'])) $extra['email_token'] = (string) $sub['email_token'];
                if (!empty($sub['next_payment_date']) && ($t = strtotime((string) $sub['next_payment_date']))) $extra['next_payment_at'] = now($t);
                if ($status) $extra['status'] = $status;
                $planCode = (string) ($data['plan']['plan_code'] ?? '');
                $plan = $planCode !== '' && $planCode === (string) Payments::webPricing()['yearly_plan'] ? 'yearly' : 'monthly';
                $existing = Db::one('SELECT plan FROM web_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $user['id']]);
                self::subscriptionRow((int) $user['id'], $planCode !== '' ? $plan : (string) ($existing['plan'] ?? 'monthly'), $cust, $extra);
                return;
            case 'refund.processed':
                $ref = (string) ($data['transaction_reference'] ?? ($data['transaction']['reference'] ?? ''));
                $p = $ref !== '' ? Db::one("SELECT id FROM payments WHERE source = 'paystack' AND ref = ?", [$ref]) : null;
                if ($p) Payments::refund((int) $p['id']);
                return;
        }
    }

    // ---- managing a subscription from the website ------------------------------

    public static function current(int $userId): ?array
    {
        try {
            return Db::one('SELECT * FROM web_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Stops future renewals (Premium stays until the paid period ends). */
    public static function cancel(int $userId): void
    {
        $s = self::current($userId);
        if (!$s || !in_array($s['status'], ['active', 'attention'], true)) throw new ApiError('You have no active website subscription.', 404);
        if ($s['subscription_code'] && $s['email_token']) {
            self::request('POST', '/subscription/disable', ['code' => $s['subscription_code'], 'token' => $s['email_token']]);
        }
        Db::exec("UPDATE web_subscriptions SET status = 'cancelled', updated_at = ? WHERE id = ?", [now(), (int) $s['id']]);
    }

    /** Paystack page where the user can change the card on their subscription. */
    public static function manageLink(int $userId): ?string
    {
        $s = self::current($userId);
        if (!$s || !$s['subscription_code']) return null;
        $res = self::request('GET', '/subscription/' . rawurlencode((string) $s['subscription_code']) . '/manage/link');
        $link = (string) ($res['data']['link'] ?? '');
        return str_starts_with($link, 'https://') ? $link : null;
    }

    /** Admin: creates the two recurring plans on Paystack from the website prices. */
    public static function createPlans(): array
    {
        $p = Payments::webPricing();
        $out = [];
        foreach (['monthly' => 'monthly', 'yearly' => 'annually'] as $plan => $interval) {
            $res = self::request('POST', '/plan', [
                'name' => config('app_name', 'FX Signals') . ' Premium ' . ucfirst($plan),
                'interval' => $interval,
                'amount' => self::minor((float) $p[$plan . '_amount']),
                'currency' => strtoupper((string) $p['currency']),
            ]);
            $out[$plan . '_plan'] = (string) ($res['data']['plan_code'] ?? '');
        }
        return $out;
    }
}
