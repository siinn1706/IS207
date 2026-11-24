<?php

namespace App\Support;

/** bcrypt compatible with passlib hashes ($2b$) produced by the old backend. */
class Passwords
{
    public static function hash(string $plain): string
    {
        // passlib emits $2b$; keep the same ident so FastAPI rollback can verify it.
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

        return preg_replace('/^\$2y\$/', '\$2b\$', $hash);
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        if (! $hash) {
            return false;
        }

        return password_verify($plain, $hash);
    }
}
