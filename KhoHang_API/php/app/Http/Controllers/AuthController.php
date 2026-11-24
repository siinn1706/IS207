<?php

namespace App\Http\Controllers;

use App\Services\OtpMailer;
use App\Support\Auth;
use App\Support\Http;
use App\Support\Jwt;
use App\Support\Legacy;
use App\Support\Passwords;
use App\Support\Validate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Port of auth_routes.py (/auth/*). */
class AuthController
{
    private const OTP_TTL_MINUTES = 10;

    private const RESEND_SECONDS = 60;

    // ---------- helpers ----------

    private static function normalizeUsername(string $u): string
    {
        return strtolower(trim($u));
    }

    private static function hashOtp(string $code): string
    {
        return hash('sha256', $code);
    }

    private static function generateOtp(): string
    {
        $s = '';
        for ($i = 0; $i < 6; $i++) {
            $s .= (string) random_int(0, 9);
        }

        return $s;
    }

    private static function validOtp(string $email, string $purpose): ?object
    {
        $now = Legacy::now();
        DB::table('email_otps')->where('email', $email)->where('purpose', $purpose)->where('expires_at', '<', $now)->delete();

        return DB::table('email_otps')->where('email', $email)->where('purpose', $purpose)
            ->where('expires_at', '>', $now)->whereNull('consumed_at')
            ->orderByDesc('created_at')->first();
    }

    /**
     * 60 s resend window. (The FastAPI code subtracted a naive DB datetime from an
     * aware one and answered 500 here; the PHP port returns the intended 429.)
     */
    private static function resendWait(?object $otp): ?int
    {
        if (! $otp) {
            return null;
        }
        $created = Legacy::parse($otp->created_at);
        $elapsed = $created ? (microtime(true) - (float) $created->format('U.u')) : self::RESEND_SECONDS;

        return $elapsed < self::RESEND_SECONDS ? (int) (self::RESEND_SECONDS - $elapsed) : null;
    }

    private static function storeOtp(string $email, string $purpose): string
    {
        $code = self::generateOtp();
        $now = Legacy::nowDt();
        DB::table('email_otps')->insert([
            'email' => $email, 'purpose' => $purpose, 'code_hash' => self::hashOtp($code),
            'expires_at' => Legacy::toDb($now->modify('+'.self::OTP_TTL_MINUTES.' minutes')),
            'attempts_left' => 5, 'created_at' => Legacy::toDb($now),
        ]);

        return $code;
    }

    /** Shared confirm flow: returns the consumed OTP row or throws the legacy 400s. */
    private static function consumeOtp(string $email, string $purpose, string $code): void
    {
        $otp = self::validOtp($email, $purpose);
        if (! $otp) {
            Http::fail(400, 'OTP expired or not found');
        }
        if ((int) $otp->attempts_left <= 0) {
            Http::fail(400, 'Too many failed attempts');
        }
        if (! hash_equals($otp->code_hash, self::hashOtp($code))) {
            $left = (int) $otp->attempts_left - 1;
            DB::table('email_otps')->where('id', $otp->id)->update(['attempts_left' => $left]);
            Http::fail(400, "Invalid OTP. $left attempts remaining");
        }
        DB::table('email_otps')->where('id', $otp->id)->update(['consumed_at' => Legacy::now()]);
    }

    private static function findByLogin(string $usernameOrEmail): ?object
    {
        return DB::table('users')
            ->where('username', self::normalizeUsername($usernameOrEmail))
            ->orWhere('email', $usernameOrEmail)
            ->first();
    }

    private static function token(object $user): string
    {
        return Jwt::encode(['sub' => $user->id, 'username' => $user->username]);
    }

    private static function throttle(string $key, int $max, int $decay): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = RateLimiter::availableIn($key);
            Http::fail(429, "Too many attempts. Please wait $wait seconds", ['Retry-After' => (string) $wait]);
        }
        RateLimiter::hit($key, $decay);
    }

    // ---------- register ----------

    public function registerRequestOtp(Request $r)
    {
        [$d] = Validate::body($r, [
            'username' => ['str', 'min' => 3, 'max' => 24],
            'email' => 'email',
            'display_name' => ['str', 'min' => 1, 'max' => 100],
            'password' => ['str', 'min' => 6, 'max' => 100],
            'avatar_url' => ['?str', 'default' => null],
        ]);
        self::throttle('otp:'.$r->ip(), 10, 600);
        $username = self::normalizeUsername($d['username']);
        if (! preg_match('/^[a-z0-9._-]{3,24}$/', $username)) {
            Http::fail(400, 'Username must be 3-24 characters, lowercase letters, numbers, dots, underscores, hyphens only');
        }
        // An unverified account with the same email is a previous, unfinished attempt:
        // let the person retry instead of locking the email forever.
        $pending = DB::table('users')->where('email', $d['email'])->where('is_verified', false)->first();
        $byName = DB::table('users')->where('username', $username)->first();
        if ($byName && (! $pending || $byName->id !== $pending->id)) {
            Http::fail(400, 'Username already exists');
        }
        if (! $pending && DB::table('users')->where('email', $d['email'])->exists()) {
            Http::fail(400, 'Email already exists');
        }
        if (($wait = self::resendWait(self::validOtp($d['email'], 'register'))) !== null) {
            Http::fail(429, "Please wait $wait seconds before requesting a new OTP");
        }
        [$code, $userId, $otpId] = DB::transaction(function () use ($d, $username, $pending) {
            $now = Legacy::now();
            $fields = [
                'username' => $username, 'display_name' => $d['display_name'], 'avatar_url' => $d['avatar_url'],
                'password_hash' => Passwords::hash($d['password']), 'updated_at' => $now,
            ];
            if ($pending) {
                DB::table('users')->where('id', $pending->id)->update($fields);
                $id = $pending->id;
            } else {
                $id = Legacy::uuid4();
                DB::table('users')->insert($fields + [
                    'id' => $id, 'email' => $d['email'], 'role' => 'staff', 'is_verified' => false, 'created_at' => $now,
                ]);
            }
            $code = self::storeOtp($d['email'], 'register');

            return [$code, $id, DB::table('email_otps')->max('id')];
        });
        if (! OtpMailer::sendOtp($d['email'], $code, 'register')) {
            // Undo this attempt so the same email/username can register again.
            DB::table('email_otps')->where('id', $otpId)->delete();
            if (! $pending) {
                DB::table('users')->where('id', $userId)->where('is_verified', false)->delete();
            }
            Http::fail(500, 'Failed to send OTP email');
        }

        return Http::json(['message' => 'OTP sent to email', 'email' => $d['email']]);
    }

    public function registerConfirm(Request $r)
    {
        [$d] = Validate::body($r, ['email' => 'email', 'otp' => ['str', 'min' => 6, 'max' => 6]]);
        self::consumeOtp($d['email'], 'register', $d['otp']);
        $user = DB::table('users')->where('email', $d['email'])->first();
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        DB::table('users')->where('id', $user->id)->update(['is_verified' => true, 'updated_at' => Legacy::now()]);
        OtpMailer::sendWelcome($d['email'], $user->username);

        return Http::json([
            'message' => 'Registration successful',
            'access_token' => self::token($user),
            'token_type' => 'bearer',
            'user' => [
                'id' => $user->id, 'username' => $user->username, 'email' => $user->email,
                'display_name' => $user->display_name, 'avatar_url' => $user->avatar_url, 'role' => $user->role,
            ],
        ]);
    }

    // ---------- login / me ----------

    public function login(Request $r)
    {
        [$d] = Validate::body($r, ['username_or_email' => 'str', 'password' => 'str']);
        self::throttle('login:'.$r->ip().'|'.strtolower($d['username_or_email']), 10, 60);
        $user = self::findByLogin($d['username_or_email']);
        if (! $user) {
            Http::fail(401, 'Invalid credentials');
        }
        if (! $user->is_verified) {
            Http::fail(403, 'Account not verified. Please check your email for OTP.');
        }
        if (! Passwords::verify($d['password'], $user->password_hash)) {
            Http::fail(401, 'Invalid credentials');
        }
        RateLimiter::clear('login:'.$r->ip().'|'.strtolower($d['username_or_email']));

        return Http::json([
            'access_token' => self::token($user),
            'token_type' => 'bearer',
            'user' => self::profile($user),
        ]);
    }

    public static function profile(object $u): array
    {
        return [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email,
            'display_name' => $u->display_name, 'avatar_url' => $u->avatar_url, 'role' => $u->role,
            'is_verified' => (bool) $u->is_verified, 'has_passkey' => (bool) $u->passkey_hash,
        ];
    }

    public function me(Request $r)
    {
        $cu = Auth::user($r);
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }

        return Http::json(self::profile($user));
    }

    // ---------- password reset ----------

    public function passwordRequestOtp(Request $r)
    {
        [$d] = Validate::body($r, ['username_or_email' => 'str']);
        self::throttle('otp:'.$r->ip(), 10, 600);
        $user = self::findByLogin($d['username_or_email']);
        if (! $user) {
            return Http::json(['message' => 'If the account exists, an OTP has been sent to the registered email']);
        }
        if (self::resendWait(self::validOtp($user->email, 'reset_password')) !== null) {
            Http::fail(429, 'Please wait before requesting a new OTP');
        }
        $code = self::storeOtp($user->email, 'reset_password');
        OtpMailer::sendOtp($user->email, $code, 'reset_password');

        return Http::json(['message' => 'If the account exists, an OTP has been sent to the registered email', 'email' => $user->email]);
    }

    public function passwordConfirm(Request $r)
    {
        [$d] = Validate::body($r, [
            'email' => 'email', 'otp' => ['str', 'min' => 6, 'max' => 6], 'new_password' => ['str', 'min' => 6, 'max' => 100],
        ]);
        self::consumeOtp($d['email'], 'reset_password', $d['otp']);
        $user = DB::table('users')->where('email', $d['email'])->first();
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        DB::table('users')->where('id', $user->id)->update(['password_hash' => Passwords::hash($d['new_password']), 'updated_at' => Legacy::now()]);

        return Http::json(['message' => 'Password reset successfully']);
    }

    /**
     * POST /auth/change-password {email, old_password, new_password}. New route for the
     * existing client page. Requires the bearer token; email must be the caller's own.
     */
    public function changePassword(Request $r)
    {
        $cu = Auth::user($r);
        [$d] = Validate::body($r, [
            'email' => 'email', 'old_password' => 'str', 'new_password' => ['str', 'min' => 6, 'max' => 100],
        ]);
        self::throttle('change-password:'.$cu['id'], 5, 300);
        $user = DB::table('users')->find($cu['id']);
        if (! $user || strcasecmp($user->email, $d['email']) !== 0) {
            Http::fail(400, 'Email không khớp với tài khoản đang đăng nhập');
        }
        if (! Passwords::verify($d['old_password'], $user->password_hash)) {
            Http::fail(401, 'Mật khẩu cũ không đúng');
        }
        if ($d['old_password'] === $d['new_password']) {
            Http::fail(400, 'Mật khẩu mới phải khác mật khẩu cũ');
        }
        DB::table('users')->where('id', $user->id)->update(['password_hash' => Passwords::hash($d['new_password']), 'updated_at' => Legacy::now()]);
        RateLimiter::clear('change-password:'.$cu['id']);

        return Http::json(['message' => 'Password changed successfully']);
    }

    /** Client calls this on sign-out. Tokens are stateless JWTs, so there is nothing to revoke. */
    public function logout()
    {
        return Http::json(['message' => 'Logged out']);
    }

    // ---------- passkey ----------

    public function passkeyRequestOtp(Request $r)
    {
        $cu = Auth::user($r);
        [$d] = Validate::body($r, ['password' => ['str', 'min' => 1]]);
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        if (! Passwords::verify($d['password'], $user->password_hash)) {
            Http::fail(401, 'Mật khẩu không đúng');
        }
        if (self::resendWait(self::validOtp($user->email, 'change_passkey')) !== null) {
            Http::fail(429, 'Please wait before requesting a new OTP');
        }
        $code = self::storeOtp($user->email, 'change_passkey');
        OtpMailer::sendOtp($user->email, $code, 'change_passkey');

        return Http::json(['message' => 'OTP sent to your email']);
    }

    public function passkeyConfirm(Request $r)
    {
        $cu = Auth::user($r);
        [$d] = Validate::body($r, ['otp' => ['str', 'min' => 6, 'max' => 6], 'new_passkey' => ['str', 'min' => 4, 'max' => 50]]);
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        self::consumeOtp($user->email, 'change_passkey', $d['otp']);
        DB::table('users')->where('id', $user->id)->update(['passkey_hash' => Passwords::hash($d['new_passkey']), 'updated_at' => Legacy::now()]);

        return Http::json(['message' => 'Passkey updated successfully']);
    }
}
