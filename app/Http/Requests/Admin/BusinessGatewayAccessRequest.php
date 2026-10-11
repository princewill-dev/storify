<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin's grant of "this business may connect its own payment methods".
 *
 * A request of its own rather than a field on `UpdateBusinessRequest` on
 * purpose: that payload is the business *profile* screen, it is asserted field
 * by field in tests, and it has nothing to say about payments. Folding a
 * payments permission into it would mean every profile edit could silently
 * carry one, and the admin console's payments control would be the same save as
 * its name-and-address form.
 *
 * The flag is required rather than `sometimes`: this endpoint does exactly one
 * thing, so a payload that does not say whether the business may connect its own
 * keys is a caller that has not decided, and answering it would mean guessing.
 */
class BusinessGatewayAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller, like the rest of
        // this workstream: guard order (403 vs route binding and the 422s) is
        // asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'can_connect_own_gateway' => ['required', 'boolean'],
            // Optional, and recorded in the audit row when it is given. The
            // suspend and activate modals have always demanded a reason because
            // they are punitive; this one is an upgrade the admin may be
            // granting without ceremony, so it must not need one.
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
