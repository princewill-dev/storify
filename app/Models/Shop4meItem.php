<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop4meItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop4me_request_id',
        'product_id',
        'product_variant_id',
        'name',
        'qty',
        'unit_hint',
        'amount_hint',
        'notes',
        'allow_substitute',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'amount_hint' => 'decimal:2',
        'allow_substitute' => 'boolean',
    ];

    public function request()
    {
        return $this->belongsTo(Shop4meRequest::class, 'shop4me_request_id');
    }

    public function responses()
    {
        return $this->hasMany(Shop4meItemResponse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
