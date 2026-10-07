<?php

namespace App\Http\Requests\Admin;

use App\Repositories\Admin\ActivityLogRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-1 (admin console) — activity-log viewer filter validation.
 *
 * Both representations of the single route (JSON list and `export=csv`)
 * validate through here; the sort whitelist comes from the repository so a
 * request-supplied column never reaches orderBy.
 */
class ListActivityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of anything the request payload could influence, and
        // it is not an authorization rule over the filters themselves.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(ActivityLogRepository::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'export' => ['nullable', Rule::in(['csv'])],
        ];
    }
}
