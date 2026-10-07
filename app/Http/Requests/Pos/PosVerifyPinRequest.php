<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal PIN switch form: the six-digit PIN only.
 *
 * Validation ran before the PIN resolution in the controller body, and that
 * resolution stays there (own account first, then the other staff accounts,
 * with the 422 on no match), so no guard order changes.
 */
final class PosVerifyPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:6'],
        ];
    }
}
