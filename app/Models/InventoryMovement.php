<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBusiness;

class InventoryMovement extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'store_id',
        'product_id',
        'quantity_change',
        'movement_type',
        'reason',
        'idempotency_key',
        'performed_by',
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
