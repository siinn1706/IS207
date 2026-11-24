<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * HTTP process -> WebSocket process bridge. The FastAPI backend broadcast
 * system events in-process; here the Workerman server listens on a loopback
 * port and relays every line it receives to all connected sockets.
 * Best effort: a stopped WS server must never fail an inventory write.
 */
class Realtime
{
    public static function broadcastSystemEvent(string $type, array $data): void
    {
        $port = (int) config('khohang.ws_internal_port');
        $payload = json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE)."\n";
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.3);
        if (! $fp) {
            Log::debug("[RT bridge] WS server unreachable: $errstr");

            return;
        }
        stream_set_timeout($fp, 0, 300000);
        @fwrite($fp, $payload);
        @fclose($fp);
    }
}
