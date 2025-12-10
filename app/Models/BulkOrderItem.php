<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkOrderItem extends Model
{
    protected $fillable = [
        'bulk_order_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'unit_price',
        'subtotal',
        'is_custom',
        'budgeted_amount',
    ];

    protected $casts = [
        'is_custom' => 'boolean',
    ];

    public function bulkOrder(): BelongsTo
    {
        return $this->belongsTo(BulkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
