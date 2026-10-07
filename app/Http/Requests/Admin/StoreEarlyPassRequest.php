<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-11 (admin console) — early-pass creation payload.
 *
 * Provenance kept from the controller: **the code is validated after
 * uppercasing.** Laravel runs `prepareForValidation()` before the rules, so
 * the uniqueness check (and the stored value) see the uppercase shape —
 * legacy validated the raw input first, which leaned on the column collation
 * to catch `earlybird` vs `EARLYBIRD`.
 */
final class StoreEarlyPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // not move into FormRequest::authorize() or middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Uppercase before validation so the unique check sees the stored
        // shape ("Codes are handled in uppercase").
        $this->merge(['code' => strtoupper(trim((string) $this->input('code', '')))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:50', Rule::unique('early_passes', 'code')],
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
