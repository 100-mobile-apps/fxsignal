<?php
declare(strict_types=1);

/**
 * Firebase Cloud Messaging (HTTP v1).
 *
 * - Topics ("signals_EURUSD", "results_EURUSD") reach every install that
 *   subscribed, including old app versions. Anything sent to a topic must be
 *   safe for free users: new-signal topic messages are teasers without levels.
 * - Full signal alerts (entry, stop loss, take profit) go only to the device
 *   tokens of users whose subscription the server has verified.
 */
final class Fcm
{
    public const UNREGISTERED = 404;

    public static function enabled(): bool
    {
        $c = config('fcm', []);
        return !empty($c['project_id']) && !empty($c['service_account_file']) && is_file($c['service_account_file']);
    }

    public static function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        return self::send(['topic' => $topic], $title, $body, $data) === 200;
    }

    /** Returns the HTTP status; Fcm::UNREGISTERED means the token is dead and should be deleted. */
    public static function sendToToken(string $token, string $title, string $body, array $data = []): int
    {
        return self::send(['token' => $token], $title, $body, $data);
    }

    private static function send(array $target, string $title, string $body, array $data): int
    {
        if (!self::enabled()) return 0;
        $c = config('fcm');
        try {
            $token = GoogleAuth::accessToken($c['service_account_file'], 'https://www.googleapis.com/auth/firebase.messaging');
        } catch (Throwable $e) {
            error_log('FCM auth failed: ' . $e->getMessage());
            return 0;
        }
        $payload = [
            'message' => $target + [
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => ['channel_id' => 'signals', 'sound' => 'default'],
                ],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ];
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$c['project_id']}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return 200;
        if ($code === 404 || ($code === 400 && str_contains($res, 'registration token is not a valid'))) {
            return self::UNREGISTERED;
        }
        $where = isset($target['topic']) ? 'topic ' . $target['topic'] : 'a device';
        error_log("FCM send to $where failed ($code): $res");
        return $code;
    }
}
