<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\BelongsToBusiness;

class ProductImage extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'product_id',
        'business_id',
        'path',
        'is_primary',
        'position',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
