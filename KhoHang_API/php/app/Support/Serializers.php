<?php

namespace App\Support;

/** Port of db_helpers.py + pydantic response models (field sets and types). */
class Serializers
{
    public static function supplier(object $m): array
    {
        return [
            'name' => $m->name,
            'tax_id' => $m->tax_id ?: '',
            'address' => $m->address ?: '',
            'phone' => $m->phone ?: '',
            'email' => $m->email ?: '',
            'bank_account' => $m->bank_account ?: '',
            'bank_name' => $m->bank_name ?: '',
            'notes' => $m->notes ?: '',
            'id' => (int) $m->id,
            'outstanding_debt' => (float) ($m->outstanding_debt ?: 0.0),
        ];
    }

    /** schemas.Item has no `quantity` field: pydantic drops it from the response. */
    public static function item(object $m): array
    {
        return [
            'name' => $m->name,
            'sku' => $m->sku,
            'unit' => $m->unit,
            'price' => (float) $m->price,
            'category' => $m->category,
            'supplier_id' => $m->supplier_id === null ? null : (int) $m->supplier_id,
            'min_stock' => (int) ($m->min_stock ?: 10),
            'id' => (int) $m->id,
        ];
    }

    public static function warehouse(object $m, ?bool $isActive = null): array
    {
        $managers = Legacy::json($m->managers) ?: [];

        return [
            'name' => $m->name,
            'code' => $m->code,
            'address' => $m->address ?: '',
            'phone' => $m->phone ?: '',
            'managers' => array_map(fn ($x) => [
                'email' => $x['email'] ?? null,
                'position' => $x['position'] ?? null,
            ], $managers),
            'notes' => $m->notes ?: '',
            'id' => (int) $m->id,
            'created_at' => Legacy::iso($m->created_at),
            'is_active' => $isActive ?? (bool) $m->is_active,
        ];
    }

    public static function transaction(object $m): array
    {
        return [
            'type' => $m->type,
            'item_id' => (int) $m->item_id,
            'quantity' => (int) $m->quantity,
            'note' => $m->note,
            'warehouse_code' => $m->warehouse_code,
            'voucher_id' => $m->voucher_id,
            'actor_user_id' => $m->actor_user_id,
            'id' => (int) $m->id,
            'timestamp' => Legacy::iso($m->timestamp),
        ];
    }

    public static function company(object $m): array
    {
        return [
            'name' => $m->name ?: '',
            'logo' => $m->logo ?: '',
            'tax_id' => $m->tax_id ?: '',
            'address' => $m->address ?: '',
            'phone' => $m->phone ?: '',
            'email' => $m->email ?: '',
            'bank_name' => $m->bank_name ?: '',
            'bank_account' => $m->bank_account ?: '',
            'bank_branch' => $m->bank_branch ?: '',
            'id' => (int) $m->id,
        ];
    }

    public static function stockIn(object $m): array
    {
        $items = Legacy::json($m->items) ?: [];

        return [
            'id' => $m->id,
            'warehouse_code' => $m->warehouse_code,
            'supplier' => $m->supplier,
            'date' => $m->date,
            'note' => $m->note ?: '',
            'tax_rate' => (float) ($m->tax_rate ?: 0.0),
            'payment_method' => $m->payment_method ?: 'tiền_mặt',
            'payment_bank_account' => $m->payment_bank_account ?: '',
            'payment_bank_name' => $m->payment_bank_name ?: '',
            'items' => array_map(fn ($i) => [
                'item_id' => (string) $i['item_id'],
                'item_code' => (string) $i['item_code'],
                'item_name' => (string) $i['item_name'],
                'quantity' => (int) $i['quantity'],
                'unit' => (string) $i['unit'],
                'price' => (float) $i['price'],
            ], $items),
            'total_quantity' => (int) ($m->total_quantity ?: 0),
            'total_amount' => (float) ($m->total_amount ?: 0.0),
            'created_at' => Legacy::iso($m->created_at),
            'status' => $m->status ?: 'completed',
        ];
    }

    public static function stockOut(object $m): array
    {
        $items = Legacy::json($m->items) ?: [];

        return [
            'id' => $m->id,
            'warehouse_code' => $m->warehouse_code,
            'recipient' => $m->recipient,
            'purpose' => $m->purpose,
            'date' => $m->date,
            'note' => $m->note ?: '',
            'tax_rate' => (float) ($m->tax_rate ?: 0.0),
            'items' => array_map(fn ($i) => [
                'item_id' => (string) $i['item_id'],
                'item_code' => (string) $i['item_code'],
                'item_name' => (string) $i['item_name'],
                'quantity' => (int) $i['quantity'],
                'unit' => (string) $i['unit'],
                'sell_price' => isset($i['sell_price']) ? (float) $i['sell_price'] : null,
            ], $items),
            'total_quantity' => (int) ($m->total_quantity ?: 0),
            'total_amount' => $m->total_amount === null ? null : (float) $m->total_amount,
            'created_at' => Legacy::iso($m->created_at),
            'status' => $m->status ?: 'completed',
        ];
    }

    public static function chatMessage(object $m): array
    {
        $reply = Legacy::json($m->reply_to);

        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender' => $m->sender,
            'text' => $m->text,
            'reply_to' => $reply ? ['id' => $reply['id'], 'text' => $reply['text'], 'sender' => $reply['sender']] : null,
            'reactions' => Legacy::json($m->reactions) ?: [],
            'created_at' => Legacy::iso($m->created_at),
            'updated_at' => Legacy::iso($m->updated_at),
        ];
    }

    public static function preferences(object $p): array
    {
        $theme = fn (string $k) => [
            'gradient_id' => $p->{$k.'_gradient_id'},
            'pattern_id' => $p->{$k.'_pattern_id'},
            'pattern_opacity' => (float) $p->{$k.'_pattern_opacity'},
            'pattern_size_px' => (int) $p->{$k.'_pattern_size_px'},
            'pattern_tint' => $p->{$k.'_pattern_tint'},
        ];

        return [
            'accent_id' => $p->accent_id,
            'light_mode_theme' => $theme('light_mode'),
            'dark_mode_theme' => $theme('dark_mode'),
            'id' => (int) $p->id,
            'user_id' => $p->user_id,
            'created_at' => Legacy::iso($p->created_at),
            'updated_at' => Legacy::iso($p->updated_at),
        ];
    }
}
