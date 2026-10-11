<?php

namespace App\Http\Requests\Management\PaymentGateways;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching a platform-processed connection on or off.
 *
 * One boolean, and it is required. A default would be a default for *stopping*
 * payments or for *starting* them, and neither is a thing to do because a field
 * was left out of a payload — a client that meant to pause and sent nothing
 * would silently keep charging customers.
 *
 * This is deliberately not the same endpoint as the connect form. That one
 * carries credentials and writes a gateway; this one carries a decision about a
 * connection that already exists, and needs no key material at all.
 */
final class SetGatewayActivationRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.required' => 'Say whether this provider should take payments or stop.',
            'is_active.boolean' => 'Say whether this provider should take payments or stop.',
        ];
    }
}
