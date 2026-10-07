<?php

namespace App\Http\Resources\Storefront;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `user` object of the profile-update response — the controller's inline
 * map moved verbatim: id, account_id, first_name, last_name, email, phone, in
 * that order.
 *
 * Deliberately a subset of Auth\CustomerResource (no name/status/
 * email_verified): the account profile response has only ever carried these
 * six fields, and the storefront SPA reads them verbatim.
 */
final class AccountProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }
}
