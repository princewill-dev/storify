<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'prefix',
        'business_code',
        'description',
        'ownership_type_id',
        'business_type_id',
        'business_model',
        'currency',
        'physical_store_count',
        'store_slug',
        'business_location',
        'status',
        // Whether this business may connect payment methods of its own, or is
        // processed by Storify through the platform's provider account. Set by
        // an admin, never by the business.
        'can_connect_own_gateway',
        // Whether money collected for this business is withdrawn to its
        // registered bank automatically, or held in its wallet until it asks.
        // An admin's decision, and read by `PayoutSweepService`.
        'auto_payout',
        // Whether Storify collects for this business and pays it out, rather than
        // the money going to the business's own account at the provider. An
        // admin's decision, flipped one business at a time.
        'custody_enabled',
        // The bank account this business's wallet is paid out to. The provider's
        // own view of the *payer* is no longer built from this row — nothing at
        // the provider is per business any more — so this is now read only by the
        // payout sweep, which is where a destination is made. See the migration
        // for why it is not `store_banks.is_primary`.
        'payout_bank_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'can_connect_own_gateway' => 'boolean',
            'auto_payout' => 'boolean',
            'custody_enabled' => 'boolean',
            'custody_enabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            if (empty($business->prefix)) {
                $words = explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $business->name));
                $words = array_values(array_filter($words));
                $prefix = count($words) === 1
                    ? strtoupper(substr($words[0], 0, 2))
                    : strtoupper(implode('', array_map(fn ($w) => $w[0] ?? '', array_slice($words, 0, 3))));
                $business->prefix = $prefix ?: 'ST';
            }

            if (empty($business->business_code)) {
                $business->business_code = $business->prefix.'_BIZ_'.Str::upper(Str::random(8));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'business_code';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * The bank account this business's sales are paid into.
     *
     * A relation rather than a balance, and read through `payout_bank_id`: the
     * row is the business's own, listable and editable in the bank accounts
     * screen, and this only names which of them receives the money.
     */
    public function payoutBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class, 'payout_bank_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latestOfMany();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function kycApplications(): HasMany
    {
        return $this->hasMany(KycApplication::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function needsSubscription(): bool
    {
        return ! $this->hasActiveSubscription();
    }

    public function getTotalBalance(): int
    {
        return $this->stores()->sum('balance');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * Everything Storify is holding for this business, settled or not.
     *
     * Derived rather than stored. A third column holding the sum of the other two
     * is a third thing that can disagree with them, and the disagreement would
     * only ever be discovered by a business that was paid the wrong amount.
     */
    public function walletBalance(): int
    {
        return (int) $this->pending_balance + (int) $this->available_balance;
    }

    /**
     * What can actually be paid out right now.
     *
     * Separate from {@see walletBalance()} on purpose: the difference is money the
     * provider has not settled to Storify yet, and paying it out would mean
     * Storify advancing funds it does not have.
     */
    public function payoutableBalance(): int
    {
        return (int) $this->available_balance;
    }

    /**
     * Whether Storify collects this business's money and pays it out.
     *
     * The single predicate the whole change hangs off, and it is read in three
     * places that must agree or money goes to the wrong one of us: the charge path
     * (whose account the money lands in), the settlement path (whether the wallet
     * is credited), and the payout sweep (who gets paid). The provider's account
     * is deleted, so a business that is *not* under custody is one whose own keys
     * take the charge — never one Storify collects for and settles directly.
     *
     * A method rather than a bare column read so the default lives in one place:
     * a stale instance or a `null` from a partial select answers `false`, which
     * is the safe answer.
     */
    public function isUnderCustody(): bool
    {
        return (bool) ($this->custody_enabled ?? false);
    }

    /**
     * Start collecting for this business.
     *
     * Deliberately does not touch the wallet: flipping custody changes whose
     * credentials take the next charge, and the money already collected under the
     * old arrangement is settled where it lies. Doing anything more here would
     * hide that, which is the part that matters.
     */
    public function enableCustody(): bool
    {
        return $this->forceFill([
            'custody_enabled' => true,
            'custody_enabled_at' => now(),
        ])->save();
    }

    /**
     * Stop collecting for this business.
     *
     * The rollback, and no longer a cheap one: with the per-business provider
     * account deleted there is nothing for a business flipped off to fall back to
     * except its own keys, so flipping one off means it can only sell on
     * credentials it holds itself. What it deliberately does **not** do is clear
     * the wallet: a business rolled back after a sale but before its payout is
     * still owed that money, and a zeroed wallet would quietly keep it.
     */
    public function disableCustody(): bool
    {
        return $this->forceFill([
            'custody_enabled' => false,
            'custody_enabled_at' => now(),
        ])->save();
    }

    /**
     * A collected sale lands here: owed, but not yet settled to us.
     */
    public function creditPending(int $amountInKobo): bool
    {
        return $this->mutateWallet('pending_balance', $amountInKobo, false);
    }

    /**
     * Put money straight into the payable bucket.
     *
     * For a payout that was refused: the money was already settled, so returning
     * it to `pending` would make the business wait a second settlement window for
     * a transfer the provider declined. It also has to be able to repair a
     * negative balance left by a refund taken after a payout, which is why it does
     * not refuse to run while available is below zero.
     */
    public function creditAvailable(int $amountInKobo): bool
    {
        return $this->mutateWallet('available_balance', $amountInKobo, true);
    }

    /**
     * Settlement cleared, so the money moved from owed-but-unsettled to payable.
     *
     * Debits pending and credits available in one locked transaction, because a
     * promote that did half the job would either create money or destroy it.
     *
     * @throws \Exception when there is not that much pending — a sign the promote
     *                    job has run twice, which is a bug worth failing loudly on
     *                    rather than quietly absorbing.
     */
    public function promoteToAvailable(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Promote amount must be positive');
        }

        return \DB::transaction(function () use ($amountInKobo) {
            $business = Business::query()->lockForUpdate()->findOrFail($this->id);

            if ((int) $business->pending_balance < $amountInKobo) {
                throw new \Exception('Cannot promote more than is pending');
            }

            $business->decrement('pending_balance', $amountInKobo);
            $business->increment('available_balance', $amountInKobo);
            $business->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * Move money out: a payout reservation, or a refund of settled funds.
     *
     * `$allowNegative` is the difference between the two. A payout must never
     * overdraw — if there is not enough settled money to pay, the right answer is
     * to pay less, not to invent it. A refund must be allowed to: the business
     * already has the cash, Storify has refunded the customer from its own
     * balance, and refusing would leave the debt unrecorded rather than recovered
     * from the business's next sale.
     *
     * @throws \Exception when it would overdraw and is not allowed to.
     */
    public function debitAvailable(int $amountInKobo, bool $allowNegative = false): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Debit amount must be positive');
        }

        return \DB::transaction(function () use ($amountInKobo, $allowNegative) {
            $business = Business::query()->lockForUpdate()->findOrFail($this->id);

            if (! $allowNegative && (int) $business->available_balance < $amountInKobo) {
                throw new \Exception('Insufficient available balance');
            }

            $business->decrement('available_balance', $amountInKobo);
            $business->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * A refund against a sale the provider has not settled to us yet.
     *
     * Debits pending, never below zero: this reverses a specific credit, and that
     * credit is still sitting in pending until it is promoted. If it has already
     * been promoted, the refund belongs in {@see debitAvailable()} instead — the
     * caller knows which, because it knows whether the sale was promoted.
     *
     * @throws \Exception when it would overdraw pending.
     */
    public function debitPending(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Debit amount must be positive');
        }

        return \DB::transaction(function () use ($amountInKobo) {
            $business = Business::query()->lockForUpdate()->findOrFail($this->id);

            if ((int) $business->pending_balance < $amountInKobo) {
                throw new \Exception('Insufficient pending balance');
            }

            $business->decrement('pending_balance', $amountInKobo);
            $business->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * The shared body of a single-bucket credit.
     *
     * Locks the row and re-reads it rather than trusting `$this`, so two workers
     * crediting the same business cannot both read the same starting figure — the
     * same reason `Store` does it this way.
     *
     * @throws \Exception when the amount is not positive, or when the bucket is
     *                    one a credit must never overdraw.
     */
    private function mutateWallet(string $column, int $amountInKobo, bool $allowNegative): bool
    {
        if (! in_array($column, ['pending_balance', 'available_balance'], true)) {
            throw new \Exception('Unknown wallet bucket');
        }

        if ($amountInKobo <= 0) {
            throw new \Exception('Credit amount must be positive');
        }

        return \DB::transaction(function () use ($column, $amountInKobo, $allowNegative) {
            $business = Business::query()->lockForUpdate()->findOrFail($this->id);

            if (! $allowNegative && (int) $business->{$column} < -$amountInKobo) {
                throw new \Exception('Wallet adjustment would overdraw');
            }

            $business->increment($column, $amountInKobo);
            $business->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }
}
