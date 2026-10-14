<?php

namespace App\Http\Resources\Management;

use App\Models\StoreBank;
use App\Models\User;
use App\Support\Stores\StoreModelDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-02 — everything the store create screen needs to render in one round
 * trip: the owner-derived defaults, the business' bank accounts and its active
 * staff.
 *
 * The controller hands over what StoreOnboardingRepository read and the user
 * the defaults are derived from; this resource only shapes it, so the query
 * side and the response side stay separate. Legacy assembled the same data in
 * the controller and passed it to Blade.
 *
 * There is no currency list. The create form no longer offers one — Storify is
 * the merchant of record and holds one wallet currency, so a per-store choice
 * was the owner being asked to decide something already decided, and the
 * server now writes the platform default itself.
 *
 * @property-read array{
 *     staff: Collection<int, User>,
 *     banks: Collection<int, StoreBank>,
 *     quota: array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}|null,
 * } $resource
 */
final class StoreOnboardingOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     staff: Collection<int, User>,
     *     banks: Collection<int, StoreBank>,
     *     quota: array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}|null,
     * }  $options
     */
    public function __construct(private readonly User $user, array $options)
    {
        parent::__construct($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'defaults' => [
                'name' => $this->user->name,
                'support_email' => $this->user->email,
                'support_phone' => $this->user->phone,
                'address' => $this->user->location,
                // The store model the signup answers produce, from the same
                // class the write path uses. The create form no longer offers
                // the three toggles and does not report the model either — it
                // uses this only to stop asking for the things that model says
                // it does not need, and to know whether to name a storefront
                // address.
                //
                // Derived here rather than in the client because a form that
                // describes one store while the server creates another is the
                // exact failure this pairing prevents. The raw `intents` are
                // deliberately not sent alongside: nothing renders them, and
                // the client working out the model for itself is what this
                // replaces.
                'store_model' => StoreModelDefaults::fromIntents($this->user->business?->onboarding_intents),
            ],
            'banks' => collect($this->resource['banks'])->map(fn (StoreBank $bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => (bool) $bank->is_primary,
                'is_verified' => (bool) $bank->is_verified,
            ])->values()->all(),
            'staff' => collect($this->resource['staff'])->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'roles' => $member->roles->pluck('name')->all(),
            ])->values()->all(),
            // What this business is allowed, against what it holds.
            //
            // Sent so the create screen can say "3 of 3 stores" and stop
            // offering a form whose only outcome is a refusal — the same
            // contract the payment screens hold with GatewayGrant. The write
            // path asks the same class, so the two cannot disagree.
            'store_quota' => $this->resource['quota'],
            'main_domain' => config('app.main_domain', parse_url((string) config('app.url'), PHP_URL_HOST)),
            // The subdomain a store is actually published under. Kept separate
            // from main_domain because storefronts live on their own domain.
            'storefront_domain' => config('frontend.storefront_main_domain'),
        ];
    }
}
