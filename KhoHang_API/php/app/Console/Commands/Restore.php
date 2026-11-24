<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Verifies a backup against its manifest and restores it into a target data
 * directory. Refuses to overwrite an existing data.db unless --force.
 */
class Restore extends Command
{
    protected $signature = 'khohang:restore {backup : Backup directory} {target : Data directory to restore into} {--force}';

    protected $description = 'Verify and restore a khohang:backup snapshot';

    public function handle(): int
    {
        $src = rtrim($this->argument('backup'), '/');
        $dst = rtrim($this->argument('target'), '/');
        $manifest = json_decode((string) @file_get_contents("$src/manifest.json"), true);
        if (! is_array($manifest)) {
            $this->error('manifest.json missing or invalid');

            return self::FAILURE;
        }
        foreach ($manifest as $rel => $sha) {
            if (! is_file("$src/$rel") || hash_file('sha256', "$src/$rel") !== $sha) {
                $this->error("Checksum mismatch: $rel");

                return self::FAILURE;
            }
        }
        if (is_file("$dst/data.db") && ! $this->option('force')) {
            $this->error("$dst/data.db exists; pass --force to overwrite");

            return self::FAILURE;
        }
        foreach (array_keys($manifest) as $rel) {
            @mkdir(dirname("$dst/$rel"), 0775, true);
            copy("$src/$rel", "$dst/$rel");
            if (hash_file('sha256', "$dst/$rel") !== $manifest[$rel]) {
                $this->error("Copy verification failed: $rel");

                return self::FAILURE;
            }
        }
        $this->info('Restored '.count($manifest)." files into $dst");

        return self::SUCCESS;
    }
}
