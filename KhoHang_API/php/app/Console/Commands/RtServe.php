<?php

namespace App\Console\Commands;

use App\Realtime\RtServer;
use Illuminate\Console\Command;
use Workerman\Worker;

/**
 * Starts the realtime chat WebSocket server (Workerman).
 *   php artisan rt:serve            (foreground; works on Windows and Linux)
 *   php artisan rt:serve start -d   (Linux daemon)   / stop / status / restart
 */
class RtServe extends Command
{
    protected $signature = 'rt:serve {action=start : start|stop|restart|status} {--d : Run as daemon (Linux only)}';

    protected $description = 'Run the /ws/rt WebSocket server';

    public function handle(): int
    {
        global $argv;
        $argv = ['artisan', $this->argument('action')];
        if ($this->option('d')) {
            $argv[] = '-d';
        }
        Worker::$pidFile = storage_path('framework/rt-server.pid');
        Worker::$logFile = storage_path('logs/rt-server.log');
        $host = config('khohang.ws_host');
        $port = config('khohang.ws_port');
        $this->info("Realtime WebSocket: ws://$host:$port/ws/rt (internal bridge 127.0.0.1:".config('khohang.ws_internal_port').')');
        RtServer::boot($host, $port, config('khohang.ws_internal_port'));
        Worker::runAll();

        return self::SUCCESS;
    }
}
