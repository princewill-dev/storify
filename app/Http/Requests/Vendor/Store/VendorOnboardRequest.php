<?php

namespace App\Http\Requests\Vendor\Store;

use Illuminate\Foundation\Http\FormRequest;

class VendorOnboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255', 'unique:stores,support_email'],
            'support_phone' => ['nullable', 'string', 'max:50', 'unique:stores,support_phone'],
            'address' => ['nullable', 'string'],
            'ownership_type_id' => ['nullable', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'exists:business_types,id'],
        ];
    }
}
