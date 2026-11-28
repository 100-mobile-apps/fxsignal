<?php
declare(strict_types=1);

/**
 * Payment ledger shared by Google Play renewals, Paystack (website) and
 * manual Premium grants from /admin. Commissions are created from it, and
 * website Premium time is calculated from it.
 */
final class Payments
{
    /** Website prices and Paystack plan codes (editable on /admin). */
    public static function webPricing(): array
    {
        return (array) config('web_pricing', []) + [
            'currency' => 'NGN',
            'monthly_amount' => 2500,     // in naira (or dollars if currency is USD)
            'yearly_amount' => 18000,
            'monthly_plan' => '',         // Paystack plan code, e.g. PLN_xxx (created on /admin)
            'yearly_plan' => '',
            'usd_rate' => 1600,           // naira per US dollar, for commissions in USD
        ];
    }

    /** What Google Play pays you per order (after Google's fee), in USD, for commissions. */
    public static function playBase(): array
    {
        return (array) config('play_commission_base', []) + [
            'monthly' => 4.24, 'yearly' => 30.59,          // regular price × 0.85
            'monthly_intro' => 1.28, 'yearly_intro' => 10.20, // launch offer × 0.85
        ];
    }

    public static function toUsd(float $amount, string $currency): float
    {
        if (strtoupper($currency) === 'USD') return round($amount, 2);
        $rate = (float) self::webPricing()['usd_rate'];
        return $rate > 0 ? round($amount / $rate, 2) : 0.0;
    }

    /** Monthly/yearly from a product or base-plan id. */
    public static function planKind(string $plan): string
    {
        return str_contains(strtolower($plan), 'year') || str_contains(strtolower($plan), 'annual') ? 'yearly' : 'monthly';
    }

    /**
     * Inserts a payment once (source + ref are unique). Returns the new id,
     * or null if it was already recorded. Creates the affiliate commission.
     */
    public static function record(int $userId, string $source, string $ref, string $plan, float $amount, string $currency,
                                  float $amountUsd, bool $test = false, ?int $at = null): ?int
    {
        if (Db::one('SELECT id FROM payments WHERE source = ? AND ref = ?', [$source, $ref])) return null;
        try {
            $id = Db::insert('INSERT INTO payments (user_id, source, ref, plan, amount, currency, amount_usd, status, test, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $source, $ref, $plan, $amount, strtoupper($currency), $amountUsd, 'paid', $test ? 1 : 0, now($at ?? time())]);
        } catch (PDOException $e) {
            return null; // recorded by a parallel request (webhook + redirect)
        }
        Affiliates::onPayment($id);
        return $id;
    }

    public static function refund(int $paymentId): void
    {
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] === 'refunded') return;
        Db::exec("UPDATE payments SET status = 'refunded' WHERE id = ?", [$paymentId]);
        Affiliates::onRefund($paymentId);
        Billing::recompute((int) $p['user_id'], time());
    }

    /** Called by Billing when Google reports a new order (first purchase or renewal). */
    public static function fromPlay(int $userId, string $orderId, string $plan, string $phase, bool $test, ?int $at = null): ?int
    {
        if ($orderId === '' || $phase === 'trial') return null;
        $kind = self::planKind($plan);
        $base = self::playBase();
        $usd = (float) ($phase === 'intro' ? ($base[$kind . '_intro'] ?? $base[$kind]) : $base[$kind]);
        return self::record($userId, 'play', $orderId, $kind, $usd, 'USD', $usd, $test, $at);
    }

    /** Admin grant: Premium for N days, no money, no commission. */
    public static function grant(int $userId, int $days, string $by): int
    {
        $days = max(1, min(3660, $days));
        $id = Db::insert('INSERT INTO payments (user_id, source, ref, plan, amount, currency, amount_usd, status, test, created_at)
            VALUES (?, ?, ?, ?, 0, ?, 0, ?, 0, ?)', [$userId, 'manual', 'grant-' . bin2hex(random_bytes(6)) . '-' . substr($by, 0, 40), "days:$days", 'USD', 'paid', now()]);
        Billing::recompute($userId, time());
        return $id;
    }

    /**
     * Premium end date from website payments and admin grants. Each payment
     * adds its period, starting from the later of the payment date and the
     * current end date, plus one day of grace so a renewal is never late.
     */
    public static function webPaidUntil(int $userId): ?string
    {
        try {
            $rows = Db::all("SELECT plan, created_at FROM payments WHERE user_id = ? AND source IN ('paystack', 'manual') AND status = 'paid' ORDER BY created_at, id", [$userId]);
        } catch (Throwable $e) {
            return null; // table not created yet
        }
        $end = null;
        foreach ($rows as $r) {
            $start = new DateTimeImmutable($r['created_at'] . ' UTC');
            if ($end !== null && $end > $start) $start = $end;
            $end = $start->modify(self::period((string) $r['plan']));
        }
        return $end ? $end->modify('+1 day')->format('Y-m-d H:i:s') : null;
    }

    public static function period(string $plan): string
    {
        if (preg_match('/^days:(\d+)$/', $plan, $m)) return '+' . (int) $m[1] . ' days';
        return self::planKind($plan) === 'yearly' ? '+1 year' : '+1 month';
    }

    /** Totals for the admin overview, in USD. */
    public static function revenue(int $sinceTs): array
    {
        $row = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(amount_usd), 0) AS usd FROM payments WHERE status = 'paid' AND test = 0 AND source <> 'manual' AND created_at >= ?", [now($sinceTs)]);
        return ['count' => (int) $row['n'], 'usd' => round((float) $row['usd'], 2)];
    }
}
