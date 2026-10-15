<?php

namespace App\Http\Resources\Auth;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer payload embedded by the storefront auth endpoints: `user` in
 * the login/verify-otp envelopes and the sole key of `me`.
 *
 * Field names, types and order are consumed verbatim by the storefront SPA —
 * `email_verified` is derived from `email_verified_at`, everything else is
 * passed through as stored.
 */
final class CustomerResource extends JsonResource
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
            // The business this account belongs to. Carried so the storefront
            // can check the session it is holding is for the store it is
            // rendering — a session that survives a slug change, or a stray
            // one on a shared local origin, would otherwise be shown another
            // store's account. The client checks it; the server does not trust
            // it, and scopes every read by the token's own customer row.
            'business_id' => $customer->business_id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => $customer->status,
            'account_state' => $customer->accountState(),
            'email_verified' => $customer->email_verified_at !== null,
        ];
    }
}
