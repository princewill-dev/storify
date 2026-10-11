<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'balance_updated_at' => 'datetime',
        'status' => TransactionStatus::class,
        'metadata' => 'array',
        'fee_kobo' => 'integer',
        'net_kobo' => 'integer',
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
        $fee = $this->metadata['platform_fee_kobo'] ?? null;

        return is_numeric($fee) ? max(0, (int) $fee) : 0;
    }

    /**
     * The connected account this sale was charged on, when the platform took it
     * on the business's behalf.
     *
     * Recorded at charge time and read back afterwards, for the same reason the
     * mode is: a refund has to be addressed to the account the money came from,
     * and by the time it is asked for the business may have connected keys of its
     * own, or switched its payout account off. Null for a sale charged on the
     * business's own credentials, which is why the caller checks the mode first.
     */
    public function gatewayAccountId(): ?string
    {
        $accountId = $this->metadata['gateway_account_id'] ?? null;

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
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
