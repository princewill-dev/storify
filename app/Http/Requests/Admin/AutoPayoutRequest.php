<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin's decision about where a business's settled money goes.
 *
 * Its own request for the same reason `BusinessGatewayAccessRequest` has one:
 * this is a payments control, it lives on the payments screen, and folding it
 * into a profile payload would mean an unrelated save could move it.
 *
 * `auto_payout` is **required**, not `sometimes` and not defaulted. The two
 * answers are not two flavours of the same thing — one sends a business's money
 * to its bank, the other deliberately holds it in the provider account — so a
 * payload that does not say which is a caller that has not decided, and picking
 * for it would either move money nobody asked to move or strand money nobody
 * meant to strand.
 */
class AutoPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller, like the rest of
        // this workstream: guard order against route binding and the 422s is
        // asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'auto_payout' => ['required', 'boolean'],
            // Optional, and recorded in the audit row when given. Switching
            // automatic payouts off is the kind of thing someone asks about
            // later, but demanding a reason would put a form in front of an
            // admin who is simply pausing a payout while a dispute is settled.
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'auto_payout.required' => 'Say whether this business\'s settled money should be withdrawn automatically.',
            'auto_payout.boolean' => 'Automatic payouts are either on or off.',
        ];
    }
}
