<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-34 — avatar upload for the signed-in user.
 *
 * The legacy profile screen accepted jpeg/png/jpg/webp up to 2 MB; the same
 * shape is enforced here. The POST route carries ForceJsonResponse so a
 * multipart validation failure returns the {message, errors} 422 instead of
 * redirecting back (302).
 */
class StoreProfilePhotoRequest extends FormRequest
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
            'photo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
