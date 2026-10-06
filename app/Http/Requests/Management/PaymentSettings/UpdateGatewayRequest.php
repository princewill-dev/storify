<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — editing a connected gateway's keys.
 *
 * The secret is write-only: the browser never receives the stored secret, so
 * an empty value means "keep the existing key" and does not trigger the
 * `required` rule.
 */
class UpdateGatewayRequest extends FormRequest
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
            'public_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
