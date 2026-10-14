<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use App\Support\Stores\StoreModelDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-02 — everything the store create screen needs to render in one round
 * trip: the owner-derived prefills and the business' active staff.
 *
 * The controller hands over what StoreOnboardingRepository read and the user
 * the defaults are derived from; this resource only shapes it, so the query
 * side and the response side stay separate. Legacy assembled the same data in
 * the controller and passed it to Blade.
 *
 * ## Three things this payload stopped carrying
 *
 * **A currency list.** The create form no longer offers one — Storify is the
 * merchant of record and holds one wallet currency, so a per-store choice was
 * the owner being asked to decide something already decided, and the server now
 * writes the platform default itself.
 *
 * **A bank list.** The form no longer offers a payout picker either. Where a
 * store's money lands is a payment setting, and every other payment control
 * lives on the store's own screen; this one was a choice offered before the
 * store had sold anything.
 *
 * **The owner's name, and their location as an address.** Both were prefills
 * for fields that no longer exist — `name` filled the *store's* name with the
 * owner's, and `address` filled a business-address field that asked, for the
 * second time on one screen, for the address the store's own field had been
 * filled from. The write path still defaults `stores.address` to
 * `users.location`, so nothing that reads an address lost one; the form simply
 * stops asking twice.
 *
 * @property-read array{
 *     staff: Collection<int, User>,
 *     quota: array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}|null,
 * } $resource
 */
final class StoreOnboardingOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     staff: Collection<int, User>,
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
                'support_email' => $this->user->email,
                'support_phone' => $this->user->phone,
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
