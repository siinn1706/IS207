<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Port of email_service.py. Returns false (never throws) when SMTP is missing or fails. */
class OtpMailer
{
    private static function configured(): bool
    {
        $c = config('khohang.smtp');

        return $c['user'] !== '' && $c['pass'] !== '';
    }

    private static function send(string $to, string $subject, string $html): bool
    {
        try {
            $c = config('khohang.smtp');
            config(['mail.mailers.khohang_smtp' => [
                'transport' => 'smtp', 'host' => $c['host'], 'port' => $c['port'],
                'username' => $c['user'], 'password' => $c['pass'], 'timeout' => 20,
                'scheme' => $c['port'] === 465 ? 'smtps' : 'smtp',
            ]]);
            Mail::mailer('khohang_smtp')->html($html, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject)->from(config('khohang.smtp.from'));
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('[ERROR] Failed to send email: '.$e->getMessage());

            return false;
        }
    }

    public static function sendOtp(string $to, string $code, string $purpose): bool
    {
        if (! self::configured()) {
            Log::error('[ERROR] SMTP credentials not configured');

            return false;
        }
        $subject = [
            'register' => 'Mã xác minh đăng ký tài khoản',
            'reset_password' => 'Mã xác minh đặt lại mật khẩu',
            'change_passkey' => 'Mã xác minh thay đổi passkey',
        ][$purpose] ?? 'Mã xác minh';
        $code = htmlspecialchars($code);
        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
  <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #2563eb;">{$subject}</h2>
    <p>Xin chào,</p>
    <p>Mã xác minh của bạn là:</p>
    <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; text-align: center; margin: 20px 0;">
      <span style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1e40af;">{$code}</span>
    </div>
    <p>Mã này có hiệu lực trong <strong>10 phút</strong>.</p>
    <p>Nếu bạn không yêu cầu mã này, vui lòng bỏ qua email này.</p>
    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
    <p style="font-size: 12px; color: #6b7280;">Email này được gửi tự động, vui lòng không trả lời.<br>© 2025 NT106 Quản Lý Kho</p>
  </div>
</body></html>
HTML;

        return self::send($to, $subject, $html);
    }

    public static function sendWelcome(string $to, string $username): bool
    {
        if (! self::configured()) {
            return false;
        }
        $u = htmlspecialchars($username);
        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
  <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #2563eb;">Chào mừng {$u}!</h2>
    <p>Tài khoản của bạn đã được kích hoạt thành công.</p>
    <p>Bạn có thể đăng nhập ngay bây giờ để sử dụng hệ thống quản lý kho.</p>
    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
    <p style="font-size: 12px; color: #6b7280;">© 2025 NT106 Quản Lý Kho</p>
  </div>
</body></html>
HTML;

        return self::send($to, 'Chào mừng đến với Hệ thống Quản Lý Kho', $html);
    }
}
