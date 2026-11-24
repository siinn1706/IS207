<?php

namespace App\Realtime;

use App\Support\Legacy;
use Illuminate\Support\Facades\DB;

/** Message / conversation DTO builders shared by /rt HTTP routes and the /ws/rt server. */
class Dto
{
    /** @param callable(?string):?string $ts formatter for created/edited/deleted */
    public static function message(object $m, ?object $sender, callable $ts, bool $withReply = true): array
    {
        $dto = [
            'id' => $m->id,
            'conversationId' => $m->conversation_id,
            'senderId' => $m->sender_id,
            'clientMessageId' => $m->client_message_id,
            'content' => $m->content,
            'contentType' => $m->content_type,
            'attachments' => Legacy::json($m->attachments_json),
        ];
        if ($withReply) {
            $dto['replyToId'] = $m->reply_to_id;
        }

        return $dto + [
            'createdAt' => $ts($m->created_at),
            'editedAt' => $ts($m->edited_at),
            'deletedAt' => $ts($m->deleted_at),
            'senderEmail' => $sender?->email,
            'senderDisplayName' => $sender?->display_name,
            'senderAvatarUrl' => $sender?->avatar_url,
        ];
    }

    public static function users(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('users')->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id')->all();
    }

    /** Members in table order with user info; joined_at kept as naive ISO (no ensure_utc in FastAPI). */
    public static function members(string $conversationId, callable $joinedFmt): array
    {
        $members = DB::table('rt_conversation_members')->where('conversation_id', $conversationId)->orderBy('user_id')->get()->all();
        $users = self::users(array_map(fn ($m) => $m->user_id, $members));

        return array_map(fn ($m) => [
            'userId' => $m->user_id,
            'role' => $m->role,
            'joinedAt' => $joinedFmt($m->joined_at),
            'isAccepted' => (bool) $m->is_accepted,
            'userEmail' => ($users[$m->user_id] ?? null)?->email,
            'userDisplayName' => ($users[$m->user_id] ?? null)?->display_name,
            'userAvatarUrl' => ($users[$m->user_id] ?? null)?->avatar_url,
        ], $members);
    }

    public static function lastMessage(string $conversationId): ?array
    {
        $m = DB::table('rt_messages')->where('conversation_id', $conversationId)->whereNull('deleted_at')
            ->orderByDesc('created_at')->first();

        return $m ? [
            'id' => $m->id, 'content' => $m->content, 'senderId' => $m->sender_id,
            'createdAt' => Legacy::isoOffset($m->created_at),
        ] : null;
    }
}
