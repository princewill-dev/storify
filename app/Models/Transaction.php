<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'reference',
        'idempotency_key',
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
        'fee_kobo',
        'net_kobo',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'balance_updated_at' => 'datetime',
        'status' => TransactionStatus::class,
        'metadata' => 'array',
        'fee_kobo' => 'integer',
        'net_kobo' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->reference)) {
                $transaction->reference = 'TXN-'.strtoupper(Str::random(12));
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

    /**
     * What the provider was actually asked to charge, in its own minor units.
     *
     * Null when no conversion was involved — which is every naira transaction,
     * and every row written before conversion existed. The reader lives here
     * rather than at the two settlement call sites because the metadata key is
     * a contract between whoever writes it and whoever settles against it, and
     * two copies of it would eventually disagree.
     *
     * `amount` stays the store's own amount. This does not.
     */
    public function chargeAmountMinor(): ?int
    {
        $pricing = $this->metadata['pricing'] ?? null;

        return is_array($pricing) && isset($pricing['charge_amount_minor'])
            ? (int) $pricing['charge_amount_minor']
            : null;
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
