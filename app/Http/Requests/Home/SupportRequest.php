<?php

namespace App\Http\Requests\Home;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The marketing site contact form (`POST /api/v1/home/support`).
 *
 * The rules — and their order — are exactly the ones the controller validated
 * inline: phone is required on this form (unlike the storefront support form,
 * where it is nullable), the message has no length cap, and nothing is
 * normalised. The route's `throttle:3,10` middleware still runs before
 * validation.
 */
class SupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public marketing endpoint: anyone may send a support message.
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
            'phone' => ['required', 'string', 'max:20'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ];
    }
}
