<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal note a business keeps about one of its customers.
 *
 * Never rendered on the storefront, and never returned by a storefront
 * endpoint — notes are the business's working memory ("called about the missing
 * item, refunded"), not a message to the customer. CustomerMessageService is
 * the thing that writes to a customer.
 *
 * `user_id` is the author. Nullable, and `nullOnDelete`, so a note survives the
 * departure of whoever wrote it: it belongs to the business, not the employee.
 */
class CustomerNote extends Model
{
    protected $fillable = [
        'business_id',
        'customer_id',
        'user_id',
        'body',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
