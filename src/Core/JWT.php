<?php

namespace App\Core;

class JWT
{
    public static function encode(array $payload): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'default-secret';
        $expiry = (int)($_ENV['JWT_EXPIRY'] ?? 86400);

        $header = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;
        $body = self::base64url(json_encode($payload));

        $signature = self::base64url(
            hash_hmac('sha256', "$header.$body", $secret, true)
        );

        return "$header.$body.$signature";
    }

    public static function decode(string $token): ?array
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'default-secret';
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;

        $expectedSig = self::base64url(
            hash_hmac('sha256', "$header.$body", $secret, true)
        );

        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);

        if (!$payload || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
