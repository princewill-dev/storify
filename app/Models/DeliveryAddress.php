<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery address, in one of two roles.
 *
 * A **saved** address (`is_snapshot = false`) is the customer's own book
 * entry — theirs to label, edit, make default and delete. A **snapshot**
 * (`is_snapshot = true`) is the copy an order was delivered to, written once by
 * checkout and never edited: `orders.delivery_address_id` points at it, and a
 * fulfilment record must not change under the order it belongs to.
 *
 * The two share a table because they share every column. `is_snapshot` is what
 * keeps a book read from listing one copy per order, and a book edit from
 * rewriting history.
 */
class DeliveryAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'company_name',
        'street_address',
        'apartment',
        'city',
        'state',
        'country',
        'landmark',
        'zip_code',
        'map_link',
        'is_default',
        'is_snapshot',
        'delivery_route_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_snapshot' => 'boolean',
    ];

    /**
     * The customer's address book — saved entries only.
     */
    public function scopeSaved($query)
    {
        return $query->where('is_snapshot', false);
    }

    /**
     * Get the customer that owns the address
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    /**
     * Get full address as string
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street_address,
            $this->apartment,
            $this->landmark ? "Landmark: {$this->landmark}" : null,
            $this->city,
            $this->state,
            $this->zip_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Scope to get default address
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Set this address as default and unset others
     */
    public function setAsDefault(): void
    {
        // Unset all other default addresses for this customer
        static::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Set this as default
        $this->update(['is_default' => true]);
    }
}
