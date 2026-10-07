<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The per-store storefront support form
 * (`POST /api/v1/storefront/{store}/support`).
 *
 * The rules — and their order — are exactly the ones the controller validated
 * inline: phone is nullable here (unlike the marketing site's contact form,
 * where it is required), the message is capped at 2000 characters, and
 * nothing is normalised. The route's `throttle:3,10` middleware still runs
 * before validation.
 *
 * Public endpoint: the store slug in the path is the only scoping, and the
 * controller resolves it after this request has passed. A malformed payload
 * is therefore answered 422 before an unknown store can 404 — the
 * codebase-wide, already-accepted consequence of extracting validation.
 */
final class SupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public storefront endpoint: anyone may send a support message.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
