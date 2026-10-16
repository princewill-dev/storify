<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The management console's theme choice.
 *
 * `system` is in the closed set on purpose. The two-state version this replaces
 * — the till's, in `PosUpdateThemeRequest` — cannot express "I have not chosen",
 * and a reader who has not chosen should get their machine's preference rather
 * than the app's.
 */
class UpdateThemeRequest extends FormRequest
{
    /** The three positions, and the only ones the console can render. */
    public const THEMES = ['light', 'dark', 'system'];

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
            'theme' => ['required', 'string', Rule::in(self::THEMES)],
        ];
    }
}
