<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use App\Support\Auth;
use App\Support\Http;
use App\Support\Legacy;
use App\Support\Serializers as S;
use App\Support\Validate;
use App\Support\ValidationFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Stock in/out vouchers, warehouses, company info and reports (main.py). */
class StockController
{
    private const COMMON = [
        'date' => 'str',
        'note' => ['str', 'default' => ''],
        'tax_rate' => ['float', 'default' => 0.0],
        'payment_method' => ['str', 'default' => 'tiền_mặt'],
        'payment_bank_account' => ['str', 'default' => ''],
        'payment_bank_name' => ['str', 'default' => ''],
    ];

    // ---------------- STOCK IN ----------------

    public function stockIn(Request $r)
    {
        $q = Validate::query($r, [
            'q' => ['?str', 'default' => null], 'warehouse_code' => ['?str', 'default' => null],
            'supplier' => ['?str', 'default' => null], 'date_from' => ['?str', 'default' => null],
            'date_to' => ['?str', 'default' => null], 'include_cancelled' => ['bool', 'default' => false],
            'page' => ['?int', 'default' => null, 'ge' => 1], 'page_size' => ['?int', 'default' => null, 'ge' => 1, 'le' => 200],
        ]);
        $query = DB::table('stock_in_records');
        if (! $q['include_cancelled']) {
            $query->where('status', '!=', 'cancelled');
        }
        if ($q['q']) {
            $p = Http::likeEscape($q['q']);
            $query->where(fn ($w) => $w->where('id', 'like', $p)->orWhere('note', 'like', $p)->orWhere('supplier', 'like', $p));
        }
        foreach (['warehouse_code', 'supplier'] as $f) {
            if ($q[$f]) {
                $query->where($f, $q[$f]);
            }
        }
        if ($q['date_from']) {
            $query->where('date', '>=', $q['date_from']);
        }
        if ($q['date_to']) {
            $query->where('date', '<=', $q['date_to']);
        }
        $query->orderByDesc('created_at');

        return Http::json(Http::paginate($query, $q['page'], $q['page_size'], [S::class, 'stockIn']));
    }

    public function stockInOne(string $recordId)
    {
        $rec = DB::table('stock_in_records')->find($recordId);
        if (! $rec) {
            Http::fail(404, 'Không tìm thấy phiếu nhập kho');
        }

        return Http::json(S::stockIn($rec));
    }

    public function createStockIn(Request $r)
    {
        $user = Auth::user($r);
        [$data] = Validate::body($r, ['warehouse_code' => 'str', 'supplier' => 'str'] + self::COMMON + ['items' => ['list', 'item' => ['fields' => [
            'item_id' => 'str', 'item_code' => 'str', 'item_name' => 'str', 'quantity' => 'int', 'unit' => 'str',
            'price' => ['float', 'default' => 0.0],
        ]]]]);
        $rec = InventoryService::withIdRetry(function () use ($data, $user) {
            $id = InventoryService::nextId('stock_in_records', 'PN', $data['warehouse_code'], $data['date']);

            return InventoryService::createStockIn($data, $id, $user);
        }, 'phiếu nhập');

        return Http::json(S::stockIn($rec), 201);
    }

    public function deleteStockIn(Request $r, string $recordId)
    {
        $user = Auth::passkey($r);
        $rec = DB::table('stock_in_records')->find($recordId);
        if (! $rec) {
            Http::fail(404, 'Không tìm thấy phiếu nhập kho');
        }

        return Http::json(S::stockIn(InventoryService::cancelStockIn($rec, $user['id'])));
    }

    // ---------------- STOCK OUT ----------------

    public function stockOut(Request $r)
    {
        $q = Validate::query($r, [
            'q' => ['?str', 'default' => null], 'warehouse_code' => ['?str', 'default' => null],
            'recipient' => ['?str', 'default' => null], 'purpose' => ['?str', 'default' => null],
            'date_from' => ['?str', 'default' => null], 'date_to' => ['?str', 'default' => null],
            'include_cancelled' => ['bool', 'default' => false],
            'page' => ['?int', 'default' => null, 'ge' => 1], 'page_size' => ['?int', 'default' => null, 'ge' => 1, 'le' => 200],
        ]);
        $query = DB::table('stock_out_records');
        if (! $q['include_cancelled']) {
            $query->where('status', '!=', 'cancelled');
        }
        if ($q['q']) {
            $p = Http::likeEscape($q['q']);
            $query->where(fn ($w) => $w->where('id', 'like', $p)->orWhere('note', 'like', $p)->orWhere('recipient', 'like', $p));
        }
        foreach (['warehouse_code', 'recipient', 'purpose'] as $f) {
            if ($q[$f]) {
                $query->where($f, $q[$f]);
            }
        }
        if ($q['date_from']) {
            $query->where('date', '>=', $q['date_from']);
        }
        if ($q['date_to']) {
            $query->where('date', '<=', $q['date_to']);
        }
        $query->orderByDesc('created_at');

        return Http::json(Http::paginate($query, $q['page'], $q['page_size'], [S::class, 'stockOut']));
    }

    public function stockOutOne(string $recordId)
    {
        $rec = DB::table('stock_out_records')->find($recordId);
        if (! $rec) {
            Http::fail(404, 'Không tìm thấy phiếu xuất kho');
        }

        return Http::json(S::stockOut($rec));
    }

    public function createStockOut(Request $r)
    {
        $user = Auth::user($r);
        [$data] = Validate::body($r, ['warehouse_code' => 'str', 'recipient' => 'str', 'purpose' => 'str'] + self::COMMON + ['items' => ['list', 'item' => ['fields' => [
            'item_id' => 'str', 'item_code' => 'str', 'item_name' => 'str', 'quantity' => 'int', 'unit' => 'str',
            'sell_price' => ['?float', 'default' => null],
        ]]]]);
        $rec = InventoryService::withIdRetry(function () use ($data, $user) {
            $id = InventoryService::nextId('stock_out_records', 'PX', $data['warehouse_code'], $data['date']);

            return InventoryService::createStockOut($data, $id, $user);
        }, 'phiếu xuất');

        return Http::json(S::stockOut($rec), 201);
    }

    public function deleteStockOut(Request $r, string $recordId)
    {
        $user = Auth::passkey($r);
        $rec = DB::table('stock_out_records')->find($recordId);
        if (! $rec) {
            Http::fail(404, 'Không tìm thấy phiếu xuất kho');
        }

        return Http::json(S::stockOut(InventoryService::cancelStockOut($rec, $user['id'])));
    }

    // ---------------- COMPANY ----------------

    private const COMPANY_FIELDS = ['name', 'logo', 'tax_id', 'address', 'phone', 'email', 'bank_name', 'bank_account', 'bank_branch'];

    public function uploadLogo(Request $r)
    {
        $file = $r->file('file');
        if (! $file) {
            throw new ValidationFailed([['type' => 'missing', 'loc' => ['body', 'file'], 'msg' => 'Field required', 'input' => null]]);
        }
        $contents = (string) file_get_contents($file->getRealPath());
        if (strlen($contents) > 10 * 1024 * 1024) {
            Http::fail(400, 'Kích thước file vượt quá 10MB');
        }
        $info = @getimagesizefromstring($contents);
        if (! $info) {
            Http::fail(400, 'File không phải ảnh hợp lệ');
        }
        [$w, $h, $type] = $info;
        if ($w !== $h) {
            Http::fail(400, "Ảnh phải vuông (1:1). Hiện tại: {$w}x{$h}");
        }
        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            Http::fail(400, 'Chỉ hỗ trợ PNG, JPG, JPEG, WEBP');
        }
        $original = $file->getClientOriginalName();
        $parts = explode('.', $original !== '' ? $original : 'png');
        $ext = strtolower(end($parts));
        $name = Legacy::uuid4().'.'.$ext;
        $dir = config('khohang.uploads_dir').'/logos';
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/$name", $contents);

        return Http::json([
            'logo_url' => "/uploads/logos/$name",
            'filename' => $name,
            'size' => strlen($contents),
            'dimensions' => "{$w}x{$h}",
        ]);
    }

    public function company()
    {
        $c = DB::table('company_info')->orderBy('id')->first();

        return Http::json($c ? S::company($c) : null);
    }

    public function createOrUpdateCompany(Request $r)
    {
        Auth::user($r);
        [$data, $set] = Validate::body($r, array_fill_keys(self::COMPANY_FIELDS, ['str', 'default' => '']));
        $c = DB::table('company_info')->orderBy('id')->first();
        $now = Legacy::now();
        if (! $c) {
            $id = DB::table('company_info')->insertGetId($data + ['created_at' => $now, 'updated_at' => $now]);
        } else {
            $id = $c->id;
            DB::table('company_info')->where('id', $id)->update(array_intersect_key($data, $set) + ['updated_at' => $now]);
        }

        return Http::json(S::company(DB::table('company_info')->find($id)));
    }

    public function updateCompany(Request $r)
    {
        Auth::user($r);
        [$data] = Validate::body($r, array_fill_keys(self::COMPANY_FIELDS, ['?str', 'default' => null]));
        $c = DB::table('company_info')->orderBy('id')->first();
        if (! $c) {
            Http::fail(404, 'Chưa có thông tin công ty');
        }
        $update = array_filter($data, fn ($v) => $v !== null);
        DB::table('company_info')->where('id', $c->id)->update($update + ['updated_at' => Legacy::now()]);

        return Http::json(S::company(DB::table('company_info')->find($c->id)));
    }

    // ---------------- WAREHOUSES ----------------

    private const MANAGER = ['list', 'item' => ['fields' => ['email' => 'email', 'position' => 'str']]];

    public function warehouses()
    {
        $activeId = DB::table('warehouses')->where('is_active', true)->orderBy('id')->value('id');

        return Http::json(DB::table('warehouses')->get()->map(fn ($w) => S::warehouse($w, $w->id === $activeId))->all());
    }

    public function activeWarehouse()
    {
        $w = DB::table('warehouses')->where('is_active', true)->orderBy('id')->first();

        return Http::json($w ? S::warehouse($w) : null);
    }

    public function createWarehouse(Request $r)
    {
        Auth::user($r);
        [$data] = Validate::body($r, [
            'name' => 'str', 'code' => 'str', 'address' => ['str', 'default' => ''], 'phone' => ['str', 'default' => ''],
            'managers' => self::MANAGER + ['default' => []], 'notes' => ['str', 'default' => ''],
        ]);
        if (DB::table('warehouses')->where('code', $data['code'])->exists()) {
            Http::fail(400, "Mã kho '{$data['code']}' đã tồn tại");
        }
        $active = DB::table('warehouses')->where('is_active', true)->count() === 0;
        $now = Legacy::now();
        $data['managers'] = Legacy::toJson($data['managers']);
        $id = DB::table('warehouses')->insertGetId($data + ['is_active' => $active, 'created_at' => $now, 'updated_at' => $now]);

        return Http::json(S::warehouse(DB::table('warehouses')->find($id)));
    }

    /** Approved security change: requires the caller's real passkey in X-Passkey. */
    public function updateWarehouse(Request $r, string $warehouseId)
    {
        Auth::passkey($r, allowQuery: false);
        [$data, $set] = Validate::body($r, [
            'name' => ['?str', 'default' => null], 'code' => ['?str', 'default' => null],
            'address' => ['?str', 'default' => null], 'phone' => ['?str', 'default' => null],
            'managers' => ['?list', 'default' => null, 'item' => ['fields' => ['email' => 'email', 'position' => 'str']]],
            'notes' => ['?str', 'default' => null],
        ]);
        $id = Http::pathInt($warehouseId, 'warehouse_id');
        $w = DB::table('warehouses')->find($id);
        if (! $w) {
            Http::fail(404, 'Không tìm thấy kho');
        }
        if ($data['code'] && $data['code'] !== $w->code
            && DB::table('warehouses')->where('code', $data['code'])->where('id', '!=', $id)->exists()) {
            Http::fail(400, "Mã kho '{$data['code']}' đã tồn tại");
        }
        $update = array_filter(array_intersect_key($data, $set), fn ($v) => $v !== null);
        if (isset($update['managers'])) {
            $update['managers'] = Legacy::toJson($update['managers']);
        }
        DB::table('warehouses')->where('id', $id)->update($update + ['updated_at' => Legacy::now()]);

        return Http::json(S::warehouse(DB::table('warehouses')->find($id)));
    }

    /** Approved security change: requires the caller's real passkey in X-Passkey. */
    public function deleteWarehouse(Request $r, string $warehouseId)
    {
        Auth::passkey($r, allowQuery: false);
        $id = Http::pathInt($warehouseId, 'warehouse_id');
        $w = DB::table('warehouses')->find($id);
        if (! $w) {
            Http::fail(404, 'Không tìm thấy kho');
        }
        DB::transaction(function () use ($w, $id) {
            DB::table('warehouses')->where('id', $id)->delete();
            if ($w->is_active) {
                $first = DB::table('warehouses')->orderBy('id')->first();
                if ($first) {
                    DB::table('warehouses')->where('id', $first->id)->update(['is_active' => true, 'updated_at' => Legacy::now()]);
                }
            }
        });

        return Http::json(['message' => 'Đã xóa kho']);
    }

    public function setActiveWarehouse(Request $r, string $warehouseId)
    {
        Auth::user($r);
        $id = Http::pathInt($warehouseId, 'warehouse_id');
        if (! DB::table('warehouses')->where('id', $id)->exists()) {
            Http::fail(404, 'Không tìm thấy kho');
        }
        DB::transaction(function () use ($id) {
            DB::table('warehouses')->update(['is_active' => false]);
            DB::table('warehouses')->where('id', $id)->update(['is_active' => true, 'updated_at' => Legacy::now()]);
        });

        return Http::json(['message' => 'Đã đổi kho active', 'warehouse_id' => $id]);
    }

    public function warehouseInventory(string $warehouseId)
    {
        $id = Http::pathInt($warehouseId, 'warehouse_id');
        $w = DB::table('warehouses')->find($id);
        if (! $w) {
            Http::fail(404, 'Không tìm thấy kho');
        }
        $stats = [];
        $blank = fn ($it, int $min) => [
            'item_id' => (string) $it['item_id'], 'item_code' => (string) $it['item_code'], 'item_name' => (string) $it['item_name'],
            'unit' => (string) $it['unit'], 'total_in' => 0, 'total_out' => 0, 'current_stock' => 0,
            'damaged' => 0, 'missing' => 0, 'min_stock' => $min, 'status' => 'normal',
        ];
        foreach (DB::table('stock_in_records')->where('warehouse_code', $w->code)->get() as $rec) {
            foreach (Legacy::json($rec->items) ?: [] as $it) {
                $key = (string) $it['item_id'];
                if (! isset($stats[$key])) {
                    $info = DB::table('items')->find((int) $key);
                    $stats[$key] = $blank($it, $info ? (int) $info->min_stock : 10);
                }
                $stats[$key]['total_in'] += (int) $it['quantity'];
            }
        }
        foreach (DB::table('stock_out_records')->where('warehouse_code', $w->code)->get() as $rec) {
            foreach (Legacy::json($rec->items) ?: [] as $it) {
                $key = (string) $it['item_id'];
                $stats[$key] ??= $blank($it, 10);
                $stats[$key]['total_out'] += (int) $it['quantity'];
            }
        }
        $c = ['in' => 0, 'low' => 0, 'out' => 0, 'missing' => 0, 'qty' => 0, 'total_missing' => 0];
        foreach ($stats as &$s) {
            $s['current_stock'] = $s['total_in'] - $s['total_out'];
            if ($s['current_stock'] < $s['min_stock']) {
                $s['missing'] = $s['min_stock'] - $s['current_stock'];
                $c['total_missing'] += $s['missing'];
                $c['missing']++;
            }
            if ($s['current_stock'] <= 0) {
                $s['status'] = 'out_of_stock';
                $c['out']++;
            } elseif ($s['current_stock'] < $s['min_stock']) {
                $s['status'] = 'low_stock';
                $c['low']++;
            } else {
                $s['status'] = 'normal';
                $c['in']++;
            }
            $c['qty'] += $s['current_stock'];
        }
        unset($s);

        return Http::json([
            'warehouse_id' => $id,
            'warehouse_code' => $w->code,
            'warehouse_name' => $w->name,
            'total_items' => count($stats),
            'total_quantity' => $c['qty'],
            'items_in_stock' => $c['in'],
            'items_low_stock' => $c['low'],
            'items_out_of_stock' => $c['out'],
            'items_damaged' => 0,
            'items_missing' => $c['missing'],
            'total_damaged' => 0,
            'total_missing' => $c['total_missing'],
            'items' => array_values($stats),
        ]);
    }

    // ---------------- REPORTS ----------------

    public function inventoryByCategory()
    {
        $colors = ['#00BCD4', '#4CAF50', '#FF9800', '#9C27B0', '#F44336', '#2196F3', '#FFC107', '#795548', '#607D8B', '#E91E63'];
        $stats = [];
        foreach (DB::table('items')->get() as $i) {
            $cat = $i->category ?: 'Khác';
            $stats[$cat] = ($stats[$cat] ?? 0) + (int) ($i->quantity ?: 0) * (float) $i->price;
        }
        $out = [];
        $idx = 0;
        foreach ($stats as $cat => $v) {
            $out[] = ['category' => (string) $cat, 'value' => round((float) $v, 2), 'color' => $colors[$idx++ % count($colors)]];
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);

        return Http::json($out);
    }

    public function monthlyTrendReport()
    {
        $data = [];
        foreach (['stock_in_records' => 'import', 'stock_out_records' => 'export'] as $table => $key) {
            foreach (DB::table($table)->get() as $rec) {
                $dt = Legacy::fromIso((string) $rec->date);
                if (! $dt) {
                    continue;
                }
                $m = $dt->format('Y-m');
                $data[$m] ??= ['import' => 0, 'export' => 0];
                $data[$m][$key] += (int) ($rec->total_quantity ?: 0);
            }
        }
        ksort($data, SORT_STRING);
        $names = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'];
        $out = [];
        foreach ($data as $m => $v) {
            [$y, $mo] = explode('-', $m);
            $out[] = ['month' => $names[(int) $mo - 1].' '.$y, 'import' => $v['import'], 'export' => $v['export']];
        }

        return Http::json(array_slice($out, -12));
    }

    public function lowStockItems()
    {
        $out = [];
        foreach (DB::table('items')->get() as $i) {
            $qty = (int) ($i->quantity ?: 0);
            $min = (int) ($i->min_stock ?: 10);
            if ($qty < $min) {
                $out[] = ['name' => $i->name, 'stock' => $qty, 'min' => $min, 'status' => $qty == 0 ? 'danger' : 'warning'];
            }
        }
        usort($out, fn ($a, $b) => [$a['status'] === 'danger', $a['stock']] <=> [$b['status'] === 'danger', $b['stock']]);

        return Http::json($out);
    }
}
