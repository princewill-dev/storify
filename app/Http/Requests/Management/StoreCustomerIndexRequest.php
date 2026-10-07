<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-19 — the store detail "Customers" tab filters.
 *
 * The controller's inline normalisation and rules moved verbatim: payloads
 * speak lowercase statuses across this API while the schema stores the
 * uppercase spelling, so an uppercase (legacy-shaped) client must still work.
 * The status whitelist stays the controller's `active`/`suspended` — the two
 * statuses the tab's rows render — and DELETED remains out of this filter.
 *
 * The store guard stays in the controller on purpose: it is a 403 the
 * controller owns ahead of the read (tests assert the refusal order), not a
 * validation gate. Extracting these rules therefore moves validation ahead of
 * the guard — the known, accepted consequence of the extraction across this
 * codebase: a caller who is both unauthorised and malformed now answers 422
 * where it answered 403. A valid payload from an unauthorised caller still
 * gets 403, and route-binding 404 still precedes both.
 */
final class StoreCustomerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $this->merge(['status' => strtolower(trim((string) $this->input('status')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
