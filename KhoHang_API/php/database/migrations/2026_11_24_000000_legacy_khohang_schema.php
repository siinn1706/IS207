<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the 18 legacy tables exactly as SQLAlchemy's create_all() did
 * (DDL captured into database/schema/legacy.sql), plus the startup fix-ups
 * from app/database.py. Every statement is IF NOT EXISTS, so running it on an
 * existing FastAPI database only adds what is missing and never rewrites data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $sql = file_get_contents(database_path('schema/legacy.sql'));
        $tables = [];
        $indexes = [];
        foreach (explode(";\n", $sql) as $stmt) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt === '') {
                continue;
            }
            str_starts_with($stmt, 'CREATE TABLE') ? $tables[] = rtrim($stmt, ';') : $indexes[] = rtrim($stmt, ';');
        }
        foreach ($tables as $stmt) {
            $pdo->exec($stmt);
        }
        // ensure_stock_transaction_columns()
        $cols = array_column(DB::select('PRAGMA table_info(stock_transactions)'), 'name');
        foreach (['warehouse_code', 'voucher_id', 'actor_user_id'] as $c) {
            if (! in_array($c, $cols, true)) {
                DB::statement("ALTER TABLE stock_transactions ADD COLUMN $c TEXT");
            }
        }
        // Columns older databases received through the one-off scripts in KhoHang_API/*.py
        $add = [
            ['users', 'display_name', 'TEXT'],
            ['rt_conversation_members', 'is_accepted', 'INTEGER DEFAULT 0'],
            ['rt_messages', 'reply_to_id', 'TEXT REFERENCES rt_messages(id)'],
        ];
        foreach ($add as [$table, $col, $type]) {
            if (! in_array($col, array_column(DB::select("PRAGMA table_info($table)"), 'name'), true)) {
                DB::statement("ALTER TABLE $table ADD COLUMN $col $type");
            }
        }
        // Indexes last, so tables from older databases already have every indexed column.
        // Includes ensure_rt_message_unique_constraint() (idx_rt_messages_sender_client).
        // Like database.py, an index that cannot be built (e.g. duplicate rows in an old
        // database) is reported and skipped instead of aborting startup.
        foreach ($indexes as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                fwrite(STDERR, "[WARN] Could not create index: {$e->getMessage()}\n");
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: legacy data tables are never dropped by a rollback.
    }
};
