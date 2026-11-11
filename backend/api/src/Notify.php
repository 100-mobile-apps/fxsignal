<?php
declare(strict_types=1);

/** Sends push (FCM topics) and queues emails for signal events and reports. */
final class Notify
{
    /** Verified users with an email preference on, filtered by followed pairs. */
    private static function recipients(string $column, ?string $pair): array
    {
        $allowed = ['email_signals', 'email_results', 'email_daily', 'email_weekly', 'email_monthly'];
        if (!in_array($column, $allowed, true)) throw new InvalidArgumentException($column);
        $rows = Db::all("SELECT id, name, email, notify_pairs, premium_until FROM users WHERE email_verified_at IS NOT NULL AND $column = 1");
        if ($pair === null) return $rows;
        return array_values(array_filter($rows, function ($u) use ($pair) {
            if (!$u['notify_pairs']) return true;
            $pairs = json_decode($u['notify_pairs'], true);
            return !is_array($pairs) || in_array($pair, $pairs, true);
        }));
    }

    public static function unsubscribeUrl(int $userId, string $column): string
    {
        $base = rtrim((string) config('base_url', ''), '/');
        $sig = substr(hash_hmac('sha256', "$userId:$column", (string) config('jwt_secret')), 0, 24);
        return "$base/email/unsubscribe?u=$userId&k=$column&s=$sig";
    }

    public static function checkUnsubscribe(int $userId, string $column, string $sig): bool
    {
        $expected = substr(hash_hmac('sha256', "$userId:$column", (string) config('jwt_secret')), 0, 24);
        return hash_equals($expected, $sig);
    }

    public static function newSignal(array $s): int
    {
        $pair = $s['pair'];
        $f = fn($v) => number_format((float) $v, price_decimals($pair), '.', '');
        $emoji = $s['direction'] === 'BUY' ? '🟢' : '🔴';
        $data = ['signal_id' => $s['id'], 'type' => 'signal'];

        $title = "$emoji {$s['direction']} $pair @ " . $f($s['entry']);
        $body = 'SL ' . $f($s['stop_loss']) . ' · TP1 ' . $f($s['tp1']) . ' · TP2 ' . $f($s['tp2']) . " · {$s['confidence']}% strength";
        $premiumSignal = Billing::signalIsPremium($s);

        if (!Billing::enforced() || !$premiumSignal) {
            // Free signal (or before launch): everyone following the pair gets the full alert.
            Fcm::sendToTopic('signals_' . topic_key($pair), $title, $body, $data);
        } else {
            // Premium signal: topic (free users + old app versions) gets a teaser only...
            Fcm::sendToTopic('signals_' . topic_key($pair),
                "🔒 New {$s['direction']} signal on $pair",
                "{$s['confidence']}% strength. Subscribe to see the entry, stop loss and take profit.",
                $data + ['locked' => 1]);
        }
        // ...and verified Premium devices get the full levels directly (they skip the topic).
        foreach (Billing::premiumDeviceTokens($pair) as $token) {
            if (Fcm::sendToToken($token, $title, $body, $data) === Fcm::UNREGISTERED) Billing::dropDevice($token);
        }

        $n = 0;
        foreach (self::recipients('email_signals', $pair) as $u) {
            $unsub = self::unsubscribeUrl((int) $u['id'], 'email_signals');
            [$subj, $html, $text] = !Billing::lockedFor($u, $s + ['status' => 'running'])
                ? EmailTemplates::newSignal($s, $u['name'], $unsub)
                : EmailTemplates::lockedSignal($s, $u['name'], $unsub);
            Mailer::queue((int) $u['id'], $u['email'], $subj, $html, $text, $unsub);
            $n++;
        }
        return $n;
    }

    /** $kind: tp1 | tp2 | sl | tp1_close | expired */
    public static function result(array $s, string $kind): int
    {
        $pair = $s['pair'];
        $pips = (float) ($kind === 'tp1' ? $s['live_pips'] : $s['pips']);
        $pipsTxt = EmailTemplates::pips($pips) . ' pips';
        [$title, $body] = match ($kind) {
            'tp1' => ["🎯 $pair TP1 hit $pipsTxt", 'Move your stop loss to entry to lock in a risk-free trade.'],
            'tp2' => ["✅ $pair hit TP2 $pipsTxt", "Full target reached on the {$s['direction']} signal."],
            'sl' => ["❌ $pair stopped out $pipsTxt", 'Stop loss was hit. Stick to your risk plan.'],
            'tp1_close' => ["✅ $pair closed in profit $pipsTxt", 'TP1 was reached; the rest closed at break-even.'],
            default => ["⏱ $pair signal expired $pipsTxt", 'Closed at market price after the time limit.'],
        };
        Fcm::sendToTopic('results_' . topic_key($pair), $title, $body, ['signal_id' => $s['id'], 'type' => $kind]);
        // TP1 is push-only to keep email volume inside hosting limits; the final result is emailed.
        if ($kind === 'tp1') return 0;
        $n = 0;
        foreach (self::recipients('email_results', $pair) as $u) {
            $unsub = self::unsubscribeUrl((int) $u['id'], 'email_results');
            [$subj, $html, $text] = EmailTemplates::result($s, $kind, $u['name'], $unsub);
            Mailer::queue((int) $u['id'], $u['email'], $subj, $html, $text, $unsub);
            $n++;
        }
        return $n;
    }

    public static function report(string $period, array $row, array $signals, array $summary): int
    {
        if ($period === 'daily') {
            Fcm::sendToTopic('reports',
                "📊 Today: {$row['wins']} wins, {$row['losses']} losses · " . EmailTemplates::pips((float) $row['pips']) . ' pips',
                $row['closed'] ? 'Tap to see the full daily report.' : 'No signals closed today.',
                ['type' => 'report', 'period' => $period]);
        } elseif ($period === 'weekly') {
            Fcm::sendToTopic('reports',
                "📊 This week: {$row['wins']}W / {$row['losses']}L · " . EmailTemplates::pips((float) $row['pips']) . ' pips',
                'Your weekly performance report is ready.', ['type' => 'report', 'period' => $period]);
        }
        $column = "email_$period";
        $n = 0;
        foreach (self::recipients($column, null) as $u) {
            $unsub = self::unsubscribeUrl((int) $u['id'], $column);
            [$subj, $html, $text] = EmailTemplates::report($period, $row, $signals, $summary, $u['name'], $unsub);
            Mailer::queue((int) $u['id'], $u['email'], $subj, $html, $text, $unsub);
            $n++;
        }
        return $n;
    }

    /** GET /email/unsubscribe — one-click from the email footer. */
    public static function unsubscribe(): void
    {
        $uid = (int) ($_GET['u'] ?? 0);
        $col = (string) ($_GET['k'] ?? '');
        $sig = (string) ($_GET['s'] ?? '');
        $labels = ['email_signals' => 'new signal emails', 'email_results' => 'trade result emails',
            'email_daily' => 'daily report emails', 'email_weekly' => 'weekly report emails', 'email_monthly' => 'monthly report emails'];
        $ok = isset($labels[$col]) && self::checkUnsubscribe($uid, $col, $sig);
        if ($ok) Db::exec("UPDATE users SET $col = 0 WHERE id = ?", [$uid]);
        http_response_code($ok ? 200 : 400);
        header('Content-Type: text/html; charset=utf-8');
        $app = htmlspecialchars((string) config('app_name', 'FX Signals'));
        $msg = $ok ? "You've been unsubscribed from {$labels[$col]}. You can turn them back on in the app under Profile → Notifications."
            : 'This unsubscribe link is invalid or has expired. You can change email settings in the app under Profile → Notifications.';
        echo "<!doctype html><meta name=viewport content='width=device-width,initial-scale=1'><title>$app</title>"
            . "<body style='margin:0;background:#0B1019;color:#E9EDF5;font-family:Segoe UI,Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px'>"
            . "<div style='max-width:420px;background:#121a29;border:1px solid #1F2A3F;border-radius:18px;padding:28px'><h2 style='margin:0 0 10px'>$app</h2><p style='line-height:1.6;color:#AEB6C8'>$msg</p></div></body>";
    }
}
