<?php

namespace App\Http\Requests\Admin;

use App\Models\PayoutHold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AD-19 — placing a payout hold: the one write on the admin payout-control surface.
 *
 * The subject depends on the scope and nothing else may be sent: `system` takes no
 * subject at all, `business` takes a business, `store` takes a store. `prohibited`
 * rather than "ignored", because a `business_id` sent with `scope: system` is a
 * caller who believes they are holding one business, and silently holding every
 * business on the platform instead is the worst possible way to find out.
 *
 * The reason is required and long-form, the same contract as the suspend/activate
 * modals: a hold with no reason is indistinguishable from a bug, and a business
 * that asks why its money stopped deserves an answer on the record.
 */
class PlacePayoutHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard order
        // (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $scope = $this->input('scope');

        return [
            'scope' => ['required', 'string', Rule::in([
                PayoutHold::SCOPE_SYSTEM,
                PayoutHold::SCOPE_BUSINESS,
                PayoutHold::SCOPE_STORE,
            ])],

            'business_id' => [
                'integer',
                'exists:businesses,id',
                Rule::requiredIf($scope === PayoutHold::SCOPE_BUSINESS),
                Rule::prohibitedIf($scope !== PayoutHold::SCOPE_BUSINESS),
            ],

            'store_id' => [
                'integer',
                'exists:stores,id',
                Rule::requiredIf($scope === PayoutHold::SCOPE_STORE),
                Rule::prohibitedIf($scope !== PayoutHold::SCOPE_STORE),
            ],

            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_id.required' => 'A business-scope hold needs the business it applies to.',
            'store_id.required' => 'A store-scope hold needs the store it applies to.',
            'business_id.prohibited' => 'A business id belongs to a business-scope hold, and this is not one.',
            'store_id.prohibited' => 'A store id belongs to a store-scope hold, and this is not one.',
        ];
    }
}
