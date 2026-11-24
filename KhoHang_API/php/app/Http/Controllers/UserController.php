<?php

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\Http;
use App\Support\Images;
use App\Support\Legacy;
use App\Support\Serializers as S;
use App\Support\Validate;
use App\Support\ValidationFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** user_routes.py (/users/*), /user/preferences and bot chat history (/chat/messages). */
class UserController
{
    // ---------------- /users ----------------

    public function search(Request $r)
    {
        Auth::user($r);
        $q = Validate::query($r, [
            'search' => ['?str', 'default' => null, 'min' => 1],
            'limit' => ['int', 'default' => 10, 'ge' => 1, 'le' => 50],
        ]);
        if (! $q['search']) {
            return Http::json(['users' => []]);
        }
        $p = '%'.mb_strtolower($q['search']).'%';
        $users = DB::table('users')
            ->where(fn ($w) => $w->where('email', 'like', $p)->orWhere('display_name', 'like', $p)->orWhere('username', 'like', $p))
            ->limit($q['limit'])->get();

        return Http::json(['users' => $users->map(fn ($u) => [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email,
            'display_name' => $u->display_name, 'avatar_url' => $u->avatar_url,
        ])->all()]);
    }

    private static function current(Request $r): object
    {
        $cu = Auth::user($r);
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }

        return $user;
    }

    public function me(Request $r)
    {
        $u = self::current($r);

        return Http::json(AuthController::profile($u) + [
            'created_at' => Legacy::iso($u->created_at),
            'updated_at' => Legacy::iso($u->updated_at),
        ]);
    }

    public function updateMe(Request $r)
    {
        $cu = Auth::user($r);
        [$d] = Validate::body($r, [
            'display_name' => ['?str', 'default' => null, 'min' => 1, 'max' => 100],
            'avatar_url' => ['?str', 'default' => null],
        ]);
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        $update = array_filter($d, fn ($v) => $v !== null);
        if ($update) {
            DB::table('users')->where('id', $user->id)->update($update + ['updated_at' => Legacy::now()]);
            $user = DB::table('users')->find($user->id);
        }

        return Http::json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id, 'username' => $user->username, 'email' => $user->email,
                'display_name' => $user->display_name, 'avatar_url' => $user->avatar_url, 'role' => $user->role,
            ],
        ]);
    }

    public function uploadAvatar(Request $r)
    {
        $cu = Auth::user($r);
        $file = $r->file('file');
        if (! $file) {
            throw new ValidationFailed([['type' => 'missing', 'loc' => ['body', 'file'], 'msg' => 'Field required', 'input' => null]]);
        }
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if (! in_array($file->getClientMimeType(), $allowed, true)) {
            Http::fail(400, 'Invalid file type. Only images are allowed.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        if (strlen($bytes) > 10 * 1024 * 1024) {
            Http::fail(400, 'File too large. Max 10MB allowed.');
        }
        $dir = config('khohang.uploads_dir').'/avatars';
        @mkdir($dir, 0775, true);
        $filename = Legacy::uuid4().'.webp';
        try {
            Images::toWebpAvatar($bytes, "$dir/$filename");
        } catch (\Throwable $e) {
            Http::fail(400, 'Failed to process image: '.$e->getMessage());
        }
        $user = DB::table('users')->find($cu['id']);
        if (! $user) {
            Http::fail(404, 'User not found');
        }
        self::deleteOwnAvatarFile($user->avatar_url);
        $url = "/uploads/avatars/$filename";
        DB::table('users')->where('id', $user->id)->update(['avatar_url' => $url, 'updated_at' => Legacy::now()]);

        return Http::json(['message' => 'Avatar uploaded successfully', 'avatar_url' => $url]);
    }

    private static function deleteOwnAvatarFile(?string $url): void
    {
        if ($url && str_starts_with($url, '/uploads/avatars/')) {
            $name = basename($url);
            $path = config('khohang.uploads_dir').'/avatars/'.$name;
            if ($name !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function deleteAvatar(Request $r)
    {
        $user = self::current($r);
        // FastAPI resolved this path against the process CWD and never found the
        // file; the PHP port deletes the real file under data/uploads/avatars.
        self::deleteOwnAvatarFile($user->avatar_url);
        DB::table('users')->where('id', $user->id)->update(['avatar_url' => null, 'updated_at' => Legacy::now()]);

        return Http::json(['message' => 'Avatar deleted successfully']);
    }

    // ---------------- /user/preferences ----------------

    private const THEME = ['model', 'fields' => [
        'gradient_id' => ['str', 'default' => 'default'],
        'pattern_id' => ['?str', 'default' => null],
        'pattern_opacity' => ['float', 'default' => 0.1],
        'pattern_size_px' => ['int', 'default' => 300],
        'pattern_tint' => ['?str', 'default' => null],
    ]];

    private static function defaults(string $userId): array
    {
        $now = Legacy::now();

        return [
            'user_id' => $userId, 'accent_id' => 'blue',
            'light_mode_gradient_id' => 'default', 'light_mode_pattern_id' => null,
            'light_mode_pattern_opacity' => 0.1, 'light_mode_pattern_size_px' => 300, 'light_mode_pattern_tint' => null,
            'dark_mode_gradient_id' => 'default', 'dark_mode_pattern_id' => null,
            'dark_mode_pattern_opacity' => 0.1, 'dark_mode_pattern_size_px' => 300, 'dark_mode_pattern_tint' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    public function preferences(Request $r)
    {
        $cu = Auth::user($r);
        $p = DB::table('user_preferences')->where('user_id', $cu['id'])->first();
        if (! $p) {
            DB::table('user_preferences')->insert(self::defaults($cu['id']));
            $p = DB::table('user_preferences')->where('user_id', $cu['id'])->first();
        }

        return Http::json(S::preferences($p));
    }

    public function updatePreferences(Request $r)
    {
        $cu = Auth::user($r);
        [$d] = Validate::body($r, [
            'accent_id' => ['?str', 'default' => null],
            'light_mode_theme' => ['?model', 'default' => null] + self::THEME,
            'dark_mode_theme' => ['?model', 'default' => null] + self::THEME,
        ]);
        if (! DB::table('user_preferences')->where('user_id', $cu['id'])->exists()) {
            DB::table('user_preferences')->insert(self::defaults($cu['id']));
        }
        $update = [];
        if ($d['accent_id'] !== null) {
            $update['accent_id'] = $d['accent_id'];
        }
        foreach (['light_mode', 'dark_mode'] as $mode) {
            $t = $d[$mode.'_theme'];
            if ($t !== null) {
                foreach ($t as $k => $v) {
                    $update["{$mode}_$k"] = $v;
                }
            }
        }
        $update['updated_at'] = Legacy::now();
        DB::table('user_preferences')->where('user_id', $cu['id'])->update($update);

        return Http::json(S::preferences(DB::table('user_preferences')->where('user_id', $cu['id'])->first()));
    }

    // ---------------- /chat/messages (chatbot history) ----------------

    /**
     * Each user may only touch their own chatbot history "bot_<user id>".
     * (FastAPI also accepted ANY id starting with "bot_", exposing other users' chats.)
     */
    private static function guard(string $conversationId, array $user): void
    {
        if ($conversationId !== 'bot_'.$user['id']) {
            Http::fail(403, 'Access denied to this conversation');
        }
    }

    public function chatMessages(Request $r, string $conversationId)
    {
        self::guard($conversationId, Auth::user($r));
        $rows = DB::table('chat_messages')->where('conversation_id', $conversationId)->orderBy('created_at')->get();

        return Http::json($rows->map([S::class, 'chatMessage'])->all());
    }

    public function createChatMessage(Request $r)
    {
        $user = Auth::user($r);
        [$m] = Validate::body($r, [
            'id' => 'str', 'conversation_id' => 'str', 'sender' => 'str', 'text' => 'str',
            'reply_to' => ['?model', 'default' => null, 'fields' => ['id' => 'str', 'text' => 'str', 'sender' => 'str']],
            'reactions' => ['list', 'default' => [], 'item' => 'str'],
        ]);
        self::guard($m['conversation_id'], $user);
        $existing = DB::table('chat_messages')->find($m['id']);
        if ($existing) {
            return Http::json(S::chatMessage($existing), 201);
        }
        $now = Legacy::now();
        DB::table('chat_messages')->insert([
            'id' => $m['id'], 'conversation_id' => $m['conversation_id'], 'sender' => $m['sender'], 'text' => $m['text'],
            'reply_to' => Legacy::toJson($m['reply_to']), 'reactions' => Legacy::toJson($m['reactions'] ?: []),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return Http::json(S::chatMessage(DB::table('chat_messages')->find($m['id'])), 201);
    }

    public function updateReactions(Request $r, string $messageId)
    {
        $user = Auth::user($r);
        [$d] = Validate::body($r, ['reactions' => ['?list', 'default' => null, 'item' => 'str']]);
        $msg = DB::table('chat_messages')->find($messageId);
        if (! $msg) {
            Http::fail(404, 'Không tìm thấy tin nhắn');
        }
        self::guard($msg->conversation_id, $user);
        $update = ['updated_at' => Legacy::now()];
        if ($d['reactions'] !== null) {
            $update['reactions'] = Legacy::toJson($d['reactions']);
        }
        DB::table('chat_messages')->where('id', $messageId)->update($update);

        return Http::json(S::chatMessage(DB::table('chat_messages')->find($messageId)));
    }

    public function clearChat(Request $r, string $conversationId)
    {
        self::guard($conversationId, Auth::user($r));
        DB::table('chat_messages')->where('conversation_id', $conversationId)->delete();

        return Http::noContent();
    }
}
