<?php

namespace App\Models;

use App\Services\Payments\Custody\PayoutAllocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One sale's share of one payout.
 *
 * The join that makes "where did this money come from" and "where did this sale's
 * money go" answerable, in both directions, for a system that pays businesses out
 * of a pooled balance. See the migration for why a pointer on either table could
 * not do this job.
 *
 * ## The status describes the *payout*, not the sale
 *
 * That is the whole of the model. A sale's own fate — refunded, charged back — is
 * already recorded on the transaction (`unwound_at`), and it is not the same
 * question: a refunded sale whose payout landed was still paid out, and the fact
 * belongs on the payout's side of the record. So:
 *
 * | Status | Means | Claims the sale? |
 * |---|---|---|
 * | `reserved` | the payout is in flight | yes |
 * | `settled` | the payout landed | yes |
 * | `released` | the payout did not happen; the money is back in the wallet | no |
 * | `reversed` | the payout landed and was recalled | no |
 *
 * "Claims the sale" is the distinction that matters to the send: a sale whose
 * net is already spoken for by an open or landed payout must not be counted again
 * when the next payout's amount is worked out, or one sale funds two transfers.
 *
 * Nothing outside {@see PayoutAllocationService}
 * writes these rows.
 */
class PayoutAllocation extends Model
{
    /** The payout has been reserved and sent, and its fate is unknown. */
    public const STATUS_RESERVED = 'reserved';

    /** The provider confirmed the money landed. */
    public const STATUS_SETTLED = 'settled';

    /** The payout did not happen — refused, or the destination was unusable. */
    public const STATUS_RELEASED = 'released';

    /** The payout landed and was then recalled by the provider. */
    public const STATUS_REVERSED = 'reversed';

    /**
     * The statuses that still speak for the sale's money.
     *
     * The single definition of "already accounted for", read by the walk that
     * decides what a new payout may draw on. A status missing from here is a sale
     * paid out twice; one wrongly included is a sale that never gets paid.
     */
    public const CLAIMING = [self::STATUS_RESERVED, self::STATUS_SETTLED];

    protected $fillable = [
        'payout_id', 'transaction_id', 'business_id', 'store_id',
        'amount_kobo', 'currency', 'status', 'settled_at', 'released_at',
    ];

    protected $casts = [
        'amount_kobo' => 'integer',
        'settled_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The allocations that still speak for a sale's money. */
    public function scopeClaiming(Builder $query): Builder
    {
        return $query->whereIn('status', self::CLAIMING);
    }

    /**
     * How much of each of a business's sales is already spoken for, by transaction id.
     *
     * One query for the whole walk rather than one per sale: the walk runs over a
     * business's sales in FIFO order and would otherwise ask the same question a few
     * hundred times. Scoped to the business for the same reason it exists —
     * the answer is only ever wanted about the sales a payout is being drawn from.
     *
     * A sale absent from the result is one nothing has claimed, which is the
     * ordinary case and reads as zero without a second query.
     *
     * @return Collection<int, int>
     */
    public static function claimedForBusiness(int $businessId): Collection
    {
        return static::query()
            ->claiming()
            ->where('business_id', $businessId)
            ->selectRaw('transaction_id, SUM(amount_kobo) as claimed_kobo')
            ->groupBy('transaction_id')
            ->pluck('claimed_kobo', 'transaction_id')
            ->map(fn ($kobo) => (int) $kobo);
    }
}
