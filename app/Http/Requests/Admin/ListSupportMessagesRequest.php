<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-16 (admin console) — the platform support inbox filter set.
 *
 * The legacy page dumped the whole table; this endpoint added search, status/
 * store/date filters, sorting and pagination, and rejects bad values rather
 * than silently ignoring them (an unknown `status` or a `store_id` that does
 * not exist is a 422, asserted by the inbox tests).
 *
 * STATUSES and SORTS are the controller's original constants, moved here with
 * the rules that enforce them: sort columns are whitelisted, never raw
 * `sort_by`, so an unknown column can never reach the query builder.
 */
final class ListSupportMessagesRequest extends FormRequest
{
    public const STATUSES = ['pending', 'replied', 'closed'];

    /**
     * Whitelisted sort columns. Anything else is rejected; an omitted sort
     * falls back to the inbox ordering (pending first, then created_at).
     */
    public const SORTS = ['created_at', 'updated_at', 'id', 'status'];

    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 before the refusals below) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
