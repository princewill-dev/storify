<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Models\BelongsToBusiness;

class Product extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'store_id',
        'business_id',
        'category_id',
        'section_id',
        'warehouse_id',
        'product_code',
        'name',
        'brand',
        'slug',
        'description',
        'color',
        'tags',
        'quantity',
        'stock_quantity',
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
            // Auto-sync warehouse_id from section
            if ($model->isDirty('section_id') && $model->section_id) {
                $section = \App\Models\Section::find($model->section_id);
                if ($section && $section->warehouse_id) {
                    $model->warehouse_id = $section->warehouse_id;
                }
            }

            // Only validate quantity/amount when product is assigned to a store
            if (!$model->store_id) {
                return;
            }
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function sizeUnit(): BelongsTo
    {
        return $this->belongsTo(SizeUnit::class, 'size_unit_id');
    }

    public function weightUnit(): BelongsTo
    {
        return $this->belongsTo(WeightUnit::class, 'weight_unit_id');
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

    /**
     * Number of units sold = initial stock minus remaining.
     */
    public function soldQuantity(): int
    {
        if (is_null($this->stock_quantity)) {
            return 0;
        }
        return max(0, (int)$this->stock_quantity - (int)$this->quantity);
    }

    /**
     * Stock percentage remaining (0-100).
     */
    public function stockPercentage(): int
    {
        if (is_null($this->stock_quantity) || (int)$this->stock_quantity === 0) {
            return 100;
        }
        return (int) round(((int)$this->quantity / (int)$this->stock_quantity) * 100);
    }
}
