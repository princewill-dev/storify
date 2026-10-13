<?php

namespace App\Http\Resources\Management;

use App\Models\Currency;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-02 — everything the store create screen needs to render in one round
 * trip: the owner-derived defaults, the currency list, the business' bank
 * accounts and its active staff.
 *
 * The controller hands over what StoreOnboardingRepository read and the user
 * the defaults are derived from; this resource only shapes it, so the query
 * side and the response side stay separate. Legacy assembled the same data in
 * the controller and passed it to Blade.
 *
 * @property-read array{
 *     staff: Collection<int, User>,
 *     banks: Collection<int, StoreBank>,
 *     currencies: Collection<int, Currency>,
 *     quota: array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}|null,
 * } $resource
 */
final class StoreOnboardingOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     staff: Collection<int, User>,
     *     banks: Collection<int, StoreBank>,
     *     currencies: Collection<int, Currency>,
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
            ],
            'currencies' => collect($this->resource['currencies'])->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
            ])->values()->all(),
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
