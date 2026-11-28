<?php
declare(strict_types=1);

/**
 * Automated emails: welcome series, activity-based upgrade offers, win-back, and
 * subscription notices (trial ending, renewal, payment problem, expired).
 *
 * Marketing emails (welcome/upgrade/winback) need users.email_marketing = 1, carry an
 * unsubscribe link, are capped per user per week (admin: email_campaigns.max_per_week)
 * and at most one a day. Subscription notices are service messages: not capped.
 * Every email is sent once per (user, kind, ref), recorded in email_log.
 */
final class Campaigns
{
    public const MARKETING = ['welcome1', 'welcome2', 'welcome3', 'upgrade', 'winback7', 'winback14', 'winback30'];
    private const PER_RUN = 150;

    public static function settings(): array
    {
        return (array) config('email_campaigns', []) + ['max_per_week' => 2, 'welcome' => true, 'upgrade' => true, 'winback' => true, 'lifecycle' => true];
    }

    /** Remember a user action used for targeting (e.g. tapping a locked signal). */
    public static function track(int $userId, string $kind): void
    {
        try {
            Db::exec('INSERT INTO user_events (user_id, kind, created_at) VALUES (?, ?, ?)', [$userId, $kind, now()]);
        } catch (Throwable $e) {
            // tracking must never break the request
        }
    }

    /** Called by the cron once an hour. Returns log lines. */
    public static function run(?int $now = null): array
    {
        $now = $now ?? time();
        $cfg = self::settings();
        // Campaigns only target accounts created after they were first switched on (no blast to the existing base).
        $start = Db::one("SELECT v FROM meta WHERE k = 'campaigns_start'");
        if (!$start) {
            Db::exec('INSERT INTO meta (k, v) VALUES (?, ?)', ['campaigns_start', json_encode(now($now))]);
            $since = now($now);
        } else {
            $since = (string) json_decode($start['v'], true);
        }
        $queued = 0;
        $log = [];
        if (!empty($cfg['lifecycle'])) $queued += self::lifecycle($now);
        if (!empty($cfg['welcome'])) $queued += self::welcome($now, $since, $cfg);
        if (!empty($cfg['upgrade'])) $queued += self::upgrade($now, $cfg);
        if (!empty($cfg['winback'])) $queued += self::winback($now, $cfg);
        if ($queued) $log[] = "campaigns: $queued emails queued";
        return $log;
    }

    // ---------- marketing ----------

    private static function welcome(int $now, string $since, array $cfg): int
    {
        $steps = [['welcome1', 1], ['welcome2', 3], ['welcome3', 6]];
        $n = 0;
        foreach (Db::all('SELECT * FROM users WHERE created_at >= ? AND created_at <= ? AND email_verified_at IS NOT NULL AND email_marketing = 1',
            [$since, now($now - 86400)]) as $u) {
            $age = $now - strtotime($u['created_at'] . ' UTC');
            foreach ($steps as [$kind, $days]) {
                if ($age < $days * 86400 || self::sent((int) $u['id'], $kind)) continue;
                if ($kind === 'welcome3' && Billing::enforcedFor($u) && Billing::isPremium($u, $now)) break; // already subscribed
                if (self::send($u, $kind, '', $now, $cfg)) $n++;
                break; // one step per run; the next comes on a later day
            }
            if ($n >= self::PER_RUN) break;
        }
        return $n;
    }

    private static function upgrade(int $now, array $cfg): int
    {
        $n = 0;
        $rows = Db::all("SELECT u.*, COUNT(e.id) AS taps FROM users u JOIN user_events e ON e.user_id = u.id
            WHERE e.kind = 'locked_view' AND e.created_at >= ? AND u.email_verified_at IS NOT NULL AND u.email_marketing = 1
            GROUP BY u.id HAVING COUNT(e.id) >= 2", [now($now - 7 * 86400)]);
        foreach ($rows as $u) {
            if (Billing::isPremium($u, $now) || self::sentSince((int) $u['id'], 'upgrade', $now - 14 * 86400)) continue;
            if (self::send($u, 'upgrade', gmdate('Y-m-d', $now), $now, $cfg)) $n++;
            if ($n >= self::PER_RUN) break;
        }
        return $n;
    }

    private static function winback(int $now, array $cfg): int
    {
        $n = 0;
        foreach ([30 => 'winback30', 14 => 'winback14', 7 => 'winback7'] as $days => $kind) {
            $rows = Db::all("SELECT * FROM users WHERE email_verified_at IS NOT NULL AND email_marketing = 1
                AND COALESCE(last_seen_at, last_login_at, created_at) <= ? AND COALESCE(last_seen_at, last_login_at, created_at) > ?",
                [now($now - $days * 86400), now($now - ($days + 7) * 86400)]);
            foreach ($rows as $u) {
                // ref = start of this inactive spell, so a user who comes back and leaves again can be won back again
                $ref = substr((string) ($u['last_seen_at'] ?? $u['last_login_at'] ?? $u['created_at']), 0, 10);
                if (self::sent((int) $u['id'], $kind, $ref)) continue;
                if (self::send($u, $kind, $ref, $now, $cfg)) $n++;
                if ($n >= self::PER_RUN) return $n;
            }
        }
        return $n;
    }

    // ---------- subscription notices ----------

    private static function lifecycle(int $now): int
    {
        $n = 0;
        $rows = Db::all("SELECT s.*, u.name, u.email, u.email_verified_at FROM subscriptions s JOIN users u ON u.id = s.user_id
            WHERE s.expires_at IS NOT NULL AND s.updated_at >= ?", [now($now - 45 * 86400)]);
        foreach ($rows as $s) {
            $exp = strtotime($s['expires_at'] . ' UTC');
            $ref = $s['id'] . ':' . substr($s['expires_at'], 0, 10);
            $kind = null;
            if ($s['state'] === 'SUBSCRIPTION_STATE_ACTIVE' && ($s['phase'] ?? '') === 'trial' && $exp - $now <= 2 * 86400 && $exp > $now) {
                $kind = 'trial_ending';
            } elseif ($s['state'] === 'SUBSCRIPTION_STATE_ACTIVE' && $s['plan'] === 'yearly' && (int) $s['auto_renewing'] === 1
                && ($s['phase'] ?? '') !== 'trial' && $exp - $now <= 7 * 86400 && $exp > $now) {
                $kind = 'renewal';
            } elseif (in_array($s['state'], ['SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'SUBSCRIPTION_STATE_ON_HOLD'], true)) {
                $kind = 'payment_failed';
            } elseif (in_array($s['state'], ['SUBSCRIPTION_STATE_EXPIRED', 'SUBSCRIPTION_STATE_CANCELED'], true) && $exp <= $now && $exp > $now - 3 * 86400) {
                $kind = 'expired';
            }
            if (!$kind || !$s['email_verified_at'] || self::sent((int) $s['user_id'], $kind, $ref)) continue;
            $u = ['id' => $s['user_id'], 'name' => $s['name'], 'email' => $s['email']];
            if (self::send($u, $kind, $ref, $now, [], $s)) $n++;
        }
        return $n;
    }

    // ---------- plumbing ----------

    private static function send(array $u, string $kind, string $ref, int $now, array $cfg, ?array $sub = null): bool
    {
        $uid = (int) $u['id'];
        $marketing = in_array($kind, self::MARKETING, true);
        if ($marketing) {
            $cap = (int) ($cfg['max_per_week'] ?? 2);
            $week = self::countSince($uid, $now - 7 * 86400);
            if ($cap <= 0 || $week >= $cap || self::countSince($uid, $now - 86400) > 0) return false;
        }
        $unsub = $marketing ? Notify::unsubscribeUrl($uid, 'email_marketing') : null;
        [$subject, $html, $text] = EmailTemplates::campaign($kind, (string) $u['name'], $unsub, $sub);
        Mailer::queue($uid, (string) $u['email'], $subject, $html, $text, $unsub);
        self::log($uid, $kind, $ref, $now);
        return true;
    }

    private static function log(int $uid, string $kind, string $ref, int $now): void
    {
        Db::exec('INSERT INTO email_log (user_id, kind, ref, created_at) VALUES (?, ?, ?, ?)', [$uid, $kind, $ref, now($now)]);
    }

    private static function sent(int $uid, string $kind, string $ref = ''): bool
    {
        return (bool) Db::one('SELECT id FROM email_log WHERE user_id = ? AND kind = ? AND ref = ?', [$uid, $kind, $ref]);
    }

    private static function sentSince(int $uid, string $kind, int $since): bool
    {
        return (bool) Db::one('SELECT id FROM email_log WHERE user_id = ? AND kind = ? AND created_at >= ?', [$uid, $kind, now($since)]);
    }

    /** Marketing emails sent to this user since a time. */
    private static function countSince(int $uid, int $since): int
    {
        $in = implode(',', array_fill(0, count(self::MARKETING), '?'));
        return (int) Db::one("SELECT COUNT(*) AS n FROM email_log WHERE user_id = ? AND created_at >= ? AND kind IN ($in)",
            array_merge([$uid, now($since)], self::MARKETING))['n'];
    }
}
