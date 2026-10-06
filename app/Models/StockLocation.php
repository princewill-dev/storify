<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLocation extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'locationable_type',
        'locationable_id',
        'quantity',
        'min_quantity',
        'business_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'min_quantity' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function locationable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Query form of {@see isLowStock()}, so counts and lists can agree with
     * the per-row check instead of re-deriving the threshold.
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->whereColumn('quantity', '<=', 'min_quantity')->where('min_quantity', '>', 0);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->min_quantity && $this->min_quantity > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }
}
