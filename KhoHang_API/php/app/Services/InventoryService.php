<?php

namespace App\Services;

use App\Support\Http;
use App\Support\Legacy;
use App\Support\Realtime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Port of inventory_service.py. Behaviour is kept 1:1 except fifoCost(), which now
 * accounts for units already issued (the FastAPI version did not).
 */
class InventoryService
{
    // ---------- voucher ids ----------

    public static function nextId(string $table, string $kind, string $warehouseCode, string $date): string
    {
        $dt = Legacy::fromIso($date) ?? new \DateTimeImmutable('now');
        $prefix = "{$warehouseCode}_{$kind}_".$dt->format('my').'_';
        $max = DB::table($table)->where('id', 'like', $prefix.'%')->orderByDesc('id')->value('id');
        $next = 1;
        if ($max !== null) {
            $rest = str_replace($prefix, '', $max);
            if (preg_match('/^\s*[+-]?\d+\s*$/', $rest)) {
                $next = (int) trim($rest) + 1;
            }
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Retry wrapper of main.create_stock_in / create_stock_out (IntegrityError/OperationalError). */
    public static function withIdRetry(callable $attempt, string $label): mixed
    {
        $max = 5;
        for ($i = 0; $i < $max; $i++) {
            try {
                return $attempt();
            } catch (QueryException $e) {
                if ($i === $max - 1) {
                    Http::fail(409, "Không thể tạo $label sau $max lần thử. Vui lòng thử lại.");
                }
                usleep(($i + 1) * 100000);
            }
        }

        return null;
    }

    // ---------- helpers ----------

    private static function sortItems(array $items): array
    {
        $key = fn ($x) => (string) (($x['item_id'] ?? null) ?: (($x['item_code'] ?? null) ?: ''));
        usort($items, fn ($a, $b) => strcmp($key($a), $key($b)));

        return $items;
    }

    private static function pyRepr(mixed $v): string
    {
        return $v === null ? 'None' : (string) $v;
    }

    public static function resolveItem(mixed $itemId, mixed $itemCode): object
    {
        $candidate = null;
        if ($itemId !== null && $itemId !== '') {
            if (is_int($itemId) || (is_string($itemId) && preg_match('/^\s*[+-]?\d+\s*$/', $itemId))) {
                $candidate = DB::table('items')->where('id', (int) trim((string) $itemId))->first();
            }
        }
        if (! $candidate && $itemCode !== null && $itemCode !== '') {
            $candidate = DB::table('items')->where('sku', $itemCode)->first();
        }
        if (! $candidate) {
            Http::fail(400, 'Không tìm thấy hàng hoá (item_id='.self::pyRepr($itemId).', item_code='.self::pyRepr($itemCode).')');
        }

        return $candidate;
    }

    private static function payload(object $item, int $qty, string $unit, ?float $price = null, ?float $cost = null): array
    {
        return [
            'item_id' => (string) $item->id,
            'item_code' => $item->sku,
            'item_name' => $item->name,
            'quantity' => $qty,
            'unit' => $unit,
            'price' => $price ?? 0,
            'cost' => $cost ?? 0,
        ];
    }

    /** @return array{0: float, 1: ?string} */
    public static function validateSellPrice(object $item, ?float $requested): array
    {
        $dbPrice = (float) ($item->price ?: 0);
        $name = (string) $item->name;
        if ($requested === null || $requested <= 0) {
            $shown = $requested === null ? 'None' : Legacy::pyFloat($requested);

            return [$dbPrice, "[SECURITY] Price override: Client provided $shown, using database price ".Legacy::f2($dbPrice)." for '$name'"];
        }
        if ($dbPrice > 0) {
            $diff = abs($requested - $dbPrice) / $dbPrice * 100;
            if ($diff <= 5) {
                return [$requested, null];
            }
        }
        if ($requested < $dbPrice * 0.95) {
            return [$dbPrice,
                "[SECURITY ALERT] Price reduction flagged for '$name': "
                .'Client requested '.Legacy::f2($requested).', database price '.Legacy::f2($dbPrice).' '
                .'(reduction: '.Legacy::f1(($dbPrice - $requested) / $dbPrice * 100).'%). '
                .'Using database price '.Legacy::f2($dbPrice).' to prevent fraud.'];
        }
        if ($requested > $dbPrice) {
            return [$requested,
                "[AUDIT] Price increase for '$name': "
                .'Client requested '.Legacy::f2($requested).', database price '.Legacy::f2($dbPrice).' '
                .'(increase: '.Legacy::f1(($requested - $dbPrice) / $dbPrice * 100).'%). '
                .'Using requested price.'];
        }

        return [$requested, null];
    }

    /**
     * FIFO cost of issuing $quantity units of an item from a warehouse.
     *
     * Layers = lines of completed (non-cancelled) stock-in vouchers in creation order.
     * Units already issued by completed stock-out vouchers (plus $alreadyTaken units
     * earlier in the voucher being created) are consumed from the oldest layers first;
     * the requested quantity is then priced from the remaining layers. Any shortfall
     * (stock that never came through a voucher) is priced at the item's list price.
     *
     * The FastAPI version restarted from the oldest layer on every issue, so every
     * stock-out was costed at the first purchase price (see contract-matrix.md).
     */
    public static function fifoCost(int $itemId, string $warehouseCode, int $quantity, int $alreadyTaken = 0): float
    {
        $layers = [];
        $records = DB::table('stock_in_records')
            ->where('warehouse_code', $warehouseCode)->where('status', 'completed')
            ->orderBy('created_at')->orderBy('id')->get(['items']);
        foreach ($records as $record) {
            foreach (Legacy::json($record->items) ?: [] as $it) {
                if ((string) ($it['item_id'] ?? '') === (string) $itemId && (int) ($it['quantity'] ?? 0) > 0) {
                    $layers[] = [(int) $it['quantity'], (float) ($it['price'] ?? 0)];
                }
            }
        }

        $consumed = $alreadyTaken;
        $outs = DB::table('stock_out_records')
            ->where('warehouse_code', $warehouseCode)->where('status', 'completed')->get(['items']);
        foreach ($outs as $record) {
            foreach (Legacy::json($record->items) ?: [] as $it) {
                if ((string) ($it['item_id'] ?? '') === (string) $itemId) {
                    $consumed += (int) ($it['quantity'] ?? 0);
                }
            }
        }

        $total = 0.0;
        $remaining = $quantity;
        foreach ($layers as [$qty, $price]) {
            if ($consumed >= $qty) {
                $consumed -= $qty;

                continue;
            }
            $available = $qty - $consumed;
            $consumed = 0;
            $take = min($available, $remaining);
            $total += $take * $price;
            $remaining -= $take;
            if ($remaining <= 0) {
                break;
            }
        }
        if ($remaining > 0) {
            $item = DB::table('items')->find($itemId);
            if ($item) {
                $total += $remaining * (float) ($item->price ?: 0);
            }
        }

        return (float) $total;
    }

    // ---------- create ----------

    public static function createStockIn(array $data, string $recordId, array $user): object
    {
        $now = Legacy::now();
        $record = DB::transaction(function () use ($data, $recordId, $user, $now) {
            $payload = [];
            foreach (self::sortItems($data['items']) as $it) {
                $item = self::resolveItem($it['item_id'], $it['item_code']);
                DB::table('items')->where('id', $item->id)->update([
                    'quantity' => (int) ($item->quantity ?: 0) + $it['quantity'], 'updated_at' => $now,
                ]);
                $payload[] = self::payload($item, $it['quantity'], $it['unit'], $it['price']);
                DB::table('stock_transactions')->insert([
                    'type' => 'in', 'item_id' => $item->id, 'quantity' => $it['quantity'], 'note' => $data['note'] ?: '',
                    'timestamp' => $now, 'warehouse_code' => $data['warehouse_code'], 'voucher_id' => $recordId,
                    'actor_user_id' => $user['id'],
                ]);
            }
            $totalQty = array_sum(array_column($payload, 'quantity'));
            $totalAmt = 0.0;
            foreach ($payload as $p) {
                $totalAmt += ($p['price'] ?: 0) * $p['quantity'];
            }
            DB::table('stock_in_records')->insert([
                'id' => $recordId,
                'warehouse_code' => $data['warehouse_code'],
                'supplier' => $data['supplier'],
                'date' => $data['date'],
                'note' => $data['note'] ?: '',
                'tax_rate' => $data['tax_rate'] ?: 0.0,
                'payment_method' => $data['payment_method'] ?: 'tiền_mặt',
                'payment_bank_account' => $data['payment_bank_account'] ?: '',
                'payment_bank_name' => $data['payment_bank_name'] ?: '',
                'items' => Legacy::toJson($payload),
                'total_quantity' => $totalQty,
                'total_amount' => $totalAmt,
                'status' => 'completed',
                'created_at' => $now,
            ]);

            return DB::table('stock_in_records')->find($recordId);
        });
        Realtime::broadcastSystemEvent('inventory:updated', ['type' => 'stock_in', 'record_id' => $recordId]);

        return $record;
    }

    public static function createStockOut(array $data, string $recordId, array $user): object
    {
        $now = Legacy::now();
        $record = DB::transaction(function () use ($data, $recordId, $user, $now) {
            $payload = [];
            $audit = [];
            $taken = []; // units of each item already issued by earlier lines of this voucher
            foreach (self::sortItems($data['items']) as $it) {
                $item = self::resolveItem($it['item_id'], $it['item_code']);
                $available = (int) ($item->quantity ?: 0);
                if (! ($available >= $it['quantity'])) {
                    Http::fail(400, "Không đủ tồn kho cho hàng hoá {$item->name} (có $available, cần {$it['quantity']})");
                }
                DB::table('items')->where('id', $item->id)->update([
                    'quantity' => $available - $it['quantity'], 'updated_at' => $now,
                ]);
                [$price, $note] = self::validateSellPrice($item, $it['sell_price']);
                if ($note) {
                    $audit[] = $note;
                }
                $fifo = self::fifoCost((int) $item->id, $data['warehouse_code'], $it['quantity'], $taken[$item->id] ?? 0);
                $taken[$item->id] = ($taken[$item->id] ?? 0) + $it['quantity'];
                $avg = $it['quantity'] > 0 ? $fifo / $it['quantity'] : 0;
                $payload[] = self::payload($item, $it['quantity'], $it['unit'], $price, $avg);
                DB::table('stock_transactions')->insert([
                    'type' => 'out', 'item_id' => $item->id, 'quantity' => $it['quantity'], 'note' => $data['note'] ?: '',
                    'timestamp' => $now, 'warehouse_code' => $data['warehouse_code'], 'voucher_id' => $recordId,
                    'actor_user_id' => $user['id'],
                ]);
            }
            $totalQty = array_sum(array_column($payload, 'quantity'));
            $totalAmt = null;
            if ($data['purpose'] === 'Bán hàng') {
                $totalAmt = 0.0;
                foreach ($payload as $p) {
                    $totalAmt += ($p['price'] ?: 0) * $p['quantity'];
                }
            }
            $note = trim((string) ($data['note'] ?: ''));
            if ($audit) {
                $note .= "\n".implode("\n", $audit);
            }
            DB::table('stock_out_records')->insert([
                'id' => $recordId,
                'warehouse_code' => $data['warehouse_code'],
                'recipient' => $data['recipient'],
                'purpose' => $data['purpose'],
                'date' => $data['date'],
                'note' => $note,
                'tax_rate' => $data['tax_rate'] ?: 0.0,
                'payment_method' => $data['payment_method'] ?: 'tiền_mặt',
                'payment_bank_account' => $data['payment_bank_account'] ?: '',
                'payment_bank_name' => $data['payment_bank_name'] ?: '',
                'items' => Legacy::toJson($payload),
                'total_quantity' => $totalQty,
                'total_amount' => $totalAmt,
                'status' => 'completed',
                'created_at' => $now,
            ]);

            return DB::table('stock_out_records')->find($recordId);
        });
        Realtime::broadcastSystemEvent('inventory:updated', ['type' => 'stock_out', 'record_id' => $recordId]);

        return $record;
    }

    // ---------- cancel ----------

    private static function cancelNote(object $record, ?string $actor, \DateTimeImmutable $now): string
    {
        $entry = '[CANCELLED by '.($actor ?: 'system').' at '.Legacy::isoOffsetDt($now).']';

        return trim(trim((string) ($record->note ?: ''))."\n".$entry);
    }

    public static function cancelStockIn(object $record, ?string $actor): object
    {
        if ((string) ($record->status ?? '') === 'cancelled') {
            return $record;
        }
        $nowDt = Legacy::nowDt();
        $now = Legacy::toDb($nowDt);
        $count = 0;
        $result = DB::transaction(function () use ($record, $actor, $nowDt, $now, &$count) {
            $removals = [];
            foreach (self::sortItems(Legacy::json($record->items) ?: []) as $it) {
                $qty = (int) ($it['quantity'] ?? 0);
                $name = $it['item_name'] ?? 'Unknown';
                $item = self::resolveItem($it['item_id'] ?? null, $it['item_code'] ?? null);
                $available = (int) ($item->quantity ?: 0);
                if (! ($available >= $qty)) {
                    Http::fail(400, "Không đủ tồn kho để hủy phiếu nhập {$record->id} cho hàng '$name': "
                        ."hiện có $available, cần trừ $qty. Tồn kho có thể đã bị thay đổi bởi phiếu khác.");
                }
                $removals[] = [$item->id, $qty, (float) ($it['price'] ?? 0)];
            }
            foreach ($removals as [$itemId, $qty, $price]) {
                $current = (int) (DB::table('items')->where('id', $itemId)->value('quantity') ?: 0);
                DB::table('items')->where('id', $itemId)->update(['quantity' => $current - $qty, 'updated_at' => $now]);
                DB::table('stock_transactions')->insert([
                    'type' => 'out', 'item_id' => $itemId, 'quantity' => $qty,
                    'note' => "Cancel stock-in {$record->id} | Original cost: ".Legacy::f2($price)."/unit × $qty = ".Legacy::f2($price * $qty),
                    'timestamp' => $now, 'warehouse_code' => $record->warehouse_code, 'voucher_id' => $record->id,
                    'actor_user_id' => $actor,
                ]);
            }
            $count = count($removals);
            DB::table('stock_in_records')->where('id', $record->id)->update([
                'status' => 'cancelled', 'note' => self::cancelNote($record, $actor, $nowDt),
            ]);

            return DB::table('stock_in_records')->find($record->id);
        });
        Realtime::broadcastSystemEvent('inventory:updated', [
            'type' => 'cancel_stock_in', 'record_id' => $record->id, 'total_items_removed' => $count,
        ]);

        return $result;
    }

    public static function cancelStockOut(object $record, ?string $actor): object
    {
        if ((string) ($record->status ?? '') === 'cancelled') {
            return $record;
        }
        $nowDt = Legacy::nowDt();
        $now = Legacy::toDb($nowDt);
        $count = 0;
        $result = DB::transaction(function () use ($record, $actor, $nowDt, $now, &$count) {
            $restock = [];
            foreach (self::sortItems(Legacy::json($record->items) ?: []) as $it) {
                $qty = (int) ($it['quantity'] ?? 0);
                $cost = (float) ($it['cost'] ?? 0);
                $name = $it['item_name'] ?? 'Unknown';
                $item = self::resolveItem($it['item_id'] ?? null, $it['item_code'] ?? null);
                $current = (int) ($item->quantity ?: 0);
                $proposed = $current + $qty;
                $max = 10000;
                if ($proposed > $max) {
                    Http::fail(400, "Dung lượng kho vượt giới hạn cho '$name': hiện có $current, "
                        ."hoàn lại $qty → tổng $proposed (tối đa $max). Vui lòng liên hệ quản lý kho.");
                }
                $restock[] = [$item->id, $qty, $cost];
            }
            foreach ($restock as [$itemId, $qty, $cost]) {
                $current = (int) (DB::table('items')->where('id', $itemId)->value('quantity') ?: 0);
                DB::table('items')->where('id', $itemId)->update(['quantity' => $current + $qty, 'updated_at' => $now]);
                DB::table('stock_transactions')->insert([
                    'type' => 'in', 'item_id' => $itemId, 'quantity' => $qty,
                    'note' => "Restock from cancelled stock-out {$record->id} | Original COGS: ".Legacy::f2($cost)."/unit × $qty = ".Legacy::f2($cost * $qty),
                    'timestamp' => $now, 'warehouse_code' => $record->warehouse_code, 'voucher_id' => $record->id,
                    'actor_user_id' => $actor,
                ]);
            }
            $count = count($restock);
            DB::table('stock_out_records')->where('id', $record->id)->update([
                'status' => 'cancelled', 'note' => self::cancelNote($record, $actor, $nowDt),
            ]);

            return DB::table('stock_out_records')->find($record->id);
        });
        Realtime::broadcastSystemEvent('inventory:updated', [
            'type' => 'cancel_stock_out', 'record_id' => $record->id, 'total_items_restored' => $count,
        ]);

        return $result;
    }
}
