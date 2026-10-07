<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal's "open register" form.
 *
 * The rule is the controller's verbatim (`required|integer|min:0`): the float
 * is integer kobo, zero allowed.
 *
 * Extraction note: the controller's `pos_enabled` check and the "already
 * open" guard both answer 400 and used to run before `$request->validate()`.
 * Laravel resolves a FormRequest before the controller body, so a request
 * that is both malformed and refused by one of those guards now answers 422
 * instead of 400 — the extraction-wide, already-accepted reordering. A VALID
 * payload still reaches both guards and gets the same 400 and message, and
 * mid-route 403/404 (auth, EnsurePosStoreAccess, route binding) still precede
 * everything.
 */
final class PosOpenSessionRequest extends FormRequest
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
            'opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }
}
