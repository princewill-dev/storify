<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\BelongsToBusiness;

class Transaction extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'reference',
        'order_id',
        'invoice_id',
        'business_id',
        'payment_method_id',
        'amount',
        'currency',
        'status',
        'gateway_reference',
        'gateway_response',
        'metadata',
        'paid_at',
        'payment_slip',
        'store_bank_id',
        'balance_updated_at',
        'store_balance_before',
        'store_balance_after',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'balance_updated_at' => 'datetime',
        'status' => TransactionStatus::class,
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($transaction) {
            if (empty($transaction->reference)) {
                $transaction->reference = 'TXN-' . strtoupper(Str::random(12));
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function storeBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status instanceof TransactionStatus ? $this->status->label() : ucfirst($this->status);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->status instanceof TransactionStatus ? $this->status->badgeClass() : 'badge-secondary light';
    }
}
