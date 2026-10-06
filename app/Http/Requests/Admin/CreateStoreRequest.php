<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-6 (admin console) — admin-provisioned store.
 *
 * The create form's fields plus the logo upload. `business_id` is required and
 * written: legacy unset it before saving while still resolving `user_id` from
 * it, so an admin-created store ended up with `business_id = null` and never
 * appeared under its business — a defect deliberately not cloned.
 */
class CreateStoreRequest extends FormRequest
{
    /**
     * Statuses the create form is allowed to reach. Legacy's create modal
     * offered active/inactive only; `pending`/`suspended`/`deleted` are
     * lifecycle transitions, not creation states.
     */
    public const CREATE_STATUSES = ['active', 'inactive'];

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
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'name' => ['required', 'string', 'max:255'],
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
            'status' => ['required', Rule::in(self::CREATE_STATUSES)],
        ];
    }
}
