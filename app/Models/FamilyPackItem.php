<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyPackItem extends Model
{
    protected $fillable = [
        'family_pack_order_id',
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
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'budgeted_amount' => 'decimal:2',
        'is_custom' => 'boolean',
    ];

    /**
     * Get the family pack order that owns this item
     */
    public function familyPackOrder(): BelongsTo
    {
        return $this->belongsTo(FamilyPackOrder::class);
    }

    /**
     * Get the product (null for custom items)
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
