<?php

namespace App\Realtime;

use App\Support\Jwt;
use App\Support\Legacy;
use Illuminate\Support\Facades\DB;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Port of rt_chat_ws.py: WebSocket endpoint /ws/rt?token=<JWT> speaking the same
 * JSON frames {type, reqId, data}. Runs as ONE Workerman process so the in-memory
 * connection registry is shared, exactly like the single uvicorn process before.
 *
 * reqId quirks of the Python handlers are preserved on purpose (client contract):
 *  - client:hello / msg:read errors / conv:sync:result take reqId from data.reqId
 *  - conv:join errors carry reqId = null
 *  - msg:send/edit/delete/react/pin/unpin use the frame's top-level reqId
 */
class RtServer
{
    /** @var array<string, array<int, TcpConnection>> */
    private array $userConnections = [];

    /** @var array<string, array<int, TcpConnection>> */
    private array $roomConnections = [];

    /** @var array<int, string> */
    private array $connUser = [];

    /** @var array<int, array<string, true>> */
    private array $connRooms = [];

    private array $presence = [];

    private array $sendTimestamps = [];

    public static function boot(string $host, int $port, int $internalPort): Worker
    {
        $self = new self;
        $worker = new Worker("websocket://$host:$port");
        $worker->name = 'khohang-rt';
        $worker->count = 1;
        $worker->onWebSocketConnect = fn ($c, $r) => $self->onHandshake($c, $r);
        $worker->onMessage = fn ($c, $data) => $self->onMessage($c, (string) $data);
        $worker->onClose = fn ($c) => $self->disconnect($c);
        $worker->onWorkerStart = function () use ($self, $internalPort) {
            // HTTP app -> WS bridge (loopback only), same process => same registry.
            $inner = new Worker("text://127.0.0.1:$internalPort");
            $inner->onMessage = function (TcpConnection $c, $line) use ($self) {
                $msg = json_decode((string) $line, true);
                if (is_array($msg) && isset($msg['type'])) {
                    $self->broadcastSystemEvent((string) $msg['type'], (array) ($msg['data'] ?? []));
                }
                $c->close();
            };
            $inner->listen();
            Timer::add(30, fn () => $self->heartbeat());
        };

        return $worker;
    }

    // ------------------------------------------------------------------ connection

    public function onHandshake(TcpConnection $conn, Request $request): void
    {
        try {
            if ($request->path() !== '/ws/rt') {
                $conn->send("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n", true);
                $conn->destroy();

                return;
            }
            $token = $request->get('token');
            $payload = is_string($token) && $token !== '' ? Jwt::decode($token) : null;
            $userId = $payload['sub'] ?? null;
            if (! $userId) {
                // Starlette rejected the handshake (HTTP 403) when the token was invalid.
                $conn->send("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n", true);
                $conn->destroy();

                return;
            }
            $conn->websocketType = "\x81"; // text frames
            $this->userConnections[$userId][$conn->id] = $conn;
            $this->connUser[$conn->id] = $userId;
            $this->connRooms[$conn->id] = [];
            $this->presence[$userId] = ['is_online' => true, 'last_seen_at' => Legacy::isoOffsetDt(Legacy::nowDt())];
        } catch (\Throwable $e) {
            Worker::safeEcho('[WS] handshake error: '.$e->getMessage()."\n");
            $conn->destroy();
        }
    }

    public function disconnect(TcpConnection $conn): void
    {
        $uid = $this->connUser[$conn->id] ?? null;
        if ($uid === null) {
            return;
        }
        unset($this->userConnections[$uid][$conn->id]);
        if (empty($this->userConnections[$uid])) {
            unset($this->userConnections[$uid]);
            $this->presence[$uid] = ['is_online' => false, 'last_seen_at' => Legacy::isoOffsetDt(Legacy::nowDt())];
        }
        foreach (array_keys($this->connRooms[$conn->id] ?? []) as $room) {
            unset($this->roomConnections[$room][$conn->id]);
            if (empty($this->roomConnections[$room])) {
                unset($this->roomConnections[$room]);
            }
        }
        unset($this->connUser[$conn->id], $this->connRooms[$conn->id]);
    }

    private function heartbeat(): void
    {
        foreach ($this->userConnections as $conns) {
            foreach ($conns as $c) {
                $this->toSocket($c, ['type' => 'ping']);
            }
        }
    }

    // ------------------------------------------------------------------ sending

    private static function encode(array $msg): string
    {
        return json_encode($msg, JSON_PRESERVE_ZERO_FRACTION);
    }

    private function toSocket(TcpConnection $c, array $msg): void
    {
        if ($c->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            $this->disconnect($c);

            return;
        }
        $c->send(self::encode($msg));
    }

    private function toUser(string $userId, array $msg): void
    {
        foreach ($this->userConnections[$userId] ?? [] as $c) {
            $this->toSocket($c, $msg);
        }
    }

    private function toRoom(string $room, array $msg, ?TcpConnection $exclude = null): void
    {
        foreach ($this->roomConnections[$room] ?? [] as $c) {
            if ($exclude && $c->id === $exclude->id) {
                continue;
            }
            $this->toSocket($c, $msg);
        }
    }

    public function broadcastSystemEvent(string $type, array $data): void
    {
        foreach ($this->userConnections as $conns) {
            foreach ($conns as $c) {
                $this->toSocket($c, ['type' => $type, 'data' => $data]);
            }
        }
    }

    private function error(TcpConnection $c, mixed $reqId, string $code, string $message): void
    {
        $this->toSocket($c, ['type' => 'error', 'reqId' => $reqId, 'data' => ['code' => $code, 'message' => $message]]);
    }

    private function rateOk(string $uid, int $limit = 5, float $window = 1.0): bool
    {
        $now = microtime(true);
        $ts = array_values(array_filter($this->sendTimestamps[$uid] ?? [], fn ($t) => $now - $t < $window));
        if (count($ts) >= $limit) {
            $this->sendTimestamps[$uid] = $ts;

            return false;
        }
        $ts[] = $now;
        $this->sendTimestamps[$uid] = $ts;

        return true;
    }

    // ------------------------------------------------------------------ dispatch

    public function onMessage(TcpConnection $c, string $raw): void
    {
        $uid = $this->connUser[$c->id] ?? null;
        if ($uid === null) {
            return;
        }
        $frame = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->toSocket($c, ['type' => 'error', 'data' => ['code' => 'INVALID_JSON', 'message' => 'Invalid JSON']]);

            return;
        }
        try {
            if (! is_array($frame) || array_is_list($frame) && $frame !== []) {
                throw new \RuntimeException("'".get_debug_type($frame)."' object has no attribute 'get'");
            }
            $type = $frame['type'] ?? null;
            $reqId = $frame['reqId'] ?? null;
            $data = array_key_exists('data', $frame) ? $frame['data'] : [];
            if (! is_array($data)) {
                throw new \RuntimeException("'".get_debug_type($data)."' object does not support item assignment");
            }
            $withReq = $data + [];
            $withReq['_reqId'] = $reqId;
            match ($type) {
                'client:hello' => $this->hello($c, $uid, $data),
                'conv:join' => $this->join($c, $uid, $data),
                'msg:send' => $this->send($c, $uid, $withReq),
                'msg:edit' => $this->edit($c, $uid, $withReq),
                'msg:delete' => $this->delete($c, $uid, $withReq),
                'msg:react' => $this->react($c, $uid, $withReq),
                'msg:pin' => $this->pin($c, $uid, $withReq),
                'msg:unpin' => $this->unpin($c, $uid, $withReq),
                'msg:read' => $this->read($c, $uid, $data),
                'typing' => $this->typing($c, $uid, $data),
                'conv:sync' => $this->sync($c, $uid, $withReq),
                'pong' => null,
                default => $this->error($c, $reqId, 'UNKNOWN_EVENT', 'Unknown event type: '.(is_string($type) ? $type : json_encode($type))),
            };
        } catch (\Throwable $e) {
            Worker::safeEcho('[WS] Error handling event: '.$e->getMessage()."\n");
            $this->toSocket($c, ['type' => 'error', 'data' => ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]]);
        }
    }

    private static function member(string $cid, string $uid): bool
    {
        return DB::table('rt_conversation_members')->where('conversation_id', $cid)->where('user_id', $uid)->exists();
    }

    private static function truthy(mixed ...$vals): bool
    {
        foreach ($vals as $v) {
            if ($v === null || $v === '' || $v === false || $v === 0 || $v === 0.0 || $v === []) {
                return false;
            }
        }

        return true;
    }

    private static function strlenPy(mixed $s): int
    {
        if (is_string($s)) {
            return mb_strlen($s);
        }
        if (is_array($s)) {
            return count($s);
        }
        throw new \RuntimeException("object of type '".get_debug_type($s)."' has no len()");
    }

    private function memberIds(string $cid): array
    {
        return DB::table('rt_conversation_members')->where('conversation_id', $cid)->orderBy('user_id')->pluck('user_id')->all();
    }

    // ------------------------------------------------------------------ handlers

    private function hello(TcpConnection $c, string $uid, array $d): void
    {
        $this->toSocket($c, ['type' => 'server:hello', 'reqId' => $d['reqId'] ?? null, 'data' => [
            'userId' => $uid, 'serverTime' => Legacy::isoOffsetDt(Legacy::nowDt()), 'heartbeatIntervalMs' => 30000,
        ]]);
    }

    private function join(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        if (! $cid) {
            $this->error($c, $d['_reqId'] ?? null, 'INVALID_REQUEST', 'conversationId required');

            return;
        }
        if (! self::member((string) $cid, $uid)) {
            $this->error($c, $d['_reqId'] ?? null, 'FORBIDDEN', 'Not a member');

            return;
        }
        $this->roomConnections[$cid][$c->id] = $c;
        $this->connRooms[$c->id][$cid] = true;
    }

    private function send(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $clientId = $d['clientMessageId'] ?? null;
        $content = array_key_exists('content', $d) ? $d['content'] : '';
        $contentType = array_key_exists('contentType', $d) ? $d['contentType'] : 'text';
        $attachments = $d['attachments'] ?? null;
        $replyTo = $d['replyToId'] ?? null;
        $req = $d['_reqId'] ?? null;

        if (! self::truthy($cid, $clientId)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId and clientMessageId required');

            return;
        }
        if (! $this->rateOk($uid)) {
            $this->error($c, $req, 'RATE_LIMIT', 'Too many messages');

            return;
        }
        if (! self::member($cid, $uid)) {
            $this->error($c, $req, 'FORBIDDEN', 'Not a member');

            return;
        }
        if (self::strlenPy($content) > 4000) {
            $this->error($c, $req, 'INVALID_REQUEST', 'Content too long (max 4000 chars)');

            return;
        }
        $existing = DB::table('rt_messages')->where('sender_id', $uid)->where('client_message_id', $clientId)->first();
        if ($existing) {
            $this->toSocket($c, ['type' => 'msg:ack', 'reqId' => $req, 'data' => [
                'conversationId' => $cid, 'clientMessageId' => $clientId,
                'serverMessageId' => $existing->id, 'createdAtServer' => Legacy::isoOffset($existing->created_at),
            ]]);

            return;
        }

        $mid = Legacy::uuid4();
        $now = Legacy::now();
        $memberIds = $this->memberIds($cid);
        DB::transaction(function () use ($mid, $cid, $uid, $clientId, $content, $contentType, $attachments, $replyTo, $now, $memberIds) {
            DB::table('rt_messages')->insert([
                'id' => $mid, 'conversation_id' => $cid, 'sender_id' => $uid, 'client_message_id' => $clientId,
                'content' => $content, 'content_type' => $contentType, 'attachments_json' => Legacy::toJson($attachments),
                'reply_to_id' => $replyTo, 'created_at' => $now,
            ]);
            foreach ($memberIds as $m) {
                DB::table('rt_message_receipts')->insert([
                    'message_id' => $mid, 'user_id' => $m, 'delivered_at' => $m === $uid ? $now : null, 'read_at' => null,
                ]);
            }
            DB::table('rt_conversations')->where('id', $cid)->update(['updated_at' => $now]);
        });

        $sender = DB::table('users')->find($uid);
        $this->toSocket($c, ['type' => 'msg:ack', 'reqId' => $req, 'data' => [
            'conversationId' => $cid, 'clientMessageId' => $clientId,
            'serverMessageId' => $mid, 'createdAtServer' => Legacy::isoOffset($now),
        ]]);

        $msgDto = [
            'id' => $mid, 'conversationId' => $cid, 'senderId' => $uid, 'clientMessageId' => $clientId,
            'content' => $content, 'contentType' => $contentType, 'attachments' => $attachments, 'replyToId' => $replyTo,
            'createdAt' => Legacy::isoOffset($now), 'editedAt' => null, 'deletedAt' => null,
            'senderEmail' => $sender?->email, 'senderDisplayName' => $sender?->display_name, 'senderAvatarUrl' => $sender?->avatar_url,
        ];
        foreach ($memberIds as $m) {
            $this->toUser($m, ['type' => 'msg:new', 'data' => ['message' => $msgDto]]);
        }

        $conv = DB::table('rt_conversations')->find($cid);
        $membersDto = Dto::members($cid, [Legacy::class, 'isoOffset']);
        $last = ['id' => $mid, 'content' => $content, 'senderId' => $uid, 'createdAt' => Legacy::isoOffset($now)];
        foreach ($memberIds as $m) {
            $unread = 0;
            if ($m !== $uid) {
                $unread = DB::table('rt_messages as msg')
                    ->join('rt_message_receipts as r', fn ($j) => $j->on('r.message_id', '=', 'msg.id')->where('r.user_id', '=', $m))
                    ->where('msg.conversation_id', $cid)->where('msg.sender_id', '!=', $m)->whereNull('msg.deleted_at')
                    ->whereNull('r.read_at')->count();
            }
            if (! $conv) {
                throw new \RuntimeException("'NoneType' object has no attribute 'id'");
            }
            $this->toUser($m, ['type' => 'conv:upsert', 'data' => ['conversation' => [
                'id' => $conv->id, 'type' => $conv->type, 'title' => $conv->title,
                'relatedEntityType' => $conv->related_entity_type, 'relatedEntityId' => $conv->related_entity_id,
                'createdAt' => Legacy::isoOffset($conv->created_at), 'updatedAt' => Legacy::isoOffset($conv->updated_at),
                'members' => $membersDto, 'lastMessage' => $last, 'unreadCount' => $unread,
            ]]]);
        }

        foreach ($memberIds as $m) {
            if ($m === $uid || empty($this->userConnections[$m])) {
                continue;
            }
            $at = Legacy::now();
            $n = DB::table('rt_message_receipts')->where('message_id', $mid)->where('user_id', $m)->update(['delivered_at' => $at]);
            if ($n) {
                $this->toUser($uid, ['type' => 'msg:delivered', 'data' => [
                    'conversationId' => $cid, 'messageId' => $mid, 'userId' => $m, 'deliveredAt' => Legacy::isoOffset($at),
                ]]);
            }
        }
    }

    private function read(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $lastId = $d['lastReadMessageId'] ?? null;
        if (! self::truthy($cid, $lastId)) {
            $this->error($c, $d['reqId'] ?? null, 'INVALID_REQUEST', 'conversationId and lastReadMessageId required');

            return;
        }
        $last = DB::table('rt_messages')->find($lastId);
        if (! $last) {
            return;
        }
        $msgs = DB::table('rt_messages')->where('conversation_id', $cid)->where('created_at', '<=', $last->created_at)
            ->where('sender_id', '!=', $uid)->whereNull('deleted_at')->get();
        $readAt = Legacy::now();
        $notify = [];
        DB::transaction(function () use ($msgs, $uid, $readAt, &$notify) {
            foreach ($msgs as $m) {
                $receipt = DB::table('rt_message_receipts')->where('message_id', $m->id)->where('user_id', $uid)->first();
                if ($receipt && ! $receipt->read_at) {
                    DB::table('rt_message_receipts')->where('message_id', $m->id)->where('user_id', $uid)->update(['read_at' => $readAt]);
                    $notify[] = $m;
                }
            }
        });
        foreach ($notify as $m) {
            $this->toUser($m->sender_id, ['type' => 'msg:read', 'data' => [
                'conversationId' => $cid, 'messageId' => $m->id, 'userId' => $uid, 'readAt' => Legacy::isoOffset($readAt),
            ]]);
        }
    }

    private function typing(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        if (! $cid) {
            return;
        }
        $this->toRoom((string) $cid, ['type' => 'typing', 'data' => [
            'conversationId' => $cid, 'userId' => $uid, 'isTyping' => $d['isTyping'] ?? false,
        ]], $c);
    }

    private function pin(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $mid = $d['messageId'] ?? null;
        $req = $d['_reqId'] ?? null;
        if (! self::truthy($cid, $mid)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId and messageId required');

            return;
        }
        if (! self::member($cid, $uid)) {
            $this->error($c, $req, 'FORBIDDEN', 'Not a conversation member');

            return;
        }
        if (! DB::table('rt_messages')->where('id', $mid)->where('conversation_id', $cid)->exists()) {
            $this->error($c, $req, 'NOT_FOUND', 'Message not found');

            return;
        }
        if (DB::table('rt_pinned_messages')->where('conversation_id', $cid)->where('message_id', $mid)->exists()) {
            $this->toSocket($c, ['type' => 'msg:pin:ack', 'reqId' => $req, 'data' => ['success' => true, 'alreadyPinned' => true]]);

            return;
        }
        $at = Legacy::now();
        DB::table('rt_pinned_messages')->insert(['conversation_id' => $cid, 'message_id' => $mid, 'pinned_by' => $uid, 'pinned_at' => $at]);
        $this->toSocket($c, ['type' => 'msg:pin:ack', 'reqId' => $req, 'data' => ['success' => true, 'pinnedAt' => Legacy::isoOffset($at)]]);
        foreach ($this->memberIds($cid) as $m) {
            $this->toUser($m, ['type' => 'msg:pinned', 'data' => [
                'conversationId' => $cid, 'messageId' => $mid, 'pinnedBy' => $uid, 'pinnedAt' => Legacy::isoOffset($at),
            ]]);
        }
    }

    private function unpin(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $mid = $d['messageId'] ?? null;
        $req = $d['_reqId'] ?? null;
        if (! self::truthy($cid, $mid)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId and messageId required');

            return;
        }
        if (! self::member($cid, $uid)) {
            $this->error($c, $req, 'FORBIDDEN', 'Not a conversation member');

            return;
        }
        $q = DB::table('rt_pinned_messages')->where('conversation_id', $cid)->where('message_id', $mid);
        if (! $q->exists()) {
            $this->toSocket($c, ['type' => 'msg:unpin:ack', 'reqId' => $req, 'data' => ['success' => true, 'wasNotPinned' => true]]);

            return;
        }
        $q->delete();
        $this->toSocket($c, ['type' => 'msg:unpin:ack', 'reqId' => $req, 'data' => ['success' => true]]);
        foreach ($this->memberIds($cid) as $m) {
            $this->toUser($m, ['type' => 'msg:unpinned', 'data' => ['conversationId' => $cid, 'messageId' => $mid, 'unpinnedBy' => $uid]]);
        }
    }

    private function edit(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $mid = $d['messageId'] ?? null;
        $content = array_key_exists('content', $d) ? $d['content'] : '';
        $req = $d['_reqId'] ?? null;
        if (! self::truthy($cid, $mid, $content)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId, messageId, and content required');

            return;
        }
        if (self::strlenPy($content) > 4000) {
            $this->error($c, $req, 'INVALID_REQUEST', 'Content too long (max 4000 chars)');

            return;
        }
        $msg = DB::table('rt_messages')->where('id', $mid)->where('conversation_id', $cid)->first();
        if (! $msg) {
            $this->error($c, $req, 'NOT_FOUND', 'Message not found');

            return;
        }
        if ($msg->sender_id !== $uid) {
            $this->error($c, $req, 'FORBIDDEN', 'Can only edit your own messages');

            return;
        }
        if ($msg->deleted_at) {
            $this->error($c, $req, 'FORBIDDEN', 'Cannot edit deleted message');

            return;
        }
        DB::table('rt_messages')->where('id', $mid)->update(['content' => $content, 'edited_at' => Legacy::now()]);
        $msg = DB::table('rt_messages')->find($mid);
        $dto = Dto::message($msg, DB::table('users')->find($uid), [Legacy::class, 'isoOffset'], withReply: false);
        $dto['deletedAt'] = null;
        $this->toSocket($c, ['type' => 'msg:edit:ack', 'reqId' => $req, 'data' => [
            'conversationId' => $cid, 'messageId' => $mid, 'editedAt' => Legacy::isoOffset($msg->edited_at),
        ]]);
        foreach ($this->memberIds($cid) as $m) {
            $this->toUser($m, ['type' => 'msg:edit', 'data' => ['message' => $dto]]);
        }
    }

    private function delete(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $mid = $d['messageId'] ?? null;
        $everyone = $d['deleteForEveryone'] ?? false;
        $req = $d['_reqId'] ?? null;
        if (! self::truthy($cid, $mid)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId and messageId required');

            return;
        }
        $msg = DB::table('rt_messages')->where('id', $mid)->where('conversation_id', $cid)->first();
        if (! $msg) {
            $this->error($c, $req, 'NOT_FOUND', 'Message not found');

            return;
        }
        if (! self::truthy($everyone)) {
            $this->toSocket($c, ['type' => 'msg:delete:ack', 'reqId' => $req, 'data' => [
                'conversationId' => $cid, 'messageId' => $mid, 'deleteForEveryone' => false,
            ]]);

            return;
        }
        if ($msg->sender_id !== $uid) {
            $this->error($c, $req, 'FORBIDDEN', 'Can only delete your own messages for everyone');

            return;
        }
        DB::table('rt_messages')->where('id', $mid)->update(['deleted_at' => Legacy::now(), 'content' => 'Tin nhắn đã bị thu hồi']);
        $msg = DB::table('rt_messages')->find($mid);
        $dto = Dto::message($msg, DB::table('users')->find($uid), [Legacy::class, 'isoOffset'], withReply: false);
        $this->toSocket($c, ['type' => 'msg:delete:ack', 'reqId' => $req, 'data' => [
            'conversationId' => $cid, 'messageId' => $mid, 'deletedAt' => Legacy::isoOffset($msg->deleted_at),
        ]]);
        foreach ($this->memberIds($cid) as $m) {
            $this->toUser($m, ['type' => 'msg:delete', 'data' => ['message' => $dto]]);
        }
    }

    private function react(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $mid = $d['messageId'] ?? null;
        $emoji = $d['emoji'] ?? null;
        $req = $d['_reqId'] ?? null;
        if (! self::truthy($cid, $mid, $emoji)) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId, messageId, and emoji required');

            return;
        }
        if (self::strlenPy($emoji) > 10) {
            $this->error($c, $req, 'INVALID_REQUEST', 'Emoji too long (max 10 chars)');

            return;
        }
        if (! DB::table('rt_messages')->where('id', $mid)->where('conversation_id', $cid)->exists()) {
            $this->error($c, $req, 'NOT_FOUND', 'Message not found');

            return;
        }
        if (! self::member($cid, $uid)) {
            $this->error($c, $req, 'FORBIDDEN', 'Not a member');

            return;
        }
        $q = DB::table('rt_message_reactions')->where('message_id', $mid)->where('user_id', $uid)->where('emoji', $emoji);
        $action = $q->exists() ? 'removed' : 'added';
        if ($action === 'removed') {
            $q->delete();
        } else {
            DB::table('rt_message_reactions')->insert(['message_id' => $mid, 'user_id' => $uid, 'emoji' => $emoji, 'created_at' => Legacy::now()]);
        }
        $this->toSocket($c, ['type' => 'msg:react:ack', 'reqId' => $req, 'data' => [
            'conversationId' => $cid, 'messageId' => $mid, 'emoji' => $emoji, 'action' => $action, 'userId' => $uid,
        ]]);
        foreach ($this->memberIds($cid) as $m) {
            $this->toUser($m, ['type' => 'msg:react', 'data' => [
                'conversationId' => $cid, 'messageId' => $mid, 'emoji' => $emoji, 'userId' => $uid,
                'action' => $action, 'createdAt' => Legacy::isoOffsetDt(Legacy::nowDt()),
            ]]);
        }
    }

    private function sync(TcpConnection $c, string $uid, array $d): void
    {
        $cid = $d['conversationId'] ?? null;
        $after = $d['afterMessageId'] ?? null;
        $limit = array_key_exists('limit', $d) ? $d['limit'] : 50;
        $req = $d['reqId'] ?? null;
        if (! $cid) {
            $this->error($c, $req, 'INVALID_REQUEST', 'conversationId required');

            return;
        }
        if (! self::member($cid, $uid)) {
            $this->error($c, $req, 'FORBIDDEN', 'Not a member');

            return;
        }
        if (! is_int($limit) && ! is_float($limit)) {
            throw new \RuntimeException('can only concatenate str (not "int") to str');
        }
        $limit = (int) $limit;
        $q = DB::table('rt_messages')->where('conversation_id', $cid)->whereNull('deleted_at');
        if ($after) {
            $ref = DB::table('rt_messages')->where('id', $after)->value('created_at');
            if ($ref) {
                $q->where('created_at', '>', $ref);
            }
        }
        $rows = $q->orderBy('created_at')->limit(max($limit + 1, 0))->get()->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, max($limit, 0));
        }
        $users = Dto::users(array_map(fn ($m) => $m->sender_id, $rows));
        $this->toSocket($c, ['type' => 'conv:sync:result', 'reqId' => $req, 'data' => [
            'conversationId' => $cid,
            'messages' => array_map(fn ($m) => Dto::message($m, $users[$m->sender_id] ?? null, [Legacy::class, 'isoOffset'], withReply: false), $rows),
            'hasMore' => $hasMore,
        ]]);
    }
}
