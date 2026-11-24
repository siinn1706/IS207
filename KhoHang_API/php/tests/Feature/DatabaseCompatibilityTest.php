<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'company_info', 'warehouses', 'suppliers', 'items', 'stock_transactions', 'stock_in_records',
        'stock_out_records', 'users', 'email_otps', 'chat_messages', 'user_preferences', 'chatbot_config',
        'rt_conversations', 'rt_conversation_members', 'rt_messages', 'rt_message_receipts',
        'rt_message_reactions', 'rt_pinned_messages',
    ];

    public function test_blank_database_gets_the_18_legacy_tables_and_indexes(): void
    {
        $tables = array_column(DB::select("select name from sqlite_master where type='table'"), 'name');
        foreach (self::TABLES as $t) {
            $this->assertContains($t, $tables);
        }
        $indexes = array_column(DB::select("select name from sqlite_master where type='index'"), 'name');
        $this->assertContains('idx_rt_messages_sender_client', $indexes);
        $this->assertContains('ix_items_sku', $indexes);
    }

    public function test_migration_is_idempotent_and_keeps_existing_rows(): void
    {
        DB::table('suppliers')->insert(['name' => 'Legacy', 'outstanding_debt' => 1.5]);
        $migration = require database_path('migrations/2026_11_24_000000_legacy_khohang_schema.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('suppliers')->count());
        $this->assertSame('Legacy', DB::table('suppliers')->value('name'));
    }

    public function test_old_stock_transactions_table_gets_missing_columns(): void
    {
        DB::statement('DROP TABLE stock_transactions');
        DB::statement('CREATE TABLE stock_transactions (id INTEGER NOT NULL PRIMARY KEY, type VARCHAR NOT NULL, item_id INTEGER NOT NULL, quantity INTEGER NOT NULL, note TEXT, timestamp DATETIME)');
        $migration = require database_path('migrations/2026_11_24_000000_legacy_khohang_schema.php');
        $migration->up();
        $cols = array_column(DB::select('PRAGMA table_info(stock_transactions)'), 'name');
        $this->assertContains('warehouse_code', $cols);
        $this->assertContains('voucher_id', $cols);
        $this->assertContains('actor_user_id', $cols);
    }

    public function test_foreign_keys_are_not_enforced_like_sqlalchemy(): void
    {
        $this->assertSame(0, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_unique_client_message_id_per_sender(): void
    {
        $row = ['conversation_id' => 'c', 'sender_id' => 'u', 'client_message_id' => 'x', 'content' => 'a'];
        DB::table('rt_messages')->insert(['id' => '1'] + $row);
        $this->expectException(QueryException::class);
        DB::table('rt_messages')->insert(['id' => '2'] + $row);
    }
}
