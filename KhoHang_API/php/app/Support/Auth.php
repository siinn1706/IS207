<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Port of auth_middleware.get_current_user and main.require_passkey. */
class Auth
{
    /** @return array{id:string,username:string,email:string,role:?string} */
    public static function user(Request $request): array
    {
        $cached = $request->attributes->get('khohang.user');
        if ($cached) {
            return $cached;
        }
        $token = self::bearer($request);
        if ($token === null) {
            Http::fail(401, 'Authentication required', ['WWW-Authenticate' => 'Bearer']);
        }
        $payload = Jwt::decode($token);
        if (! $payload) {
            Http::fail(401, 'Invalid or expired token', ['WWW-Authenticate' => 'Bearer']);
        }
        $userId = $payload['sub'] ?? null;
        if (! $userId) {
            Http::fail(401, 'Invalid token payload');
        }
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) {
            Log::info('[Auth] User not found for token subject');
            Http::fail(401, 'User not found');
        }
        if (! $user->is_verified) {
            Http::fail(403, 'Account not verified');
        }
        $u = ['id' => $user->id, 'username' => $user->username, 'email' => $user->email, 'role' => $user->role];
        $request->attributes->set('khohang.user', $u);

        return $u;
    }

    /** HTTPBearer(auto_error=False): only a well-formed "Bearer <token>" header counts. */
    public static function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if ($header === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($header), 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer' || $parts[1] === '') {
            return null;
        }

        return $parts[1];
    }

    /**
     * Destructive operations: header X-Passkey (or legacy ?passkey=) must match
     * the authenticated user's bcrypt passkey hash. The value is never logged.
     */
    public static function passkey(Request $request, bool $allowQuery = true): array
    {
        $user = self::user($request);
        $provided = $request->headers->get('X-Passkey');
        if (($provided === null || $provided === '') && $allowQuery) {
            $provided = $request->query('passkey');
        }
        if ($provided === null || $provided === '') {
            Http::fail(400, $allowQuery ? 'Thiếu passkey (X-Passkey hoặc ?passkey=)' : 'Thiếu passkey (X-Passkey)');
        }
        $hash = DB::table('users')->where('id', $user['id'])->value('passkey_hash');
        if (! $hash) {
            Http::fail(403, 'User chưa thiết lập passkey');
        }
        if (! Passwords::verify((string) $provided, $hash)) {
            Http::fail(403, 'Passkey không hợp lệ');
        }

        return $user;
    }
}
