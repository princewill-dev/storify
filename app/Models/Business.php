<?php

namespace App\Models;

use App\Models\Concerns\HasCustodyWallet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasCustodyWallet;
    use HasFactory;

    /**
     * The answers to "How can we be of help", and the only list of them.
     *
     * Validation, the store form's pre-ticked toggles and the SPA's ordering of
     * the `/welcome` cards all read this constant, so an answer cannot be added
     * on one side of the wire without existing on the other. The stored value
     * for "sell online" is `sell_online` and not `online`, because a store
     * column of that name already means something else.
     *
     * @var list<string>
     */
    public const ONBOARDING_INTENTS = [
        'sell_online',
        'sell_in_store',
        'sell_digital',
        'dropshipping',
        'transfer_existing',
    ];

    /**
     * The default answer, and the one the interests screen arrives with
     * selected: everybody signing up is here to sell something online.
     */
    public const DEFAULT_ONBOARDING_INTENT = 'sell_online';

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
        // What the owner said they were here to do, as a list drawn from
        // self::ONBOARDING_INTENTS. Shapes the experience — the store form's
        // pre-ticked toggles, the order of the `/welcome` cards — and gates
        // nothing: no nav item and no tab is ever hidden by it.
        'onboarding_intents',
        // When the `/welcome` interstitial was dealt with. Null means it has
        // not been, and `next` keeps steering the owner back to it. Per
        // business and not per user: it is a fact about the business's
        // onboarding, and a cashier must neither be shown it nor be able to
        // clear it by loading a page.
        'welcome_seen_at',
        // Whether the business is registered with the CAC, and under what.
        // `is_registered` is nullable on purpose: `false` means the owner was
        // asked and said no, `null` means nobody has asked. Collapsing the two
        // would make every business that predates the column look like it had
        // answered.
        'is_registered',
        'registration_type',
        'registration_number',
        'certificate_path',
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
            'onboarding_intents' => 'array',
            'welcome_seen_at' => 'datetime',
            'is_registered' => 'boolean',
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
}
