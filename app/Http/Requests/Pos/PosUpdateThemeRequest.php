<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS terminal theme preference form.
 *
 * The controller's single rule verbatim; the whole endpoint is the theme
 * update, so no service or repository is involved on this path.
 */
final class PosUpdateThemeRequest extends FormRequest
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
            'theme' => ['required', 'in:light,dark'],
        ];
    }
}
