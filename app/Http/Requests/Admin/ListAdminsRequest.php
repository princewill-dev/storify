<?php

namespace App\Http\Requests\Admin;

use App\Repositories\Admin\AdminAccountRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-10 (admin console) — admin directory filter validation.
 *
 * The legacy list took its filters straight off the request; the sort
 * whitelist (AdminAccountRepository::SORTABLE) is what keeps a
 * request-supplied column away from orderBy (admin roadmap §3.3).
 */
class ListAdminsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['invited', 'active', 'suspended'])],
            'sort' => ['nullable', Rule::in(AdminAccountRepository::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
