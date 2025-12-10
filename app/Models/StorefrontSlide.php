<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSlide extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'product_id',
        'title',
        'description',
        'price_override',
        'image_path',
        'position',
        'status',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
