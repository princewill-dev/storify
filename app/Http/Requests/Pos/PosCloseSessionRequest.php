<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal's "close register" form.
 *
 * The rules are the controller's verbatim: counted cash is integer kobo and
 * required, notes optional up to 500 characters.
 *
 * Extraction note: the "No open session found." 400 used to run before
 * validation; a malformed request with no open register therefore now answers
 * 422 instead of 400 — the extraction-wide, already-accepted reordering. A
 * valid payload still reaches the lookup and gets the same 400 and message
 * when no session is open.
 */
final class PosCloseSessionRequest extends FormRequest
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
            'closing_balance_actual' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
