<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use App\Services\GeminiClient;
use App\Services\QuotaExceeded;
use App\Support\Auth;
use App\Support\Http;
use App\Support\Legacy;
use App\Support\Validate;
use App\Support\ValidationFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;

/** Root/debug, static uploads, downloads, chatbot config/files, AI chat, voucher export. */
class CoreController
{
    private const CHAT_FILE_EXTS = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.txt', '.zip', '.rar'];

    public function root()
    {
        return Http::json(['message' => 'N3T KhoHang API is running with JWT Auth']);
    }

    public function debugPaths()
    {
        $data = config('khohang.data_dir');
        $up = config('khohang.uploads_dir');
        $count = fn ($d) => is_dir($d) ? count(array_diff(scandir($d), ['.', '..'])) : 0;

        return Http::json([
            'DATA_DIR' => $data,
            'UPLOADS_DIR' => $up,
            'UPLOADS_DIR_exists' => is_dir($up),
            'rt_files' => "$up/rt_files",
            'rt_files_exists' => is_dir("$up/rt_files"),
            'chat_files' => "$up/chat_files",
            'chat_files_exists' => is_dir("$up/chat_files"),
            'rt_files_count' => $count("$up/rt_files"),
            'chat_files_count' => $count("$up/chat_files"),
        ]);
    }

    private static function mime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = (new MimeTypes)->getMimeTypes($ext);

        return $types[0] ?? 'application/octet-stream';
    }

    /** StaticFiles mount: GET /uploads/{path}. Path traversal is rejected. */
    public function uploads(string $path)
    {
        $base = realpath(config('khohang.uploads_dir'));
        $full = $base ? realpath($base.'/'.$path) : false;
        if (! $full || ! str_starts_with($full, $base.DIRECTORY_SEPARATOR) || ! is_file($full)) {
            Http::fail(404, 'Not Found');
        }
        $res = new BinaryFileResponse($full);
        $res->headers->set('Content-Type', self::mime($full));

        return $res;
    }

    public function download(string $folder, string $filename)
    {
        if (! in_array($folder, ['rt_files', 'chat_files', 'chatbot', 'logos', 'avatars'], true)) {
            Http::fail(400, 'Invalid folder');
        }
        $dir = realpath(config('khohang.uploads_dir').'/'.$folder);
        $full = $dir ? realpath($dir.'/'.$filename) : false;
        if (! $full || dirname($full) !== $dir || ! is_file($full)) {
            Http::fail(404, 'File not found');
        }

        return response((string) file_get_contents($full), 200, [
            'Content-Type' => self::mime($full),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    // ---------------- /api/chatbot ----------------

    private static function botConfig(): object
    {
        $c = DB::table('chatbot_config')->orderBy('id')->first();
        if (! $c) {
            $now = Legacy::now();
            DB::table('chatbot_config')->insert([
                'bot_name' => 'N3T Assistant', 'bot_description' => 'Trợ lý quản lý kho', 'avatar_url' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $c = DB::table('chatbot_config')->orderBy('id')->first();
        }

        return $c;
    }

    public function chatbotConfig()
    {
        $c = self::botConfig();

        return Http::json(['avatar_url' => $c->avatar_url, 'bot_name' => $c->bot_name, 'bot_description' => $c->bot_description]);
    }

    public function chatbotAvatar(Request $r)
    {
        $u = Auth::user($r);
        $file = $r->file('file');
        if (! $file) {
            throw new ValidationFailed([['type' => 'missing', 'loc' => ['body', 'file'], 'msg' => 'Field required', 'input' => null]]);
        }
        if ($u['role'] !== 'admin') {
            Http::fail(403, 'Only admin can change chatbot avatar');
        }
        if (! str_starts_with((string) $file->getClientMimeType(), 'image/')) {
            Http::fail(400, 'File must be an image');
        }
        try {
            $original = $file->getClientOriginalName() ?: 'avatar.png';
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $ext = $ext === '' ? '.png' : '.'.$ext;
            $name = 'chatbot_avatar_'.bin2hex(random_bytes(16)).$ext;
            $dir = config('khohang.uploads_dir').'/chatbot';
            @mkdir($dir, 0775, true);
            file_put_contents("$dir/$name", (string) file_get_contents($file->getRealPath()));
            $c = self::botConfig();
            if ($c->avatar_url) {
                $old = realpath(config('khohang.data_dir').'/'.ltrim($c->avatar_url, '/'));
                $base = realpath(config('khohang.uploads_dir'));
                if ($old && $base && str_starts_with($old, $base.DIRECTORY_SEPARATOR) && is_file($old)) {
                    @unlink($old);
                }
            }
            $url = "/uploads/chatbot/$name";
            DB::table('chatbot_config')->where('id', $c->id)->update(['avatar_url' => $url, 'updated_at' => Legacy::now()]);
        } catch (\Throwable $e) {
            Http::fail(500, 'Error uploading avatar: '.$e->getMessage());
        }

        return Http::json(['success' => true, 'avatar_url' => $url, 'message' => 'Chatbot avatar updated successfully']);
    }

    /** bot_name / bot_description are query parameters in the legacy contract. */
    public function updateChatbotConfig(Request $r)
    {
        $u = Auth::user($r);
        $q = Validate::query($r, ['bot_name' => ['?str', 'default' => null], 'bot_description' => ['?str', 'default' => null]]);
        if ($u['role'] !== 'admin') {
            Http::fail(403, 'Only admin can change chatbot config');
        }
        $c = self::botConfig();
        $update = array_filter($q, fn ($v) => $v !== null);
        if ($update) {
            DB::table('chatbot_config')->where('id', $c->id)->update($update + ['updated_at' => Legacy::now()]);
        }
        $c = DB::table('chatbot_config')->find($c->id);

        return Http::json(['success' => true, 'bot_name' => $c->bot_name, 'bot_description' => $c->bot_description]);
    }

    /** Shared by /api/chatbot/files and /rt/files. */
    public static function storeChatFile(Request $r, string $folder, array $exts, string $allowedText)
    {
        Auth::user($r);
        $file = $r->file('file');
        if (! $file) {
            throw new ValidationFailed([['type' => 'missing', 'loc' => ['body', 'file'], 'msg' => 'Field required', 'input' => null]]);
        }
        $name = $file->getClientOriginalName();
        if ($name === '') {
            Http::fail(400, 'Filename required');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = $ext === '' ? '' : '.'.$ext;
        if (! in_array($ext, $exts, true)) {
            Http::fail(400, "File type not allowed. Allowed: $allowedText");
        }
        $size = (int) $file->getSize();
        if ($size > 10 * 1024 * 1024) {
            Http::fail(413, 'File too large (max 10MB)');
        }
        $id = Legacy::uuid4();
        $dir = config('khohang.uploads_dir').'/'.$folder;
        @mkdir($dir, 0775, true);
        $file->move($dir, $id.$ext);

        return Http::json([
            'file_id' => $id,
            'url' => "/uploads/$folder/$id$ext",
            'name' => $name,
            'size' => $size,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
        ]);
    }

    public function chatbotFile(Request $r)
    {
        return self::storeChatFile($r, 'chat_files', self::CHAT_FILE_EXTS, implode(', ', self::CHAT_FILE_EXTS));
    }

    // ---------------- /ai ----------------

    private function aiReply(Request $r): string
    {
        [$d] = Validate::body($r, ['prompt' => 'str', 'system_instruction' => ['?str', 'default' => null]]);
        if (! GeminiClient::configured()) {
            Http::fail(501, 'Gemini API chưa được cấu hình');
        }
        try {
            return GeminiClient::generateReply($d['prompt'], $d['system_instruction']);
        } catch (QuotaExceeded $e) {
            Http::fail(429, $e->getMessage(), $e->retryAfter ? ['Retry-After' => (string) (int) $e->retryAfter] : []);
        } catch (\Throwable $e) {
            Http::fail(500, 'Gemini error: '.$e->getMessage());
        }
    }

    public function aiChat(Request $r)
    {
        return Http::json(['reply' => $this->aiReply($r), 'model' => config('khohang.gemini_model')]);
    }

    public function aiChatMarkdown(Request $r)
    {
        return response($this->aiReply($r), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
    }

    // ---------------- /export ----------------

    private const VOUCHER = [
        'voucher_type' => 'str', 'voucher_no' => 'str', 'voucher_date' => 'str', 'partner_name' => 'str',
        'invoice_no' => ['?str', 'default' => null], 'invoice_date' => ['?str', 'default' => null],
        'warehouse_code' => 'str', 'warehouse_location' => ['?str', 'default' => null],
        'attachments' => ['?str', 'default' => null], 'tax_rate' => ['?float', 'default' => 0.0],
        'prepared_by' => ['?str', 'default' => null], 'receiver' => ['?str', 'default' => null],
        'storekeeper' => ['?str', 'default' => null], 'director' => ['?str', 'default' => null],
        'items' => ['list', 'item' => ['fields' => [
            'sku' => 'str', 'name' => 'str', 'unit' => 'str',
            'qty_doc' => 'float', 'qty_actual' => 'float', 'unit_price' => 'float',
        ]]],
    ];

    private function exportResponse(string $content, string $filename, string $mime)
    {
        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    public function exportExcel(Request $r)
    {
        [$d] = Validate::body($r, self::VOUCHER);
        // FastAPI wrapped the 400 inside `except Exception` and re-raised it as 500.
        if (count($d['items']) > 18) {
            Http::fail(500, 'Export Excel error: 400: Template chỉ hỗ trợ tối đa 18 dòng hàng hóa');
        }
        try {
            [$content, $filename] = ExportService::excel($d);
        } catch (\Throwable $e) {
            Http::fail(500, 'Export Excel error: '.$e->getMessage());
        }

        return $this->exportResponse($content, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function exportPdf(Request $r)
    {
        [$d] = Validate::body($r, self::VOUCHER);
        if (count($d['items']) > 18) {
            Http::fail(500, 'Export PDF error: 400: Template chỉ hỗ trợ tối đa 18 dòng hàng hóa');
        }
        try {
            [$content, $filename] = ExportService::pdf($d);
        } catch (\Throwable $e) {
            Http::fail(500, 'Export PDF error: '.$e->getMessage());
        }

        return $this->exportResponse($content, $filename, 'application/pdf');
    }
}
