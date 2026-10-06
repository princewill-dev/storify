<?php

namespace App\Http\Requests\Admin;

use App\Repositories\Admin\UserModerationRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-8 (admin console) — user directory filter validation.
 *
 * The legacy filter set, validated rather than passed through: the role list
 * the console manages, the status/verified/has-business/subscription shapes
 * and a sort whitelist so a request-supplied column never reaches orderBy
 * (admin roadmap §3.3). `role=all` (or an empty role) is the explicit "every
 * managed role" option the previous SPA silently used.
 */
class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the managed-role 404, and neither belongs in
        // FormRequest::authorize() or middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in([...UserModerationRepository::MANAGED_ROLES, 'all'])],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted', 'pending'])],
            'verified' => ['nullable', Rule::in(['0', '1', 'yes', 'no'])],
            'has_business' => ['nullable', Rule::in(['yes', 'no'])],
            'subscription' => ['nullable', Rule::in(['active', 'trial', 'none'])],
            'sort' => ['nullable', Rule::in(UserModerationRepository::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
