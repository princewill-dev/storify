<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkOrderRevision extends Model
{
    protected $fillable = [
        'bulk_order_id',
        'revision_number',
        'created_by_type',
        'created_by_id',
        'notes',
        'items_snapshot',
        'total_amount',
        'is_customer_accepted',
    ];

    protected $casts = [
        'items_snapshot' => 'array',
        'total_amount' => 'decimal:2',
        'is_customer_accepted' => 'boolean',
    ];

    /**
     * Get the bulk order this revision belongs to
     */
    public function bulkOrder(): BelongsTo
    {
        return $this->belongsTo(BulkOrder::class);
    }

    /**
     * Get the creator (admin or customer) polymorphically
     */
    public function createdBy()
    {
        if ($this->created_by_type === 'admin') {
            return $this->belongsTo(\App\Models\Admin::class, 'created_by_id');
        }
        
        return $this->belongsTo(Customer::class, 'created_by_id');
    }

    /**
     * Check if this is an admin revision
     */
    public function isAdminRevision(): bool
    {
        return $this->created_by_type === 'admin';
    }

    /**
     * Check if this is a customer revision
     */
    public function isCustomerRevision(): bool
    {
        return $this->created_by_type === 'customer';
    }
}
