<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBusiness;

class StockTransfer extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'transfer_code',
        'business_id',
        'from_location_type',
        'from_location_id',
        'to_location_type',
        'to_location_id',
        'requested_by',
        'approved_by',
        'dispatched_by',
        'received_by',
        'status',
        'notes',
        'rejection_reason',
    ];

    protected $casts = [
        'status' => TransferStatus::class,
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (self $transfer) {
            if (empty($transfer->transfer_code)) {
                $transfer->transfer_code = 'txr_' . Str::lower(Str::random(10));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'transfer_code';
    }

    public function fromLocation(): MorphTo
    {
        return $this->morphTo('from_location');
    }

    public function toLocation(): MorphTo
    {
        return $this->morphTo('to_location');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }

    public function isDraft(): bool
    {
        return $this->status === TransferStatus::DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === TransferStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === TransferStatus::APPROVED;
    }

    public function isDispatched(): bool
    {
        return $this->status === TransferStatus::DISPATCHED;
    }

    public function isReceived(): bool
    {
        return $this->status === TransferStatus::RECEIVED;
    }

    public function isRejected(): bool
    {
        return $this->status === TransferStatus::REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === TransferStatus::CANCELLED;
    }

    public function canBeSubmitted(): bool
    {
        return $this->isDraft() && $this->items()->count() > 0;
    }

    public function isAwaitingAcknowledgment(): bool
    {
        return $this->status === TransferStatus::AWAITING_ACKNOWLEDGMENT;
    }

    public function canBeApproved(): bool
    {
        return $this->isPending() || $this->isAwaitingAcknowledgment();
    }

    public function canBeAcknowledged(): bool
    {
        return $this->isAwaitingAcknowledgment();
    }

    public function canBeDispatched(): bool
    {
        return $this->isApproved();
    }

    public function canBeReceived(): bool
    {
        return $this->isDispatched();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [TransferStatus::DRAFT, TransferStatus::PENDING, TransferStatus::APPROVED]);
    }

    public function canBeRejected(): bool
    {
        return $this->isPending();
    }
}
