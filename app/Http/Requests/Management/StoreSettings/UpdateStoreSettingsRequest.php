<?php

namespace App\Http\Requests\Management\StoreSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-04 — the store details, branding and socials payload:
 * StoreSettingsController::update()'s inline rules, moved verbatim.
 *
 * Partial-update semantics are deliberate: only the fields present in the
 * request may be written, so saving the Socials card cannot blank the address
 * the way a full-attribute overwrite would. Sending an empty string clears a
 * nullable field (ConvertEmptyStringsToNull turns it into null) — the
 * controller's attribute loop and the update service keep that contract.
 *
 * The slug rules that depend on the bound store deliberately stay in the
 * controller: the live-storefront refusal (422 with the lock message) runs
 * first and the `ReservedStoreSlug` check runs only while the storefront is
 * offline, so neither belongs in this store-independent rule set — moving
 * them here would change which refusal a live storefront sees.
 *
 * Accepted consequence of the extraction: rules now run during parameter
 * resolution, before the controller body's authorizeStore() guard, so a
 * request that is both unauthorised and malformed is a 422 rather than a 403.
 * A valid payload from an unauthorised caller still gets 403.
 */
final class UpdateStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:stores edit` middleware; the store
        // guard stays in the controller body on purpose.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }
}
