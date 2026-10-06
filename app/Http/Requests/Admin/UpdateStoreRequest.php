<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-6 (admin console) — the 16-field store edit.
 *
 * `business_id`/`name`/`status` are `sometimes` (a partial edit must not blank
 * them); the nullable contact/link fields stay replaceable. The status select
 * is restricted on purpose — see `EDITABLE_STATUSES`.
 */
class UpdateStoreRequest extends FormRequest
{
    /**
     * Statuses an edit is allowed to reach. `deleted` is excluded on purpose —
     * deletion has guards, an edit does not (see the controller docblock).
     * `pending` stays selectable so a pending store round-trips through the
     * form unchanged.
     */
    public const EDITABLE_STATUSES = ['active', 'inactive', 'suspended', 'pending'];

    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 before route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'business_id' => ['sometimes', 'required', 'integer', 'exists:businesses,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'status' => ['sometimes', 'required', Rule::in(self::EDITABLE_STATUSES)],
        ];
    }
}
