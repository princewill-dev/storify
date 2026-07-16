<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'company_favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
            'company_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'og_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:2000'],
            'branch_address' => ['nullable', 'string', 'max:2000'],
            'api_keys' => ['nullable', 'array'],
            'api_keys.*' => ['nullable', 'string', 'max:500'],
            'main_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'default_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'store_creation_limit' => ['nullable', 'integer', 'min:1'],
            'trial_enabled' => ['boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
