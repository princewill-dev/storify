<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-02 — the onboarding store-list filters.
 *
 * The controller's inline rules moved verbatim. The list scope (accessible
 * stores, deleted stores hidden by default, the q/status/date filters) lives
 * in StoreOnboardingRepository; access itself is the route's
 * `permission:stores view` middleware, so authorize() stays a plain true.
 */
final class StoreOnboardingIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'suspended', 'deleted'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
