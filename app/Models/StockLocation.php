<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Concerns\BelongsToBusiness;

class StockLocation extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'locationable_type',
        'locationable_id',
        'quantity',
        'min_quantity',
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

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->min_quantity && $this->min_quantity > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }
}
