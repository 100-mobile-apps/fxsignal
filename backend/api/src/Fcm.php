<?php
declare(strict_types=1);

/**
 * Firebase Cloud Messaging (HTTP v1) — sends to topics, so the server never
 * stores device tokens. The app subscribes to topics such as
 * "signals_EURUSD" and "results_EURUSD" based on the user's settings.
 */
final class Fcm
{
    public static function enabled(): bool
    {
        $c = config('fcm', []);
        return !empty($c['project_id']) && !empty($c['service_account_file']) && is_file($c['service_account_file']);
    }

    public static function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        if (!self::enabled()) return false;
        $c = config('fcm');
        $token = self::accessToken($c['service_account_file']);
        $payload = [
            'message' => [
                'topic' => $topic,
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
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log("FCM send to $topic failed ($code): $res");
            return false;
        }
        return true;
    }

    private static function accessToken(string $saFile): string
    {
        $cache = sys_get_temp_dir() . '/fx_fcm_token_' . md5($saFile) . '.json';
        if (is_file($cache)) {
            $t = json_decode((string) file_get_contents($cache), true);
            if (($t['exp'] ?? 0) > time() + 60) return $t['token'];
        }
        $sa = json_decode((string) file_get_contents($saFile), true);
        $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        openssl_sign("$head.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "$head.$claims." . $b64($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (empty($res['access_token'])) throw new RuntimeException('Could not get FCM access token');
        file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + (int) ($res['expires_in'] ?? 3600)]));
        return $res['access_token'];
    }
}
