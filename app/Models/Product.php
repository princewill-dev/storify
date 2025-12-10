<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'category_id',
        'product_code',
        'name',
        'brand',
        'slug',
        'description',
        'color',
        'tags',
        'quantity',
        'size',
        'size_unit_id',
        'weight',
        'weight_unit_id',
        'amount',
        'discount_percentage',
        'currency_id',
        'status',
        'featured',
        'cod_available',
        'has_variants',
        'bulk_quantity',
        'bulk_price',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->name)) {
                $model->slug = Str::slug($model->name) . '-' . substr((string) Str::uuid(), 0, 8);
            }
            if (empty($model->product_code)) {
                $model->product_code = 'prd_' . strtoupper(Str::random(8));
            }
        });
        static::saving(function ($model) {
            // When product has variants, base amount/quantity are optional
            if ($model->has_variants) {
                return;
            }
            $validator = Validator::make([
                'quantity' => $model->quantity,
                'amount' => $model->amount,
            ], [
                'quantity' => ['required','integer','gt:0'],
                'amount' => ['required','numeric','gt:0'],
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'product_code';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function primaryImage(): ?ProductImage
    {
        $img = $this->images->firstWhere('is_primary', true);
        return $img ?: $this->images->first();
    }

    protected $casts = [
        'featured' => 'boolean',
        'has_variants' => 'boolean',
    ];

    /**
     * Get bulk savings percentage
     */
    public function getBulkSavingsPercentAttribute(): float
    {
        if (!$this->amount || !$this->bulk_quantity || $this->bulk_quantity <= 0) {
            return 0;
        }

        $unitPrice = $this->amount;
        $bulkUnitPrice = $this->bulk_price / $this->bulk_quantity;
        
        if ($unitPrice <= 0) {
            return 0;
        }

        return (($unitPrice - $bulkUnitPrice) / $unitPrice) * 100;
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
