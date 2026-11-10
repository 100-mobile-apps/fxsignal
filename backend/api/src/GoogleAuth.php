<?php
declare(strict_types=1);

/**
 * OAuth2 access tokens for Google APIs from a service-account JSON key
 * (used for Firebase Cloud Messaging and the Google Play Developer API).
 * Tokens are cached in the system temp folder until shortly before expiry.
 */
final class GoogleAuth
{
    public static function accessToken(string $saFile, string $scope): string
    {
        $cache = sys_get_temp_dir() . '/fx_gtoken_' . md5($saFile . '|' . $scope) . '.json';
        if (is_file($cache)) {
            $t = json_decode((string) file_get_contents($cache), true);
            if (($t['exp'] ?? 0) > time() + 60) return (string) $t['token'];
        }
        $sa = json_decode((string) @file_get_contents($saFile), true);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('Service account file is missing or invalid: ' . basename($saFile));
        }
        $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        if (!openssl_sign("$head.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google token request');
        }
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
        if (empty($res['access_token'])) throw new RuntimeException('Could not get a Google access token');
        @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + (int) ($res['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($cache, 0600);
        return (string) $res['access_token'];
    }
}
