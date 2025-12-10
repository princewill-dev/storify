<?php

namespace App\Models;

use App\Enums\FamilyPackDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyPackDelivery extends Model
{
    protected $fillable = [
        'family_pack_order_id',
        'order_id',
        'cycle_number',
        'scheduled_date',
        'status',
        'payment_id',
        'payment_reminder_sent_at',
        'payment_due_reminder_sent_at',
        'payment_overdue_reminder_sent_at',
        'skipped_by',
        'skipped_at',
        'notes',
    ];

    protected $casts = [
        'status' => FamilyPackDeliveryStatus::class,
        'cycle_number' => 'integer',
        'scheduled_date' => 'date',
        'payment_reminder_sent_at' => 'datetime',
        'payment_due_reminder_sent_at' => 'datetime',
        'payment_overdue_reminder_sent_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    /**
     * Get the family pack order
     */
    public function familyPackOrder(): BelongsTo
    {
        return $this->belongsTo(FamilyPackOrder::class);
    }

    /**
     * Get the generated order
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the payment transaction
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_id');
    }

    /**
     * Get the customer who skipped this delivery
     */
    public function skippedByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'skipped_by');
    }

    /**
     * Check if payment is pending
     */
    public function isPaymentPending(): bool
    {
        return $this->status === FamilyPackDeliveryStatus::PAYMENT_PENDING;
    }

    /**
     * Check if delivery was skipped
     */
    public function isSkipped(): bool
    {
        return $this->status === FamilyPackDeliveryStatus::SKIPPED;
    }
}
