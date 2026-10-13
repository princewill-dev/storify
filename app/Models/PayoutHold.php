<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing instruction not to pay somebody, with a reason and a name against it.
 *
 * Read in exactly one place — the payout sweep — which is what makes it a hold on
 * *money moving* and nothing else. A hold does not stop a business being charged,
 * does not touch a balance, and does not stop a payout already at the provider
 * from being resolved. See the migration for why that placement is the whole
 * safety argument.
 */
class PayoutHold extends Model
{
    /** Everything, everywhere. The incident switch. */
    public const SCOPE_SYSTEM = 'system';

    /** One business's payouts, across all of its stores. */
    public const SCOPE_BUSINESS = 'business';

    /** One store's share, while the rest of the business is paid as normal. */
    public const SCOPE_STORE = 'store';

    /** Placed by a person, through the console or the command. */
    public const SOURCE_MANUAL = 'manual';

    /** Placed by suspending the business or the store, and lifted by reactivating it. */
    public const SOURCE_SUSPENSION = 'suspension';

    /**
     * The value that marks a hold as in force, and the only column in the unique
     * index that changes: 1 while open, absent once released, which is what lets a
     * subject be held, released, and held again.
     */
    public const OPEN = 1;

    protected $fillable = [
        'scope', 'business_id', 'store_id', 'reason', 'source',
        'placed_by', 'placed_at', 'released_at', 'released_by', 'open_marker',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'store_id' => 'integer',
        'placed_at' => 'datetime',
        'released_at' => 'datetime',
        'open_marker' => 'integer',
    ];

    /**
     * The business this is about, or null for a system-wide hold.
     *
     * Null rather than an exception: `business_id` is 0 on a system hold, which is
     * a deliberate non-reference (see the migration), and a relation that threw on
     * it would make every listing of the open holds more awkward than the sentinel
     * saves.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** The holds currently in force. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('open_marker');
    }

    /**
     * A one-line description of what this hold is over, for a log or a console row.
     *
     * Built here rather than at each of the places that say it, because "held" on
     * its own is the message that makes an operator go looking for a bug.
     */
    public function describe(): string
    {
        return match ($this->scope) {
            self::SCOPE_SYSTEM => 'the whole platform',
            self::SCOPE_BUSINESS => 'business '.$this->business_id,
            self::SCOPE_STORE => 'store '.$this->store_id,
            default => $this->scope,
        };
    }
}
