<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use App\Enums\StockMovementType;

class StockMovement extends Model
{
    use HasFactory;

    public const TYPE_ADDED = StockMovementType::ADDED->value;
    public const TYPE_REMOVED = StockMovementType::REMOVED->value;
    public const TYPE_TRANSFERRED = StockMovementType::TRANSFERRED->value;
    public const TYPE_ADJUSTED = StockMovementType::ADJUSTED->value;

    protected $fillable = [
        'movement_code',
        'product_id',
        'product_variant_id',
        'from_location_type',
        'from_location_id',
        'to_location_type',
        'to_location_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
        'performed_by_type',
        'performed_by_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (StockMovement $movement) {
            if (empty($movement->movement_code)) {
                $movement->movement_code = 'stm_' . Str::upper(Str::random(10));
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function fromLocation(): MorphTo
    {
        return $this->morphTo();
    }

    public function toLocation(): MorphTo
    {
        return $this->morphTo();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function performedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public static function typeBadgeData(): array
    {
        return StockMovementType::badgeData();
    }
}
