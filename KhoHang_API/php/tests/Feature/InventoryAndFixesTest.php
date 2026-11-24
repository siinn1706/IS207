<?php

namespace Tests\Feature;

use App\Support\Jwt;
use App\Support\Legacy;
use App\Support\Passwords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAndFixesTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $now = Legacy::now();
        DB::table('users')->insert([
            ['id' => 'u1', 'username' => 'admin', 'email' => 'admin@example.com', 'password_hash' => Passwords::hash('admin123'),
                'passkey_hash' => Passwords::hash('246810'), 'role' => 'admin', 'is_verified' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'u2', 'username' => 'other', 'email' => 'other@example.com', 'password_hash' => Passwords::hash('secret1'),
                'passkey_hash' => null, 'role' => 'staff', 'is_verified' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('items')->insert(['id' => 1, 'name' => 'Giấy', 'sku' => 'A4', 'unit' => 'ram', 'price' => 999.0,
            'category' => 'VPP', 'min_stock' => 5, 'quantity' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->h = ['Authorization' => 'Bearer '.Jwt::encode(['sub' => 'u1', 'username' => 'admin'])];
    }

    private function stockIn(int $qty, float $price): string
    {
        return $this->postJson('/stock/in', ['warehouse_code' => 'K1', 'supplier' => 'NCC', 'date' => '2026-03-01',
            'items' => [['item_id' => '1', 'item_code' => 'A4', 'item_name' => 'Giấy', 'quantity' => $qty, 'unit' => 'ram', 'price' => $price]]],
            $this->h)->assertCreated()->json('id');
    }

    private function stockOut(array $qtys): array
    {
        $id = $this->postJson('/stock/out', ['warehouse_code' => 'K1', 'recipient' => 'KH', 'purpose' => 'Nội bộ', 'date' => '2026-03-02',
            'items' => array_map(fn ($q) => ['item_id' => '1', 'item_code' => 'A4', 'item_name' => 'Giấy', 'quantity' => $q, 'unit' => 'ram'], $qtys)],
            $this->h)->assertCreated()->json('id');

        return array_column(Legacy::json(DB::table('stock_out_records')->find($id)->items), 'cost');
    }

    public function test_fifo_consumes_earlier_layers(): void
    {
        $this->stockIn(10, 100);
        $this->stockIn(10, 150);
        $this->assertEquals([100.0], $this->stockOut([5]));          // 5 of layer 1
        $this->assertEquals([125.0], $this->stockOut([10]));         // 5@100 + 5@150
        $this->assertEquals([150.0], $this->stockOut([5]));          // rest of layer 2
    }

    public function test_fifo_counts_earlier_lines_of_same_voucher_and_ignores_cancelled(): void
    {
        $this->stockIn(4, 100);
        $this->stockIn(10, 200);
        $this->assertEquals([100.0, 200.0], $this->stockOut([4, 3]));
        $id = DB::table('stock_out_records')->value('id');
        $this->deleteJson("/stock/out/$id", [], $this->h + ['X-Passkey' => '246810'])->assertOk();
        // the cancelled voucher no longer consumes layer 1
        $this->assertEquals([100.0], $this->stockOut([4]));
    }

    public function test_fifo_falls_back_to_list_price_beyond_vouchers(): void
    {
        $this->stockIn(2, 100);
        DB::table('items')->where('id', 1)->update(['quantity' => 10]); // stock added outside vouchers
        $this->assertEquals([(2 * 100 + 2 * 999) / 4], $this->stockOut([4]));
    }

    public function test_items_include_quantity(): void
    {
        $this->stockIn(7, 100);
        $this->getJson('/items')->assertOk()->assertJsonPath('0.quantity', 7);
    }

    public function test_bot_chat_is_isolated_per_user(): void
    {
        $this->postJson('/chat/messages', ['id' => 'm1', 'conversation_id' => 'bot_u1', 'sender' => 'user', 'text' => 'hi'], $this->h)->assertCreated();
        $other = ['Authorization' => 'Bearer '.Jwt::encode(['sub' => 'u2'])];
        $this->getJson('/chat/messages/bot_u1', $other)->assertForbidden();
        $this->deleteJson('/chat/messages/bot_u1', [], $other)->assertForbidden();
        $this->putJson('/chat/messages/m1/reactions', ['reactions' => ['x']], $other)->assertForbidden();
        $this->getJson('/chat/messages/bot_u1', $this->h)->assertOk()->assertJsonCount(1);
    }

    public function test_inventory_ignores_cancelled_vouchers(): void
    {
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'K', 'code' => 'K1', 'managers' => '[]', 'is_active' => 1]);
        $keep = $this->stockIn(3, 100);
        $drop = $this->stockIn(5, 100);
        $this->deleteJson("/stock/in/$drop", [], $this->h + ['X-Passkey' => '246810'])->assertOk();
        $this->getJson('/warehouses/1/inventory')->assertOk()->assertJsonPath('items.0.total_in', 3);
        $this->assertNotSame($keep, $drop);
    }

    public function test_change_password(): void
    {
        $body = ['email' => 'admin@example.com', 'old_password' => 'admin123', 'new_password' => 'newpass1'];
        $this->postJson('/auth/change-password', $body)->assertUnauthorized();
        $this->postJson('/auth/change-password', ['old_password' => 'bad'] + $body, $this->h)->assertUnauthorized();
        $this->postJson('/auth/change-password', ['email' => 'other@example.com'] + $body, $this->h)->assertStatus(400);
        $this->postJson('/auth/change-password', $body, $this->h)->assertOk();
        $this->postJson('/auth/login', ['username_or_email' => 'admin', 'password' => 'newpass1'])->assertOk();
    }

    public function test_logout_is_a_no_op(): void
    {
        $this->postJson('/auth/logout')->assertOk()->assertExactJson(['message' => 'Logged out']);
    }

    public function test_register_can_be_retried_after_mail_failure(): void
    {
        // SMTP is not configured in tests: sending fails -> 500, but nothing is left behind
        $body = ['username' => 'newbie', 'email' => 'new@example.com', 'display_name' => 'N', 'password' => 'secret1'];
        $this->postJson('/auth/register/request-otp', $body)->assertStatus(500)->assertExactJson(['detail' => 'Failed to send OTP email']);
        $this->assertFalse(DB::table('users')->where('email', 'new@example.com')->exists());
        $this->assertSame(0, DB::table('email_otps')->count());
        $this->postJson('/auth/register/request-otp', $body)->assertStatus(500);
        // an unverified leftover from the old backend no longer blocks the email
        DB::table('users')->insert(['id' => 'old', 'username' => 'stale', 'email' => 'new@example.com', 'password_hash' => 'x', 'is_verified' => 0]);
        $this->postJson('/auth/register/request-otp', $body)->assertStatus(500);
        $pending = DB::table('users')->find('old');
        $this->assertSame('newbie', $pending->username);   // the retry's details replace the stale attempt
        $this->assertSame(0, (int) $pending->is_verified);
        $this->assertSame(1, DB::table('users')->where('email', 'new@example.com')->count());
    }
}
