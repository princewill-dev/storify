<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BelongsToBusiness;

class Order extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id',
        'order_number',
        'customer_id',
        'store_id',
        'user_id',
        'delivery_address_id',
        'delivery_route_id',
        'source',
        'delivery_state',
        'delivery_area',
        'delivery_days',
        'subtotal',
        'shipping_fee',
        'tax',
        'total',
        'status',
        'status',
        'notes',
        'meta',
        'staff_id',
        'pos_session_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'status' => \App\Enums\OrderStatus::class,
        'meta' => 'array',
    ];

    public function isPos(): bool
    {
        return $this->source === 'pos';
    }

    public function isShop4me(): bool
    {
        return $this->source === 'shop4me';
    }

    public function isBulk(): bool
    {
        return $this->source === 'bulk';
    }

    public function isFamilyPack(): bool
    {
        return $this->source === 'family_pack';
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . strtoupper(Str::random(10));
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function bulkOrder(): HasOne
    {
        return $this->hasOne(BulkOrder::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class)->latestOfMany();
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(DeliveryAddress::class);
    }

    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    public function delivery(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\OrderDelivery::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status instanceof \App\Enums\OrderStatus ? $this->status->label() : ucfirst($this->status);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->status instanceof \App\Enums\OrderStatus ? $this->status->badgeClass() : 'bg-secondary';
    }

    public function getPaymentStatusAttribute()
    {
        $transaction = $this->transaction; // Uses the HasOne latestOfMany relationship

        if (!$transaction) {
            return \App\Enums\PaymentStatus::UNPAID;
        }

        // Map TransactionStatus to PaymentStatus
        return match($transaction->status) {
            \App\Enums\TransactionStatus::PENDING => \App\Enums\PaymentStatus::PENDING,
            \App\Enums\TransactionStatus::CONFIRMED => \App\Enums\PaymentStatus::PAID,
            \App\Enums\TransactionStatus::REFUNDED => \App\Enums\PaymentStatus::REFUNDED,
            \App\Enums\TransactionStatus::CANCELED => \App\Enums\PaymentStatus::FAILED,
            default => \App\Enums\PaymentStatus::UNPAID,
        };
    }
}
