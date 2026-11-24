<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * Safe startup step: if migrations are pending and a database already exists,
 * take a verified backup first, then migrate. No-op when nothing is pending.
 */
class Upgrade extends Command
{
    protected $signature = 'khohang:upgrade';

    protected $description = 'Back up (when needed) and apply pending schema migrations';

    public function handle(Migrator $migrator): int
    {
        $db = config('database.connections.sqlite.database');
        @mkdir(dirname($db), 0775, true);
        $existed = is_file($db) && filesize($db) > 0;
        if (! $existed) {
            touch($db);
        }
        if (! $migrator->repositoryExists()) {
            $pending = true;
        } else {
            $ran = $migrator->getRepository()->getRan();
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = (bool) array_diff($files, $ran);
        }
        if (! $pending) {
            $this->info('Schema up to date.');

            return self::SUCCESS;
        }
        if ($existed && $this->call('khohang:backup') !== self::SUCCESS) {
            $this->error('Backup failed - migration aborted, database untouched.');

            return self::FAILURE;
        }

        return $this->call('migrate', ['--force' => true]);
    }
}
