<?php

namespace App\Http\Requests\Management\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-17 — the drawer count that closes a session, in kobo, plus the optional
 * note and the session code that disambiguates several open drawers.
 */
class ClosePosSessionRequest extends FormRequest
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
            'closing_balance_actual' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'session_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
