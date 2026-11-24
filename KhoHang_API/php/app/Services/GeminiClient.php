<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Port of gemini_client.py using the Gemini REST API instead of the google-genai SDK.
 * Same policy: primary model with retries, fallback model only after a quota error.
 */
class GeminiClient
{
    private const DEFAULT_INSTRUCTION = 'Always answer in Vietnamese using valid Markdown (headings, lists, tables, code blocks where helpful).';

    public static function configured(): bool
    {
        return config('khohang.gemini_api_key') !== '';
    }

    private static function generateWith(string $model, string $prompt, ?string $instruction): string
    {
        $res = Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => config('khohang.gemini_api_key')])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $instruction ?: self::DEFAULT_INSTRUCTION]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            ]);
        if (! $res->successful()) {
            throw new \RuntimeException($res->status().' '.substr($res->body(), 0, 1000));
        }
        $parts = $res->json('candidates.0.content.parts') ?? [];
        $text = trim(implode('', array_map(fn ($p) => $p['text'] ?? '', $parts)));

        return $text !== '' ? $text : '(Không có phản hồi từ mô hình)';
    }

    private static function retryDelay(string $msg): ?float
    {
        if (preg_match('/retry in ([\d.]+)s/i', $msg, $m) || preg_match('/"retryDelay":\s*"([\d.]+)s"/i', $msg, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /** @return array{0: bool, 1: ?float} */
    private static function isQuota(\Throwable $e): array
    {
        $s = $e->getMessage();
        foreach (['/quota.*exceeded/i', '/429/', '/rate.*limit/i', '/free.*tier/i', '/RESOURCE_EXHAUSTED/'] as $p) {
            if (preg_match($p, $s)) {
                return [true, self::retryDelay($s) ?? (str_contains($s, 'RESOURCE_EXHAUSTED') ? 60.0 : null)];
            }
        }

        return [false, null];
    }

    private static function isNotFound(\Throwable $e): bool
    {
        return (bool) preg_match('/404|not found|is not found|not supported/i', $e->getMessage());
    }

    public static function generateReply(string $prompt, ?string $instruction = null, int $maxRetries = 2): string
    {
        if (! self::configured()) {
            throw new \RuntimeException('Gemini client not configured. Set GEMINI_API_KEY.');
        }
        $model = config('khohang.gemini_model');
        $fallback = config('khohang.gemini_fallback_model');
        $last = null;
        $tryFallback = false;
        $delay = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return self::generateWith($model, $prompt, $instruction);
            } catch (\Throwable $e) {
                $last = $e;
                [$quota, $delay] = self::isQuota($e);
                if (self::isNotFound($e)) {
                    throw new \RuntimeException("Model '$model' không khả dụng trong API Gemini. Vui lòng kiểm tra lại cấu hình model. Chi tiết: ".substr($e->getMessage(), 0, 200));
                }
                if ($quota) {
                    $tryFallback = true;
                    sleep(1);
                    break;
                }
                if ($attempt < $maxRetries) {
                    sleep(($attempt + 1) * 2);
                }
            }
        }
        if ($tryFallback) {
            try {
                return self::generateWith($fallback, $prompt, $instruction);
            } catch (\Throwable $e) {
                [$quota2, $delay2] = self::isQuota($e);
                if (self::isNotFound($e)) {
                    throw new QuotaExceeded("Đã vượt quá quota API Gemini cho model '$model'. Fallback model không khả dụng. Vui lòng thử lại sau. Chi tiết: ".substr($last?->getMessage() ?? $e->getMessage(), 0, 200), $delay);
                }
                if ($quota2) {
                    throw new QuotaExceeded('Đã vượt quá quota API Gemini cho tất cả các model. Vui lòng thử lại sau. Chi tiết: '.substr($e->getMessage(), 0, 200), $delay2 ?? $delay);
                }
                throw new \RuntimeException('Lỗi khi gọi Gemini API với fallback model: '.$e->getMessage().'. Lỗi ban đầu: '.$last?->getMessage());
            }
        }
        if ($last) {
            [$quota, $delay] = self::isQuota($last);
            if ($quota) {
                throw new QuotaExceeded('Đã vượt quá quota API Gemini. Vui lòng thử lại sau. Chi tiết: '.substr($last->getMessage(), 0, 200), $delay);
            }
            throw new \RuntimeException('Lỗi khi gọi Gemini API: '.$last->getMessage());
        }
        throw new \RuntimeException('Không thể tạo phản hồi từ Gemini API');
    }
}
