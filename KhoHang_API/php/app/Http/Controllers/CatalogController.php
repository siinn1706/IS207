<?php

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\Http;
use App\Support\Legacy;
use App\Support\Serializers as S;
use App\Support\Validate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Suppliers, items, global search, stock transactions, dashboard (main.py). */
class CatalogController
{
    private const SUPPLIER_FIELDS = ['name', 'tax_id', 'address', 'phone', 'email', 'bank_account', 'bank_name', 'notes'];

    // ---------------- SUPPLIERS ----------------

    public function suppliers(Request $r)
    {
        $q = Validate::query($r, [
            'q' => ['?str', 'default' => null],
            'page' => ['?int', 'default' => null, 'ge' => 1],
            'page_size' => ['?int', 'default' => null, 'ge' => 1, 'le' => 200],
        ]);
        $query = DB::table('suppliers');
        if ($q['q']) {
            $p = Http::likeEscape($q['q']);
            $query->where(fn ($w) => $w->where('name', 'like', $p)->orWhere('phone', 'like', $p)->orWhere('tax_id', 'like', $p));
        }
        $query->orderByDesc('updated_at');

        return Http::json(Http::paginate($query, $q['page'], $q['page_size'], [S::class, 'supplier']));
    }

    public function createSupplier(Request $r)
    {
        Auth::user($r);
        $spec = ['name' => 'str'];
        foreach (array_slice(self::SUPPLIER_FIELDS, 1) as $f) {
            $spec[$f] = ['str', 'default' => ''];
        }
        [$data] = Validate::body($r, $spec);
        if (DB::table('suppliers')->where('name', $data['name'])->exists()) {
            Http::fail(400, 'Nhà cung cấp đã tồn tại');
        }
        $now = Legacy::now();
        $id = DB::table('suppliers')->insertGetId($data + ['outstanding_debt' => 0.0, 'created_at' => $now, 'updated_at' => $now]);

        return Http::json(S::supplier(DB::table('suppliers')->find($id)));
    }

    public function updateSupplier(Request $r, string $supplierId)
    {
        Auth::user($r);
        $spec = array_fill_keys(self::SUPPLIER_FIELDS, ['?str', 'default' => null]);
        [$data, $set] = Validate::body($r, $spec);
        $id = Http::pathInt($supplierId, 'supplier_id');
        $row = DB::table('suppliers')->find($id);
        if (! $row) {
            Http::fail(404, 'Không tìm thấy nhà cung cấp');
        }
        $update = array_intersect_key($data, $set);
        if ($update) {
            $update['updated_at'] = Legacy::now();
            DB::table('suppliers')->where('id', $id)->update($update);
        }

        return Http::json(S::supplier(DB::table('suppliers')->find($id)));
    }

    public function deleteSupplier(Request $r, string $supplierId)
    {
        Auth::passkey($r);
        $id = Http::pathInt($supplierId, 'supplier_id');
        if (! DB::table('suppliers')->where('id', $id)->exists()) {
            Http::fail(404, 'Không tìm thấy nhà cung cấp');
        }
        DB::transaction(function () use ($id) {
            // SQLAlchemy backref behaviour: children lose the reference (items.supplier_id -> NULL)
            DB::table('items')->where('supplier_id', $id)->update(['supplier_id' => null, 'updated_at' => Legacy::now()]);
            DB::table('suppliers')->where('id', $id)->delete();
        });

        return Http::noContent();
    }

    public function supplierTransactions(Request $r, string $supplierId)
    {
        $id = Http::pathInt($supplierId, 'supplier_id');
        $supplier = DB::table('suppliers')->find($id);
        if (! $supplier) {
            Http::fail(404, 'Không tìm thấy nhà cung cấp');
        }
        $records = DB::table('stock_in_records')->where('supplier', $supplier->name)->get()->map([S::class, 'stockIn'])->all();

        return Http::json([
            'stock_in' => $records,
            'stock_out' => [],
            'total_transactions' => count($records),
            'outstanding_debt' => $supplier->outstanding_debt === null ? 0.0 : (float) ($supplier->outstanding_debt ?: 0.0),
        ]);
    }

    // ---------------- ITEMS ----------------

    public function items(Request $r)
    {
        $q = Validate::query($r, [
            'q' => ['?str', 'default' => null],
            'category' => ['?str', 'default' => null],
            'supplier_id' => ['?int', 'default' => null],
            'low_stock' => ['?bool', 'default' => false],
            'page' => ['?int', 'default' => null, 'ge' => 1],
            'page_size' => ['?int', 'default' => null, 'ge' => 1, 'le' => 200],
            'sort' => ['?str', 'default' => null],
        ]);
        $query = DB::table('items');
        if ($q['q']) {
            $p = Http::likeEscape($q['q']);
            $query->where(fn ($w) => $w->where('name', 'like', $p)->orWhere('sku', 'like', $p));
        }
        if ($q['category']) {
            $query->where('category', $q['category']);
        }
        if ($q['supplier_id'] !== null) {
            $query->where('supplier_id', $q['supplier_id']);
        }
        if ($q['low_stock']) {
            $query->where(fn ($w) => $w->whereColumn('quantity', '<=', 'min_stock')->orWhereNull('quantity'));
        }
        $sorts = [
            'name' => ['name', 'asc'], 'name_desc' => ['name', 'desc'],
            'quantity' => ['quantity', 'asc'], 'quantity_desc' => ['quantity', 'desc'],
            'created_at' => ['created_at', 'asc'], 'created_at_desc' => ['created_at', 'desc'],
        ];
        [$col, $dir] = $sorts[$q['sort'] ?? ''] ?? ['updated_at', 'desc'];
        $query->orderBy($col, $dir);

        return Http::json(Http::paginate($query, $q['page'], $q['page_size'], [S::class, 'item']));
    }

    public function createItem(Request $r)
    {
        Auth::user($r);
        [$data] = Validate::body($r, [
            'name' => 'str', 'sku' => 'str', 'unit' => 'str', 'price' => 'float', 'category' => 'str',
            'supplier_id' => ['?int', 'default' => null], 'min_stock' => ['int', 'default' => 10],
        ]);
        if (DB::table('items')->where('sku', $data['sku'])->exists()) {
            Http::fail(400, 'SKU đã tồn tại');
        }
        $now = Legacy::now();
        $id = DB::table('items')->insertGetId($data + [
            'quantity' => 0, 'description' => '', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return Http::json(S::item(DB::table('items')->find($id)));
    }

    public function updateItem(Request $r, string $itemId)
    {
        Auth::user($r);
        [$data, $set] = Validate::body($r, [
            'name' => ['?str', 'default' => null], 'sku' => ['?str', 'default' => null],
            'quantity' => ['?int', 'default' => null], 'unit' => ['?str', 'default' => null],
            'price' => ['?float', 'default' => null], 'category' => ['?str', 'default' => null],
            'supplier_id' => ['?int', 'default' => null],
        ]);
        $id = Http::pathInt($itemId, 'item_id');
        $item = DB::table('items')->find($id);
        if (! $item) {
            Http::fail(404, 'Không tìm thấy hàng hoá');
        }
        $update = array_intersect_key($data, $set);
        if (array_key_exists('sku', $update) && $update['sku'] !== $item->sku
            && DB::table('items')->where('sku', $update['sku'])->exists()) {
            Http::fail(400, 'SKU đã tồn tại');
        }
        $update['updated_at'] = Legacy::now();
        DB::table('items')->where('id', $id)->update($update);

        return Http::json(S::item(DB::table('items')->find($id)));
    }

    public function deleteItem(Request $r, string $itemId)
    {
        Auth::passkey($r);
        $id = Http::pathInt($itemId, 'item_id');
        if (! DB::table('items')->where('id', $id)->exists()) {
            Http::fail(404, 'Không tìm thấy hàng hoá');
        }
        // FastAPI tried to NULL stock_transactions.item_id (NOT NULL) and crashed with 500,
        // leaving the item in place. Same outcome, explicit status and message.
        if (DB::table('stock_transactions')->where('item_id', $id)->exists()) {
            Http::fail(409, 'Không thể xóa hàng hoá đã có giao dịch kho');
        }
        DB::table('items')->where('id', $id)->delete();

        return Http::noContent();
    }

    public function alerts()
    {
        $alerts = [];
        foreach (DB::table('items')->get() as $item) {
            $current = (int) ($item->quantity ?: 0);
            $min = (int) ($item->min_stock ?: 10);
            $max = max($min * 10, 100);
            if ($current <= 0 || $current < $min * 0.2) {
                $status = 'critical';
            } elseif ($current < $min * 0.5) {
                $status = 'warning';
            } elseif ($current < $min) {
                $status = 'low';
            } elseif ($current > $max) {
                $status = 'overstock';
            } else {
                continue;
            }
            $alerts[] = [
                'id' => (string) $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'currentStock' => $current,
                'minStock' => $min,
                'maxStock' => $max,
                'category' => $item->category,
                'lastUpdate' => $item->updated_at ? Legacy::iso($item->updated_at) : Legacy::iso($item->created_at),
                'status' => $status,
            ];
        }

        return Http::json($alerts);
    }

    public function topItems()
    {
        $rows = DB::table('items')->orderByDesc('quantity')->limit(10)->get();

        return Http::json($rows->map(fn ($i) => ['name' => $i->name, 'value' => (int) ($i->quantity ?: 0)])->all());
    }

    public function itemsMonthlyTrend()
    {
        $months = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'];
        $since = Legacy::toDb(Legacy::nowDt()->modify('-365 days'));
        $data = array_fill_keys($months, 0);
        $txs = DB::table('stock_transactions')->where('timestamp', '>=', $since)->where('type', 'in')->get();
        foreach ($txs as $tx) {
            $dt = Legacy::parse($tx->timestamp);
            if ($dt) {
                $data[$months[(int) $dt->format('n') - 1]] += (int) $tx->quantity;
            }
        }

        return Http::json(array_map(fn ($m) => ['month' => $m, 'value' => $data[$m]], $months));
    }

    public function categoryDistribution()
    {
        $colors = ['#00BCD4', '#4CAF50', '#FF9800', '#F44336', '#9C27B0', '#2196F3', '#FFC107', '#795548', '#607D8B', '#E91E63'];
        $data = [];
        foreach (DB::table('items')->get() as $item) {
            $cat = $item->category ?: 'Khác';
            $data[$cat] = ($data[$cat] ?? 0) + (int) ($item->quantity ?: 0);
        }
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = [(string) $k, $v];
        }
        usort($pairs, fn ($a, $b) => $b[1] <=> $a[1]);
        $out = [];
        foreach ($pairs as $idx => [$name, $value]) {
            $out[] = ['name' => $name, 'value' => $value, 'color' => $colors[$idx % count($colors)]];
        }

        return Http::json($out);
    }

    // ---------------- SEARCH ----------------

    public function globalSearch(Request $r)
    {
        $q = Validate::query($r, [
            'q' => ['str', 'min' => 1],
            'limit' => ['int', 'default' => 5, 'ge' => 1, 'le' => 50],
        ]);
        $p = Http::likeEscape($q['q']);
        $limit = $q['limit'];

        $items = DB::table('items')->where(fn ($w) => $w->where('name', 'like', $p)->orWhere('sku', 'like', $p))
            ->orderByDesc('updated_at')->limit($limit)->get();
        $suppliers = DB::table('suppliers')->where(fn ($w) => $w->where('name', 'like', $p)->orWhere('phone', 'like', $p)->orWhere('tax_id', 'like', $p))
            ->orderByDesc('updated_at')->limit($limit)->get();
        $in = DB::table('stock_in_records')->where(fn ($w) => $w->where('id', 'like', $p)->orWhere('supplier', 'like', $p)->orWhere('note', 'like', $p))
            ->orderByDesc('created_at')->limit($limit)->get();
        $out = DB::table('stock_out_records')->where(fn ($w) => $w->where('id', 'like', $p)->orWhere('recipient', 'like', $p)->orWhere('note', 'like', $p))
            ->orderByDesc('created_at')->limit($limit)->get();

        return Http::json([
            'items' => $items->map(fn ($i) => ['id' => (int) $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit, 'quantity' => (int) ($i->quantity ?: 0)])->all(),
            'suppliers' => $suppliers->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'phone' => $s->phone ?: '', 'tax_id' => $s->tax_id ?: ''])->all(),
            'stock_in' => $in->map(fn ($x) => ['id' => $x->id, 'warehouse_code' => $x->warehouse_code, 'supplier' => $x->supplier, 'date' => $x->date])->all(),
            'stock_out' => $out->map(fn ($x) => ['id' => $x->id, 'warehouse_code' => $x->warehouse_code, 'recipient' => $x->recipient, 'date' => $x->date])->all(),
        ]);
    }

    // ---------------- STOCK TRANSACTIONS ----------------

    public function transactions()
    {
        return Http::json(DB::table('stock_transactions')->orderByDesc('timestamp')->get()->map([S::class, 'transaction'])->all());
    }

    public function createTransaction(Request $r)
    {
        $user = Auth::user($r);
        [$tx] = Validate::body($r, [
            'type' => 'str', 'item_id' => 'int', 'quantity' => 'int',
            'note' => ['?str', 'default' => null], 'warehouse_code' => ['?str', 'default' => null],
            'voucher_id' => ['?str', 'default' => null], 'actor_user_id' => ['?str', 'default' => null],
        ]);

        return DB::transaction(function () use ($tx, $user) {
            $item = DB::table('items')->find($tx['item_id']);
            if (! $item) {
                Http::fail(404, 'Không tìm thấy hàng hoá');
            }
            $current = (int) ($item->quantity ?: 0);
            if ($tx['type'] === 'in') {
                $new = $current + $tx['quantity'];
            } elseif ($tx['type'] === 'out') {
                if ($current < $tx['quantity']) {
                    Http::fail(400, 'Không đủ tồn kho để xuất');
                }
                $new = $current - $tx['quantity'];
            } else {
                Http::fail(400, "Loại giao dịch phải là 'in' hoặc 'out'");
            }
            $now = Legacy::now();
            DB::table('items')->where('id', $item->id)->update(['quantity' => $new, 'updated_at' => $now]);
            $id = DB::table('stock_transactions')->insertGetId([
                'type' => $tx['type'], 'item_id' => $tx['item_id'], 'quantity' => $tx['quantity'], 'note' => $tx['note'],
                'timestamp' => $now, 'warehouse_code' => $tx['warehouse_code'], 'voucher_id' => $tx['voucher_id'],
                'actor_user_id' => $user['id'],
            ]);

            return Http::json(S::transaction(DB::table('stock_transactions')->find($id)));
        });
    }

    // ---------------- DASHBOARD ----------------

    public function dashboardStats()
    {
        $items = DB::table('items')->get();
        $low = 0;
        $value = 0.0;
        foreach ($items as $i) {
            if ((int) ($i->quantity ?: 0) < (int) ($i->min_stock ?: 10)) {
                $low++;
            }
            $value += (int) ($i->quantity ?: 0) * (float) $i->price;
        }
        $recent = DB::table('stock_transactions')->orderByDesc('timestamp')->limit(10)->get();

        return Http::json([
            'total_items' => $items->count(),
            'low_stock_count' => $low,
            'total_value' => (float) $value,
            'recent_transactions' => $recent->map([S::class, 'transaction'])->all(),
        ]);
    }
}
