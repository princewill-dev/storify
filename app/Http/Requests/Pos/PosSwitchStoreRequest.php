<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal store switch form.
 *
 * The rules are the controller's two rules verbatim. Validation ran before
 * the assignment check there, and the assignment check stays in the
 * controller (it answers 403, not 404, and is a POS-specific scoping rule
 * rather than a request-shape one), so the guard order is unchanged.
 */
final class PosSwitchStoreRequest extends FormRequest
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
            'store_id' => ['required', 'exists:stores,id'],
        ];
    }
}
