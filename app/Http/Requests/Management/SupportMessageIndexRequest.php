<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-33 — the support inbox list filters.
 *
 * The rules are the controller's inline `$request->validate([...])` set moved
 * verbatim, including the `STATUSES` whitelist const that lived on the
 * controller beside them.
 *
 * The inaccessible-store filter stays a 422 in the controller body, not a
 * validation rule: it is a deliberate anti-id-probing refusal, and it ran
 * after validation in the old body too, so nothing reorders. The route's
 * `permission:support view_tickets` middleware still answers an unauthorised
 * caller before validation runs.
 */
class SupportMessageIndexRequest extends FormRequest
{
    /** The statuses the inbox filter accepts. */
    private const STATUSES = ['pending', 'replied', 'closed'];

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
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
