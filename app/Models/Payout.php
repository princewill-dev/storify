<?php

namespace App\Models;

use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One attempt to move money from Storify's balance to a business's bank.
 *
 * The row is the unit of idempotency. {@see reserve()} writes it — and gets its
 * `reference` from the database's unique index — *before* the provider is told
 * anything, so a retry after a crash reuses the row rather than opening a second
 * one. That reverses the arrangement this replaces: the old key hashed the
 * account, destination, amount and date, which only worked because the provider
 * balance emptied itself and two identical payouts could never legitimately
 * happen. A wallet does not empty itself, so they can, and a key derived from the
 * amount would have silently dropped the second one.
 *
 * The destination is copied onto the row rather than read through the business.
 * A payout that has already left has to keep saying where it went, even after the
 * business changes its bank.
 *
 * Status transitions belong to the service that drives them
 * ({@see PayoutSweepService}), and this model
 * deliberately owns only the shape of a payout and the claim on a reference. Two
 * writers of `status` is how a row ends up `paid` with its reservation still held,
 * so the rule is one: nothing outside that service moves this column.
 */
class Payout extends Model
{
    /** Reserved, not yet sent. */
    public const STATUS_PENDING = 'pending';

    /** Sent, provider has accepted it, outcome unknown. */
    public const STATUS_PROCESSING = 'processing';

    /** Provider confirmed the money landed. */
    public const STATUS_PAID = 'paid';

    /** Provider refused it, or returned it before it landed. */
    public const STATUS_FAILED = 'failed';

    /** Landed, and then came back — a recall or a reversed transfer. */
    public const STATUS_REVERSED = 'reversed';

    /**
     * Closed out, and not going to be attempted again in this form.
     *
     * Either abandoned before it was ever sent, or a refusal a person has decided
     * not to keep holding against the destination — see
     * {@see PayoutSweepService::clearDestinationFailures()}. Both mean the same
     * thing to everything downstream: no money moved, and this row is no longer
     * evidence about whether the next one will work.
     */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'business_id', 'store_id', 'provider', 'destination_id', 'payout_bank_id',
        'bank_code', 'account_number', 'account_name',
        'amount_kobo', 'currency', 'status', 'reference', 'provider_payout_id',
        'failure_code', 'failure_reason', 'requested_by',
        'requested_at', 'sent_at', 'paid_at', 'failed_at', 'reversed_at',
        'attempts', 'meta',
    ];

    protected $casts = [
        'amount_kobo' => 'integer',
        'attempts' => 'integer',
        'meta' => 'array',
        'requested_at' => 'datetime',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Null when the job asked for this payout, set when a person did.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The sales this payout's amount was drawn from.
     *
     * The answer to "what did this ₦47,500 consist of", which the row alone cannot
     * give — see {@see PayoutAllocation}. Ordered oldest sale first, because that
     * is the order they were taken in and it is the order a person reading the
     * payout will expect.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PayoutAllocation::class)->orderBy('transaction_id');
    }

    /**
     * Claim a reference for a payout and write it, before anything is sent.
     *
     * The amount is signed, and so is the wallet it comes out of: a reversal can
     * leave a business owing Storify, and that has to be recordable rather than
     * fatal. Everything else is copied down from the destination at the moment of
     * asking, because the destination can change afterwards.
     *
     * `requested_at` is stamped here rather than taken from `created_at`: the row
     * can be reserved, sit in `pending` while a slow provider call is retried, and
     * the question the job asks is when we first tried, not when the row
     * appeared.
     */
    public static function reserve(
        int $businessId,
        ?int $storeId,
        string $provider,
        string $accountNumber,
        ?string $bankCode,
        ?string $accountName,
        int $amountKobo,
        string $currency = 'NGN',
        ?int $requestedBy = null,
        ?int $payoutBankId = null,
    ): self {
        return static::query()->create([
            'business_id' => $businessId,
            'store_id' => $storeId,
            'provider' => $provider,
            // Which of the business's accounts this was, alongside the number and
            // code it was made to. A snapshot, written here and never resolved
            // again: a payout already reserved keeps going where it was reserved
            // for even if the store changes its bank in the meantime.
            'payout_bank_id' => $payoutBankId,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'account_name' => $accountName,
            'amount_kobo' => $amountKobo,
            'currency' => $currency,
            'status' => self::STATUS_PENDING,
            'reference' => self::newReference($businessId),
            'requested_by' => $requestedBy,
            'requested_at' => now(),
        ]);
    }

    /**
     * `po-{business}-{ulid}` — ours to read, and the provider's transfer reference.
     *
     * **Lowercase, and the prefix is not cosmetic.** Paystack requires a transfer
     * reference to be lowercase, with only `-`, `_` and alphanumerics allowed —
     * an uppercase one is refused outright, which would leave a business unpaid
     * for a reason nothing on our side would name. Bachs is indifferent, so the
     * stricter provider sets the format for both: one reference, two rails, and
     * no case-folding at a boundary where the mistake would be invisible.
     *
     * A ULID rather than a random string or a counter: it sorts by creation time,
     * so a list of payouts reads in the order they were attempted without a join
     * to `created_at`, and it carries no sequence a caller could guess and reuse.
     */
    private static function newReference(int $businessId): string
    {
        return 'po-'.$businessId.'-'.strtolower(Str::ulid()->toBase32());
    }

    /**
     * A payout is addressed by the reference, everywhere.
     *
     * The reference is the name this payout has in the world: it is what the
     * provider was given, what is printed on the transfer, and what the business
     * reads off it when it rings up about money that has not arrived — and, as
     * {@see newReference()} makes sure, it is the only name that identifies the
     * business it belongs to. The auto-increment `id` is an internal key that says
     * none of that, so a URL built on it leaks how many payouts the platform has
     * ever made and gives a support conversation nothing either side can check.
     *
     * Bound here rather than in the route so every binding — this one today, and
     * any route added later — agrees on what a payout is called. There is exactly
     * one route that binds a payout, so the blast radius of the change is that
     * route plus the links to it.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
