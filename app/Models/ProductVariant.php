<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBusiness;

class ProductVariant extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'product_id',
        'variant_code',
        'sku',
        'size',
        'size_unit_id',
        'weight',
        'weight_unit_id',
        'color',
        'quantity',
        'amount',
        'currency_id',
        'status',
        'featured',
    ];

    protected $casts = [
        'featured' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->variant_code)) {
                $model->variant_code = 'var_' . strtoupper(Str::random(10));
            }
            if (empty($model->status)) {
                $model->status = 'active';
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
