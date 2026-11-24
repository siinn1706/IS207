<?php

namespace App\Support;

/**
 * Minimal HS256 JWT compatible with PyJWT tokens issued by the old backend
 * (claims: sub, username, exp as integer seconds).
 */
class Jwt
{
    public static function secret(): string
    {
        $secret = (string) config('khohang.jwt_secret');
        if ($secret === '') {
            // Fail closed: never sign or accept tokens with an implicit secret.
            throw new \RuntimeException('JWT_SECRET is not configured');
        }

        return $secret;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string|false
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    public static function encode(array $claims, ?int $ttlSeconds = null): string
    {
        $ttlSeconds ??= ((int) config('khohang.jwt_exp_days', 7)) * 86400;
        $claims['exp'] = time() + $ttlSeconds;
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig = self::b64(hash_hmac('sha256', "$header.$payload", self::secret(), true));

        return "$header.$payload.$sig";
    }

    /** Returns claims, or null when the token is malformed, forged or expired. */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (! hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode((string) self::unb64($p), true);
        if (! is_array($claims)) {
            return null;
        }
        if (isset($claims['exp']) && (! is_numeric($claims['exp']) || (int) $claims['exp'] <= time())) {
            return null;
        }
        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int) $claims['nbf'] > time()) {
            return null;
        }

        return $claims;
    }
}
