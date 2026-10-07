<?php

namespace App\Http\Requests\Management\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-17 — the opening float for a cash-register session, in kobo.
 */
class OpenPosSessionRequest extends FormRequest
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
            'opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }
}
