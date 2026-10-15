<?php

namespace App\Http\Requests\Storefront;

use App\Enums\ConsentType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's own preference toggles
 * (`PUT /api/v1/storefront/account/consents`).
 *
 * A map keyed by consent type — `{"consents": {"marketing": true}}` — because
 * that is what a screen of switches submits: it sends every switch it renders,
 * not only the one that moved, and the same decision arriving twice writes
 * nothing (see ConsentService::record).
 *
 * `array:marketing` is Laravel's allowed-keys rule, so the settable set is the
 * enum's, not a second list that can drift from it. That restriction is what
 * keeps `terms` unsettable from here: it is recorded at registration as a fact,
 * and a customer cannot un-agree to something they had to accept to register.
 */
final class ConsentUpdateRequest extends FormRequest
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
        $settable = ConsentType::settableValues();

        return [
            'consents' => ['required', 'array:'.implode(',', $settable)],
            'consents.*' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'consents.array' => 'Only these preferences can be changed: '.implode(', ', ConsentType::settableValues()).'.',
        ];
    }
}
