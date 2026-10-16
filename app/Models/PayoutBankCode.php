<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business bank account, in one payout provider's code space.
 *
 * See the migration that created `payout_bank_codes` for why this exists: the
 * business picks its bank out of Paystack's list and the payout leaves through
 * Bachs, and the two number the same institutions differently.
 *
 * Read at dispatch, not at setup. Which row a payout needs is decided by whichever
 * provider is the default when the send happens, so a row here is not a decision
 * that was taken once — it is the standing answer for one provider, and switching
 * the processor switches which row is read without the business being involved.
 */
class PayoutBankCode extends Model
{
    protected $fillable = [
        'store_bank_id',
        'provider',
        'bank_code',
        'destination_id',
        'is_usable',
        'failure_reason',
        'resolved_at',
    ];

    protected $casts = [
        'is_usable' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    /**
     * The account this is an answer about.
     *
     * @return BelongsTo<StoreBank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class, 'store_bank_id');
    }

    /**
     * Whether a send through this provider can actually happen.
     *
     * Both halves are required and neither implies the other: a resolvable bank
     * code with no destination is an account the provider refused, and a
     * destination with no code cannot exist — but the code alone is not enough to
     * send, which is what the destination id is.
     */
    public function payable(): bool
    {
        return $this->bank_code !== null && filled($this->destination_id);
    }

    /**
     * Whether the provider itself refused this account.
     *
     * `is_usable` is the only record of a verdict that survives to the row.
     * {@see PayoutBankResolution} draws the same line with its `conclusive` flag,
     * which is not stored: a provider that said no writes `false` there, while a
     * provider we could not reach — and a dry run that worked the code out without
     * asking — write `null`. So `false` is a sentence the provider said and `null`
     * is our own silence, and only the first may stop a business using this
     * account. Reading this as "not payable" would refuse a business for our
     * outage, which is the mistake the whole resolution mechanism is arranged to
     * avoid.
     */
    public function refused(): bool
    {
        return ! $this->payable() && $this->is_usable === false;
    }
}
