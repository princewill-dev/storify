<?php

namespace App\Http\Requests\Management;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-05 — the storefront overview filters.
 *
 * The rules are the controller's inline `$request->validate([...])` set moved
 * verbatim. The store guards stay in the controller: they are 403/404 refusals
 * whose order matters, not validation gates.
 */
class StorefrontIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'storefront' => ['nullable', Rule::in(['live', 'offline'])],
            'status' => ['nullable', Rule::in([
                Store::STATUS_PENDING,
                Store::STATUS_ACTIVE,
                Store::STATUS_SUSPENDED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
