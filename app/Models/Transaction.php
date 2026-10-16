<?php

namespace App\Models;

use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Support\Money\Naira;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'reference',
        'idempotency_key',
        'order_id',
        'invoice_id',
        'business_id',
        'payment_method_id',
        'amount',
        'currency',
        'status',
        'gateway_reference',
        'gateway_response',
        'metadata',
        'paid_at',
        'payment_slip',
        'store_bank_id',
        'balance_updated_at',
        'store_balance_before',
        'store_balance_after',
        'fee_kobo',
        'net_kobo',
        'platform_fee_kobo',
        // Whose money the provider's own fee came out of. Written at the same
        // lines that write `fee_kobo`, from the same managed/own-keys flag —
        // see the migration for why this is a column and not a cutover date.
        'provider_fee_bearer',
        // When a collected sale's share becomes payable, when it was actually
        // moved from pending to available, and when it was given back to the
        // customer. Written by the custody wallet, and only for sales Storify
        // collected for.
        'settle_at',
        'promoted_at',
        'unwound_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'balance_updated_at' => 'datetime',
        'settle_at' => 'datetime',
        'promoted_at' => 'datetime',
        'unwound_at' => 'datetime',
        'status' => TransactionStatus::class,
        'metadata' => 'array',
        'fee_kobo' => 'integer',
        'net_kobo' => 'integer',
        'platform_fee_kobo' => 'integer',
        'provider_fee_bearer' => ProviderFeeBearer::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->reference)) {
                $transaction->reference = 'TXN-'.strtoupper(Str::random(12));
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function storeBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * This sale's share of the payouts that have paid it.
     *
     * Usually one row, sometimes two: a sale is taken FIFO and may be **split**
     * across a payout boundary, so "which payout paid me" has an answer that can be
     * a list. See {@see PayoutAllocation} — `amount_kobo` on the join is what makes
     * the split representable, and this relation is the reading end of it.
     *
     * @return HasMany<PayoutAllocation, $this>
     */
    public function payoutAllocations(): HasMany
    {
        return $this->hasMany(PayoutAllocation::class);
    }

    /**
     * The allocation that currently speaks for this sale's money, or null.
     *
     * `CLAIMING` and not "the latest row": a released or reversed allocation is the
     * record of a payout that did *not* pay this sale, and reporting one of those as
     * the answer to "where did the money go" would name a payout whose money came
     * back. Latest by id, because the ordinary case is one row.
     */
    public function claimingAllocation(): ?PayoutAllocation
    {
        return $this->payoutAllocations
            ->filter(fn (PayoutAllocation $allocation) => in_array($allocation->status, PayoutAllocation::CLAIMING, true))
            ->sortByDesc('id')
            ->first();
    }

    /**
     * What has happened to the money from this sale, in one word — or null.
     *
     * Null is the important case and it is not an error: this is a sale Storify did
     * not collect, so there is no wallet, no payout and nothing to say. A client
     * reading null must show no settlement information rather than "pending".
     *
     * | State | Means |
     * |---|---|
     * | `not_collected` | collected into the wallet is not yet claimed — see below |
     * | `settling` | charged, the provider has not released it to Storify yet |
     * | `payable` | released and waiting for the next payout run |
     * | `payout_in_flight` | a payout carrying it is with the provider |
     * | `paid` | the payout carrying it landed |
     * | `refunded` | refunded or charged back; it was never paid, or it was and the wallet was debited back |
     *
     * `not_collected` is the hook the wallet's own write happens through: the credit
     * claims on `settle_at`, so a row that is platform-managed with a null
     * `settle_at` is one the settlement webhook has not been seen for. It reads
     * distinctly rather than as "settling" because it is a different situation — the
     * money is not in the wallet at all, so it is not part of any balance.
     */
    public function settlementState(): ?string
    {
        if (! $this->isPlatformManaged()) {
            return null;
        }

        // Refunded or charged back. Checked first: a refund can land before a payout
        // ever carried the sale, and after one — and in both cases the sale's own
        // record of it outranks whatever an allocation says, because the allocation
        // describes the payout, not the sale.
        if ($this->unwound_at !== null) {
            return 'refunded';
        }

        if ($this->settle_at === null) {
            return 'not_collected';
        }

        if ($this->promoted_at === null) {
            return 'settling';
        }

        $allocation = $this->claimingAllocation();

        if ($allocation === null) {
            return 'payable';
        }

        return $allocation->status === PayoutAllocation::STATUS_SETTLED ? 'paid' : 'payout_in_flight';
    }

    /**
     * What the provider was actually asked to charge, in its own minor units.
     *
     * Null when no conversion was involved — which is every naira transaction,
     * and every row written before conversion existed. The reader lives here
     * rather than at the two settlement call sites because the metadata key is
     * a contract between whoever writes it and whoever settles against it, and
     * two copies of it would eventually disagree.
     *
     * `amount` stays the store's own amount. This does not.
     */
    public function chargeAmountMinor(): ?int
    {
        $pricing = $this->metadata['pricing'] ?? null;

        return is_array($pricing) && isset($pricing['charge_amount_minor'])
            ? (int) $pricing['charge_amount_minor']
            : null;
    }

    /**
     * Whether Storify processed this sale on the business's behalf, rather than
     * the business charging through a gateway of its own.
     *
     * Read from the transaction rather than from the connection, and that is the
     * point: by the time a sale settles, the connection may have been switched
     * off, revoked, or re-pointed at the business's own keys — and the money
     * that was taken belongs to whichever arrangement was in force when the
     * customer paid, not to whichever one is in force when the webhook lands.
     *
     * This is what decides whether the store's own wallet is credited. For a
     * managed sale it must not be: the money is already in the business's own
     * account at the provider, and crediting a Storify-held balance as well
     * would be holding money that is not ours to hold.
     */
    public function isPlatformManaged(): bool
    {
        return ($this->metadata['payment_mode'] ?? null) === 'platform_managed';
    }

    /**
     * Whether this sale's figures are all in the currency the wallet holds.
     *
     * The wallet, the platform's custody accounts and the payout rail are all
     * denominated in one currency (`config('custody.currency')`), so a collected
     * sale is only representable when its own currency is that one.
     *
     * What goes wrong otherwise is arithmetic rather than policy. The business's
     * share is `netKoboFor(koboFromRounded($this->amount), fee, commission)`:
     * `amount` is the *store's* figure, while `fee_kobo` and `platform_fee_kobo`
     * are the naira figures the provider and we actually charged for the
     * converted charge. On a USD store that reads $25.00 as ₦25.00, which is not
     * a rounding error but a different sum entirely — short by the rate on a
     * currency stronger than the naira (clamped to zero, so the sale is silently
     * never credited at all) and enormous on a weaker one (the store's number
     * credited as naira, so Storify pays out far more than it ever collected).
     *
     * Nothing in this class can fix that. It says only whether the figures are in
     * the one currency they were computed in, and the two callers that move money
     * — `CustodyService::recordCollectedSale()` and
     * `LedgerPostingService::postCustodyCollection()` — refuse on a false rather
     * than converting, because a converted credit would need a rate, and a rate is
     * a number somebody has to be right about.
     */
    public function isInCustodyCurrency(): bool
    {
        return strtoupper((string) $this->currency) === strtoupper((string) config('custody.currency', 'NGN'));
    }

    /**
     * The provider whose settlement window governs this sale.
     *
     * Resolved from the payment method, which for a managed sale is the provider
     * itself (`bachs`, `paystack`) — the same field the ledger reads to decide
     * whether a gateway leg clears through clearing or through a bank.
     *
     * Empty when nothing identifies one. The caller must treat that as "use the
     * longest window", never as "settles instantly": a sale we cannot attribute to
     * a provider is exactly the one not to pay out early on.
     */
    public function settlementProviderCode(): string
    {
        $code = (string) ($this->paymentMethod?->code ?? '');

        return $code !== '' && PaymentGatewayRegistry::has($code) ? $code : '';
    }

    /**
     * When this sale's share stops being merely owed and becomes payable.
     *
     * `paid_at` plus the provider's window — null until the sale is paid, because
     * there is nothing to settle before there is money. A conservative window is
     * the optimistic half of the guard; the provider refusing a transfer the
     * platform balance cannot cover is the authoritative half.
     *
     * `$providerCode` is for callers that know the provider better than the row
     * does — the settlement path has the connection in hand, and a POS leg has no
     * provider in its payment method at all. Unknown providers get the longest
     * configured window rather than the shortest.
     */
    public function settlementDueAt(?string $providerCode = null): ?Carbon
    {
        if ($this->paid_at === null) {
            return null;
        }

        $provider = $providerCode ?? $this->settlementProviderCode();
        $hours = config('custody.settlement_hours')[$provider] ?? null;
        $hours = is_numeric($hours) ? (int) $hours : (int) config('custody.default_hours', 72);

        return $this->paid_at->copy()->addHours($hours);
    }

    /**
     * Our commission on this sale, in kobo, as it was calculated when the
     * customer paid.
     *
     * Recorded rather than recomputed, because the admin can change the rate at
     * any time and the sale was charged at the rate in force that day. A
     * settlement that recalculated would post a commission the provider was
     * never asked to take.
     */
    public function platformFeeKobo(): int
    {
        // Column first, metadata second. The column is where it is written now;
        // the metadata key is what every row written before that column existed
        // carries, and what the backfill reads to fill it in. Falling back means
        // a row is reported correctly whether or not it has been backfilled yet.
        $fee = $this->platform_fee_kobo ?? ($this->metadata['platform_fee_kobo'] ?? null);

        return is_numeric($fee) ? max(0, (int) $fee) : 0;
    }

    /**
     * Whose money the provider's own processing fee came out of.
     *
     * Null reads as `BUSINESS`, and that is the fail-safe direction rather than
     * a convenience: it is the true answer for every row written before the
     * column existed and for a row this process has not refreshed, so an unread
     * attribute preserves history instead of re-ruling on it.
     */
    public function providerFeeBearer(): ProviderFeeBearer
    {
        $bearer = $this->provider_fee_bearer;

        return $bearer instanceof ProviderFeeBearer
            ? $bearer
            : (ProviderFeeBearer::tryFrom((string) $bearer) ?? ProviderFeeBearer::BUSINESS);
    }

    /**
     * Everything taken out of this sale before it reached the business, in kobo.
     *
     * Which fees those are depends on who bore the provider's charge:
     *
     * - **The business did** (its own keys, or any sale before this rule): the
     *   provider's fee *and* our commission, as one figure — which is how the
     *   business experiences it and how its ledger books it, both on one line.
     *   The two stay distinguishable on the row (`fee_kobo` against
     *   `platform_fee_kobo`) and on our own books; they are not presented to the
     *   business as two separate costs.
     * - **Storify did** (Storify charged on its own credentials): our commission
     *   alone. The provider's charge is Storify's own expense, is not part of
     *   what the business was asked to pay for, and never appears on its books.
     *
     * Null, not zero, when the provider's fee is not known yet — and only for a
     * business-bearing sale. A POS managed leg is written when the till closes
     * the sale, and the provider does not report its charge until it settles days
     * later. Returning the commission alone would be understating the fee, and
     * returning zero would be claiming there was none. A platform-bearing sale
     * has no such gap: nothing it owes to the business is waiting on the
     * provider's number.
     */
    public function processingFeeKobo(): ?int
    {
        if (! $this->providerFeeBearer()->isBusiness()) {
            return $this->platformFeeKobo();
        }

        if ($this->fee_kobo === null) {
            return null;
        }

        return max(0, (int) $this->fee_kobo) + $this->platformFeeKobo();
    }

    /**
     * The SQL twin of {@see processingFeeKobo()}, for summing it over rows.
     *
     * It lives here, beside the PHP rule, so the two cannot drift apart. Every
     * business-facing total is built from this one expression — the transactions
     * list's totals bar, the CSV, the dashboard cards — and a copy of it in each
     * of those places would eventually disagree with the others about what a
     * business was charged, which is the kind of disagreement that shows up as a
     * support ticket rather than as a test failure.
     *
     * The `CASE` is the whole of the rule. A row explicitly charged under the new
     * one contributes our commission alone; **everything else** — a row on the
     * business's own keys, a row written before the column existed, a value
     * nobody anticipated — contributes both fees, exactly as it always did.
     * Failing towards the old rule is the only safe direction here: it can
     * overstate a deduction a historical sale genuinely had, where the other way
     * would tell a business it was owed money nobody had taken.
     *
     * Qualifies its columns, because this string is pasted into queries on other
     * tables' behalf and an unqualified `fee_kobo` would be ambiguous the first
     * time anything joins to a table that has one.
     */
    public static function processingFeeSumSql(): string
    {
        return sprintf(
            "COALESCE(SUM(CASE WHEN transactions.provider_fee_bearer = '%s' "
            .'THEN COALESCE(transactions.platform_fee_kobo, 0) '
            .'ELSE COALESCE(transactions.fee_kobo, 0) + COALESCE(transactions.platform_fee_kobo, 0) END), 0)',
            ProviderFeeBearer::PLATFORM->value,
        );
    }

    /**
     * What actually reached the business's bank, in kobo.
     *
     * Derived rather than read from `net_kobo`, and that is deliberate. The
     * column was written by code that subtracted only the provider's fee, so for
     * a managed sale it overstates the money by our commission — the exact
     * discrepancy this change exists to remove. Deriving means the figure is
     * right as soon as this code ships, while the column catches up in the
     * backfill. Both are computed by {@see netKoboFor()}, so they cannot
     * disagree once it has run.
     *
     * Null when the provider's fee is unknown and it is needed to answer — see
     * {@see processingFeeKobo()}. A partial subtraction is a wrong number; an
     * absent one is an honest gap. Only a business-bearing sale can have that
     * gap: Storify's own fee never bounds what a business is owed.
     */
    public function netKobo(): ?int
    {
        return self::netKoboFor(
            Naira::koboFromRounded($this->amount),
            $this->fee_kobo !== null ? (int) $this->fee_kobo : null,
            $this->platformFeeKobo(),
            $this->providerFeeBearer(),
        );
    }

    /**
     * The one place the net is worked out.
     *
     * Static and taking its inputs as arguments because the writers need it
     * *before* the row carries these values: they are computing what to persist,
     * not reading what was persisted. Four call sites used to carry their own
     * copy of `max(0, $amount - $fee)` and every one of them silently omitted the
     * commission, which is why it lives in one place now.
     *
     * `$bearer` is required rather than defaulted, deliberately. Defaulting it
     * would let a call site that nobody updated go on applying the old rule —
     * understating a business's share by the provider's fee, on every sale, with
     * no error to notice. Required makes a missed site loud.
     */
    public static function netKoboFor(
        int $grossKobo,
        ?int $providerFeeKobo,
        int $platformFeeKobo,
        ProviderFeeBearer $bearer,
    ): ?int {
        // Storify bears the provider's fee, so it is not part of the business's
        // share — and, checked before the null guard below, an unreported fee is
        // no reason to refuse an answer. That ordering is what lets a managed
        // sale be credited the moment it is paid instead of days later.
        if (! $bearer->isBusiness()) {
            return max(0, $grossKobo - max(0, $platformFeeKobo));
        }

        if ($providerFeeKobo === null) {
            return null;
        }

        // Clamped, not trusted: a provider reporting a fee larger than the sale
        // would otherwise produce a negative net and a ledger that does not
        // balance. Only reachable on bad data.
        return max(0, $grossKobo - max(0, $providerFeeKobo) - max(0, $platformFeeKobo));
    }

    /**
     * The provider's own name for the charge behind this payment.
     *
     * Distinct from `gateway_reference`, and both are needed: that column holds
     * the *checkout* id, because a webhook arrives under that name, while the
     * charge id is what a refund is addressed by — sending the checkout id to
     * the refund endpoint asks about something that is not a charge. Bachs
     * reports the charge as `id` on the settlement payload this row stores.
     */
    public function providerChargeId(): ?string
    {
        $raw = $this->gateway_response;
        $chargeId = is_array($raw) ? ($raw['id'] ?? null) : null;

        if (is_string($chargeId) && $chargeId !== '') {
            return $chargeId;
        }

        return $this->gateway_reference ?: null;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status instanceof TransactionStatus ? $this->status->label() : ucfirst($this->status);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->status instanceof TransactionStatus ? $this->status->badgeClass() : 'badge-secondary light';
    }
}
