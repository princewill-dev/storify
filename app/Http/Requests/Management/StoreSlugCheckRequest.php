<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-05 — the storefront slug availability check payload.
 *
 * The rules are the controller's inline `$request->validate([...])` set moved
 * verbatim. The 422 (`name`) still comes from the request as it did from the
 * inline call; the slug walk itself is query building and lives in
 * App\Repositories\Management\StorefrontRepository.
 */
class StoreSlugCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'required_without:slug', 'string', 'max:255'],
            'slug' => ['nullable', 'required_without:name', 'string', 'max:255'],
            'ignore_store' => ['nullable', 'string', 'max:64'],
        ];
    }
}
