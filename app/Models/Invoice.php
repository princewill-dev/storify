<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use SoftDeletes, BelongsToBusiness;

    protected $fillable = [
        'invoice_number', 'business_id', 'user_id', 'store_id',
        'customer_id', 'recipient_name', 'recipient_email',
        'recipient_phone', 'recipient_address', 'status',
        'issue_date', 'due_date', 'subtotal', 'tax_rate',
        'tax_amount', 'discount_type', 'discount_value', 'total',
        'payment_token', 'amount_paid',
        'notes', 'terms', 'meta', 'sent_at', 'paid_at', 'voided_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'status' => InvoiceStatus::class,
        'meta' => 'array',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'INV-' . Str::upper(Str::random(12));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'invoice_number';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::DRAFT;
    }

    public function remainingBalance(): float
    {
        return (float) max(0, $this->total - $this->amount_paid);
    }

    public function isFullyPaid(): bool
    {
        return $this->amount_paid >= $this->total;
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}
