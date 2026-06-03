<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

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
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'support_email' => ['nullable', 'email', 'max:255', 'unique:stores,support_email'],
            'support_phone' => ['nullable', 'string', 'max:50', 'unique:stores,support_phone'],
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
            'physical_address' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['nullable', 'exists:currencies,id'],
            'business_id' => ['nullable', 'exists:businesses,id'],
            'staff_ids' => ['nullable', 'array'],
        ];
    }
}
