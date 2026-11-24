<?php

namespace App\Http\Controllers;

use App\Realtime\Dto;
use App\Support\Auth;
use App\Support\Http;
use App\Support\Legacy;
use App\Support\Validate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Port of rt_chat_routes.py (/rt/*). */
class RtController
{
    private const RT_EXTS = ['.jpg', '.jpeg', '.png', '.gif', '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.txt', '.zip'];

    private static function isMember(string $conversationId, string $userId): ?object
    {
        return DB::table('rt_conversation_members')->where('conversation_id', $conversationId)->where('user_id', $userId)->first();
    }

    private static function conversationDto(object $conv, int $unread): array
    {
        return [
            'id' => $conv->id,
            'type' => $conv->type,
            'title' => $conv->title,
            'relatedEntityType' => $conv->related_entity_type,
            'relatedEntityId' => $conv->related_entity_id,
            'createdAt' => Legacy::isoZ($conv->created_at),
            'updatedAt' => Legacy::isoZ($conv->updated_at),
            'members' => Dto::members($conv->id, [Legacy::class, 'iso']),
            'lastMessage' => Dto::lastMessage($conv->id),
            'unreadCount' => $unread,
        ];
    }

    public function conversations(Request $r)
    {
        $uid = Auth::user($r)['id'];
        $ids = DB::table('rt_conversation_members')->where('user_id', $uid)->where('is_accepted', true)->pluck('conversation_id')->all();
        if (! $ids) {
            return Http::json([]);
        }
        $convs = DB::table('rt_conversations')->whereIn('id', $ids)->orderByDesc('updated_at')->get();
        $out = [];
        foreach ($convs as $conv) {
            $unread = 0;
            if (Dto::lastMessage($conv->id)) {
                $lastReadAt = DB::table('rt_message_receipts as r')
                    ->join('rt_messages as m', 'm.id', '=', 'r.message_id')
                    ->where('r.user_id', $uid)->whereNotNull('r.read_at')->where('m.conversation_id', $conv->id)
                    ->orderByDesc('r.read_at')->value('m.created_at');
                $q = DB::table('rt_messages')->where('conversation_id', $conv->id)->where('sender_id', '!=', $uid)->whereNull('deleted_at');
                if ($lastReadAt) {
                    $q->where('created_at', '>', $lastReadAt);
                }
                $unread = $q->count();
            }
            $out[] = self::conversationDto($conv, $unread);
        }

        return Http::json($out);
    }

    public function pending(Request $r)
    {
        $uid = Auth::user($r)['id'];
        $ids = DB::table('rt_conversation_members')->where('user_id', $uid)->where('is_accepted', false)->pluck('conversation_id')->all();
        if (! $ids) {
            return Http::json([]);
        }
        $out = [];
        foreach (DB::table('rt_conversations')->whereIn('id', $ids)->get() as $conv) {
            $unread = DB::table('rt_messages as m')
                ->where('m.conversation_id', $conv->id)->where('m.sender_id', '!=', $uid)->whereNull('m.deleted_at')
                ->whereNotExists(fn ($q) => $q->from('rt_message_receipts as r')->whereColumn('r.message_id', 'm.id')
                    ->where('r.user_id', $uid)->whereNotNull('r.read_at'))
                ->count();
            $out[] = self::conversationDto($conv, $unread);
        }

        return Http::json($out);
    }

    public function direct(Request $r)
    {
        $uid = Auth::user($r)['id'];
        [$d] = Validate::body($r, ['email' => ['?email', 'default' => null], 'other_user_id' => ['?str', 'default' => null]]);
        if (! $d['email'] && ! $d['other_user_id']) {
            Http::fail(400, 'Cần email hoặc other_user_id');
        }
        $other = $d['email']
            ? DB::table('users')->where('email', strtolower(trim($d['email'])))->first()
            : DB::table('users')->find($d['other_user_id']);
        if (! $other) {
            Http::fail(404, 'Không tìm thấy người dùng');
        }
        if ($other->id === $uid) {
            Http::fail(400, 'Không thể chat với chính bạn');
        }
        $want = [$uid, $other->id];
        sort($want);
        $members = DB::table('rt_conversation_members as m')->join('rt_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.type', 'direct')->orderBy('c.rowid')->get(['m.conversation_id', 'm.user_id']);
        $byConv = [];
        foreach ($members as $m) {
            $byConv[$m->conversation_id][] = $m->user_id;
        }
        foreach ($byConv as $cid => $uids) {
            sort($uids);
            if ($uids === $want) {
                return Http::json(['conversation_id' => $cid]);
            }
        }
        $cid = Legacy::uuid4();
        DB::transaction(function () use ($cid, $uid, $other) {
            $now = Legacy::now();
            DB::table('rt_conversations')->insert(['id' => $cid, 'type' => 'direct', 'title' => null, 'created_at' => $now, 'updated_at' => $now]);
            foreach ([$uid, $other->id] as $id) {
                DB::table('rt_conversation_members')->insert([
                    'conversation_id' => $cid, 'user_id' => $id, 'role' => 'member', 'joined_at' => $now, 'is_accepted' => $id === $uid,
                ]);
            }
        });

        return Http::json(['conversation_id' => $cid]);
    }

    public function messages(Request $r, string $conversationId)
    {
        $uid = Auth::user($r)['id'];
        $q = Validate::query($r, [
            'after' => ['?str', 'default' => null], 'before' => ['?str', 'default' => null],
            'limit' => ['int', 'default' => 50, 'le' => 100],
        ]);
        if (! DB::table('rt_conversations')->where('id', $conversationId)->exists()) {
            Http::fail(404, 'Conversation not found');
        }
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(403, 'Not a member');
        }
        $query = DB::table('rt_messages')->where('conversation_id', $conversationId)->whereNull('deleted_at');
        if ($q['after']) {
            $ref = DB::table('rt_messages')->where('id', $q['after'])->value('created_at');
            if ($ref) {
                $query->where('created_at', '>', $ref);
            }
            $query->orderBy('created_at');
        } elseif ($q['before']) {
            $ref = DB::table('rt_messages')->where('id', $q['before'])->value('created_at');
            if ($ref) {
                $query->where('created_at', '<', $ref);
            }
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }
        $limit = $q['limit'];
        $rows = $query->limit(max($limit + 1, 0))->get()->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, max($limit, 0));
        }
        if (! $q['after'] && ! $q['before']) {
            $rows = array_reverse($rows);
        }
        $ids = array_map(fn ($m) => $m->id, $rows);
        $users = Dto::users(array_map(fn ($m) => $m->sender_id, $rows));
        $receipts = $ids ? DB::table('rt_message_receipts')->whereIn('message_id', $ids)->orderBy('user_id')->get()->groupBy('message_id') : collect();
        $reactions = $ids ? DB::table('rt_message_reactions')->whereIn('message_id', $ids)->orderBy('user_id')->orderBy('emoji')->get()->groupBy('message_id') : collect();
        // MessageDTO.model_dump() keeps aware datetimes; FastAPI's JSON serialization renders them with "Z"
        $out = array_map(fn ($m) => Dto::message($m, $users[$m->sender_id] ?? null, [Legacy::class, 'isoZ']) + [
            'receipts' => ($receipts[$m->id] ?? collect())->map(fn ($x) => [
                'userId' => $x->user_id, 'deliveredAt' => Legacy::iso($x->delivered_at), 'readAt' => Legacy::iso($x->read_at),
            ])->values()->all(),
            'reactions' => ($reactions[$m->id] ?? collect())->map(fn ($x) => [
                'userId' => $x->user_id, 'emoji' => $x->emoji, 'createdAt' => Legacy::iso($x->created_at),
            ])->values()->all(),
        ], $rows);

        return Http::json(['messages' => $out, 'has_more' => $hasMore]);
    }

    public function accept(Request $r, string $conversationId)
    {
        $uid = Auth::user($r)['id'];
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(404, 'Conversation not found or you are not a member');
        }
        DB::table('rt_conversation_members')->where('conversation_id', $conversationId)->where('user_id', $uid)->update(['is_accepted' => true]);

        return Http::json(['success' => true]);
    }

    public function reject(Request $r, string $conversationId)
    {
        $uid = Auth::user($r)['id'];
        [$d] = Validate::body($r, ['delete_history' => ['bool', 'default' => false]]);
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(404, 'Conversation not found or you are not a member');
        }
        DB::transaction(function () use ($d, $conversationId, $uid) {
            if ($d['delete_history']) {
                $ids = DB::table('rt_messages')->where('conversation_id', $conversationId)->pluck('id')->all();
                if ($ids) {
                    DB::table('rt_message_receipts')->whereIn('message_id', $ids)->delete();
                }
                DB::table('rt_messages')->where('conversation_id', $conversationId)->delete();
                DB::table('rt_conversation_members')->where('conversation_id', $conversationId)->delete();
                DB::table('rt_conversations')->where('id', $conversationId)->delete();
            } else {
                DB::table('rt_conversation_members')->where('conversation_id', $conversationId)->where('user_id', $uid)->delete();
            }
        });

        return Http::json(['success' => true]);
    }

    public function files(Request $r)
    {
        return CoreController::storeChatFile($r, 'rt_files', self::RT_EXTS, implode(', ', self::RT_EXTS));
    }

    public function lookup(Request $r)
    {
        $uid = Auth::user($r)['id'];
        $q = Validate::query($r, ['email' => 'email']);
        $key = 'rt-lookup:'.$uid;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            Http::fail(429, 'Too many requests');
        }
        RateLimiter::hit($key, 60);
        $user = DB::table('users')->where('email', strtolower(trim($q['email'])))->first();
        if (! $user) {
            Http::fail(404, 'User not found');
        }

        return Http::json([
            'id' => $user->id, 'email' => $user->email, 'username' => $user->username,
            'display_name' => $user->display_name, 'avatar_url' => $user->avatar_url,
        ]);
    }

    // ---------- reactions ----------

    private static function messageForMember(string $messageId, string $uid): object
    {
        $m = DB::table('rt_messages')->find($messageId);
        if (! $m) {
            Http::fail(404, 'Message not found');
        }
        if (! self::isMember($m->conversation_id, $uid)) {
            Http::fail(403, 'Not a conversation member');
        }

        return $m;
    }

    public function addReaction(Request $r, string $messageId)
    {
        $uid = Auth::user($r)['id'];
        [$d] = Validate::body($r, ['emoji' => ['str', 'min' => 1, 'max' => 10]]);
        self::messageForMember($messageId, $uid);
        $exists = DB::table('rt_message_reactions')->where('message_id', $messageId)->where('user_id', $uid)->where('emoji', $d['emoji'])->exists();
        if ($exists) {
            Http::fail(409, 'Reaction already exists');
        }
        $now = Legacy::now();
        DB::table('rt_message_reactions')->insert(['message_id' => $messageId, 'user_id' => $uid, 'emoji' => $d['emoji'], 'created_at' => $now]);

        return Http::json(['message_id' => $messageId, 'user_id' => $uid, 'emoji' => $d['emoji'], 'created_at' => Legacy::isoOffset($now)]);
    }

    public function removeReaction(Request $r, string $messageId, string $emoji)
    {
        $uid = Auth::user($r)['id'];
        self::messageForMember($messageId, $uid);
        $q = DB::table('rt_message_reactions')->where('message_id', $messageId)->where('user_id', $uid)->where('emoji', $emoji);
        if (! $q->exists()) {
            Http::fail(404, 'Reaction not found');
        }
        $q->delete();

        return Http::json(['message' => 'Reaction removed']);
    }

    public function reactions(Request $r, string $messageId)
    {
        $uid = Auth::user($r)['id'];
        self::messageForMember($messageId, $uid);

        return Http::json(DB::table('rt_message_reactions')->where('message_id', $messageId)->orderBy('user_id')->orderBy('emoji')->get()->map(fn ($x) => [
            'message_id' => $x->message_id, 'user_id' => $x->user_id, 'emoji' => $x->emoji, 'created_at' => Legacy::isoOffset($x->created_at),
        ])->all());
    }

    // ---------- pins ----------

    public function pinned(Request $r, string $conversationId)
    {
        $uid = Auth::user($r)['id'];
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(403, 'Not a conversation member');
        }
        $pins = DB::table('rt_pinned_messages')->where('conversation_id', $conversationId)->orderByDesc('pinned_at')->get();
        $msgs = DB::table('rt_messages')->whereIn('id', $pins->pluck('message_id')->all())->get()->keyBy('id');
        $users = Dto::users($msgs->pluck('sender_id')->all());
        $out = [];
        foreach ($pins as $p) {
            $m = $msgs[$p->message_id] ?? null;
            $dto = null;
            if ($m) {
                $dto = Dto::message($m, $users[$m->sender_id] ?? null, [Legacy::class, 'isoZ']) + ['receipts' => null, 'reactions' => null];
                if ($m->deleted_at) {
                    $dto['content'] = 'Tin nhắn đã bị thu hồi';
                }
            }
            $out[] = [
                'messageId' => $p->message_id, 'conversationId' => $p->conversation_id, 'pinnedBy' => $p->pinned_by,
                'pinnedAt' => Legacy::isoZ($p->pinned_at), 'message' => $dto,
            ];
        }

        return Http::json($out);
    }

    public function pin(Request $r, string $conversationId, string $messageId)
    {
        $uid = Auth::user($r)['id'];
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(403, 'Not a conversation member');
        }
        if (! DB::table('rt_messages')->where('id', $messageId)->where('conversation_id', $conversationId)->exists()) {
            Http::fail(404, 'Message not found');
        }
        if (DB::table('rt_pinned_messages')->where('conversation_id', $conversationId)->where('message_id', $messageId)->exists()) {
            Http::fail(400, 'Message already pinned');
        }
        $now = Legacy::now();
        DB::table('rt_pinned_messages')->insert(['conversation_id' => $conversationId, 'message_id' => $messageId, 'pinned_by' => $uid, 'pinned_at' => $now]);

        return Http::json(['success' => true, 'pinnedAt' => Legacy::isoOffset($now)]);
    }

    public function unpin(Request $r, string $conversationId, string $messageId)
    {
        $uid = Auth::user($r)['id'];
        if (! self::isMember($conversationId, $uid)) {
            Http::fail(403, 'Not a conversation member');
        }
        $q = DB::table('rt_pinned_messages')->where('conversation_id', $conversationId)->where('message_id', $messageId);
        if (! $q->exists()) {
            Http::fail(404, 'Pinned message not found');
        }
        $q->delete();

        return Http::json(['success' => true]);
    }
}
