<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal sign-in form.
 *
 * The controller's `merge()` of a trimmed email ran before its validation and
 * moves verbatim into `prepareForValidation()`: a missing email still becomes
 * the empty string (so it fails `required`), and a submitted one is compared
 * after trimming, exactly as before.
 *
 * Validation already ran ahead of the credential/role refusals in the
 * controller body, so extracting it does not reorder any guard: a malformed
 * request and a wrong-role request answer in the same order they always did.
 */
final class PosLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => trim($this->input('email', '')),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
