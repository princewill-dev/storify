<?php

namespace App\Http\Requests\Management\StorefrontSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Site metadata for a storefront.
 *
 * Partial-update semantics match the rest of the store settings screens: only
 * fields present in the request are written, and an empty string clears a
 * nullable field (ConvertEmptyStringsToNull turns it into null) so the
 * effective value falls back to the store's name/description/logo.
 *
 * The OG image travels as a file on a multipart POST with `_method=PUT`,
 * because PHP does not parse multipart bodies on a real PUT.
 */
final class UpdateStorefrontSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's permission middleware; the store guard stays
        // in the controller so it runs before this payload is validated.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'site_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'og_image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:4096'],
            'remove_og_image' => ['sometimes', 'boolean'],
        ];
    }
}
