<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a customer agreeing to, or refusing, one thing.
 *
 * Append-only by design: nothing in the application updates or deletes a row
 * here. The current answer is the newest row for a type, which is what makes
 * the trail able to answer "when did they opt in, and how" rather than only
 * "are they opted in".
 *
 * No `BelongsToBusiness` scope: rows are written and read from the customer's
 * own token, where the customer is the tenant boundary, and from management,
 * where the business is taken from the customer being acted on. A global scope
 * keyed on the acting user would be wrong on the storefront side, where there
 * is no user at all.
 */
class CustomerConsent extends Model
{
    protected $fillable = [
        'business_id',
        'customer_id',
        'type',
        'granted',
        'source',
        'ip_address',
    ];

    protected $casts = [
        'granted' => 'boolean',
        'type' => ConsentType::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
