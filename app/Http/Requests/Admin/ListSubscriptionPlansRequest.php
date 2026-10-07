<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-11 (admin console) — `GET /api/v1/admin/subscription-plans` filters.
 *
 * The exact rule set the controller validated inline: a `q` search capped at
 * 100 characters, a `status` restricted to active/inactive, and the usual
 * admin `per_page` cap. Everything is nullable — no filters is the normal
 * list call.
 *
 * Authorization stays with the platform-admin guard in the controller (it
 * must not move into FormRequest::authorize() or middleware, so its order is
 * unchanged).
 */
final class ListSubscriptionPlansRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
