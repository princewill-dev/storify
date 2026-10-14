<?php

namespace App\Http\Requests\Management;

use App\Rules\ReservedStoreSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The unique rule moved out of the controller body: the slug is
            // the live URL, so a taken one must fail validation (422,
            // slug-keyed) rather than surfacing the unique index as a 500.
            // Running in the FormRequest, it now precedes the controller's
            // verified-owner 403 — the known, accepted consequence of the
            // extraction across this codebase: a caller who is both
            // unverified and malformed answers 422. A valid payload from an
            // unverified caller still answers 403, so nothing is escalated.
            'slug' => ['nullable', 'string', 'max:255', new ReservedStoreSlug, Rule::unique('stores', 'slug')],
            'description' => ['nullable', 'string'],
            // Not unique, either of them. A business runs several stores on one
            // support line — the shops are staffed by the same people and the
            // form offers the owner's own number as the default — so a second
            // store reusing it is the ordinary case, and these rules refused it
            // with "The support phone has already been taken."
            //
            // The uniqueness outlived the one-seller-one-store model it was
            // written for, and was enforced here and nowhere else: the store
            // settings form and both admin forms accepted a duplicate and then
            // met the database's unique index, which surfaced as a 500. That
            // index is gone too — see 2026_10_14_000006 — so keeping these
            // rules would only make this path disagree with every other one
            // into the same columns.
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'ownership_type_id' => ['nullable', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'exists:business_types,id'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'bank_id' => ['nullable', 'exists:store_banks,id'],
            'has_website' => ['boolean'],
            'is_physical' => ['boolean'],
            // The till. The column has existed since stores did and the store
            // settings screen has always been able to flip it; what was missing
            // is a way to say it at creation, which is what somebody who
            // answered "Sell in store" at signup is told their store will have.
            // Optional: absent means the column default, so an SPA built before
            // this keeps creating stores exactly as it did.
            'pos_enabled' => ['boolean'],
            'physical_address' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['nullable', 'exists:currencies,id'],
            'business_id' => ['nullable', 'exists:businesses,id'],
            'staff_ids' => ['nullable', 'array'],
        ];
    }
}
