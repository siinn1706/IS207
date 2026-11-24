<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Copies data.db (consistent snapshot via SQLite VACUUM INTO) and the uploads
 * folder into data/backups/<timestamp>/ with a SHA-256 manifest.
 * Run before every migration / cutover.
 */
class Backup extends Command
{
    protected $signature = 'khohang:backup {--dest= : Destination directory (default data/backups/<UTC timestamp>)}';

    protected $description = 'Back up the SQLite database and uploads with checksums';

    public function handle(): int
    {
        $dataDir = rtrim(config('khohang.data_dir'), '/');
        $db = config('database.connections.sqlite.database');
        $dest = $this->option('dest') ?: $dataDir.'/backups/'.gmdate('Ymd_His');
        if (! is_file($db)) {
            $this->warn("No database at $db yet - nothing to back up.");

            return self::SUCCESS;
        }
        if (! @mkdir($dest, 0775, true) && ! is_dir($dest)) {
            $this->error("Cannot create $dest");

            return self::FAILURE;
        }
        $pdo = new \PDO('sqlite:'.$db);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $target = $dest.'/data.db';
        $pdo->exec('VACUUM INTO '.$pdo->quote($target));
        $check = (new \PDO('sqlite:'.$target))->query('PRAGMA integrity_check')->fetchColumn();
        if ($check !== 'ok') {
            $this->error("Integrity check failed on backup: $check");

            return self::FAILURE;
        }

        $manifest = ['data.db' => hash_file('sha256', $target)];
        $uploads = config('khohang.uploads_dir');
        if (is_dir($uploads)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = 'uploads/'.ltrim(substr($file->getPathname(), strlen($uploads)), '/\\');
                @mkdir(dirname("$dest/$rel"), 0775, true);
                copy($file->getPathname(), "$dest/$rel");
                $manifest[$rel] = hash_file('sha256', "$dest/$rel");
            }
        }
        file_put_contents("$dest/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Backup written to '.$dest.' ('.count($manifest).' files)');

        return self::SUCCESS;
    }
}
