<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalDownload extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'order_id',
        'order_item_id',
        'product_id',
        'customer_id',
        'token',
        'download_count',
        'max_downloads',
        'expires_at',
        'last_downloaded_at',
    ];

    protected $casts = [
        'download_count' => 'integer',
        'max_downloads' => 'integer',
        'expires_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return (int) $this->download_count >= (int) $this->max_downloads;
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && ! $this->isExhausted();
    }

    public function downloadsRemaining(): int
    {
        return max(0, (int) $this->max_downloads - (int) $this->download_count);
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        if ($this->isExhausted()) {
            return 'Limit reached';
        }

        return 'Active';
    }

    public function getStatusBadgeClassAttribute(): string
    {
        if (! $this->isActive()) {
            return 'bg-slate-100 text-slate-500';
        }

        return 'bg-emerald-50 text-emerald-700';
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
