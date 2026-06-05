<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\BulkOrderStatus;
use App\Models\BelongsToBusiness;

class BulkOrder extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'bulk_code',
        'customer_id',
        'store_id',
        'order_id',
        'delivery_address_id',
        'delivery_route_id',
        'status',
        'subtotal',
        'estimated_total',
        'notes',
        'custom_items',
        'reviewed_at',
        'reviewed_by',
        'review_notes',
    ];

    protected $casts = [
        'custom_items' => 'array',
        'reviewed_at' => 'datetime',
        'customer_accepted_at' => 'datetime',
        'status' => BulkOrderStatus::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkOrderItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(DeliveryAddress::class);
    }

    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    /**
     * Generate unique bulk code
     */
    public static function generateBulkCode(): string
    {
        do {
            $code = 'BULK-' . strtoupper(uniqid());
        } while (self::where('bulk_code', $code)->exists());

        return $code;
    }
    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'bulk_code';
    }
}
