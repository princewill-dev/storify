<?php

namespace App\Http\Requests\Management\StoreSettings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-04 — the store-context staff assignment payload (the counterpart of the
 * staff `store_ids` sync): StoreSettingsController::assignStaff()'s inline
 * rule, moved verbatim.
 *
 * The exists rule is the assignment's only tenancy gate: the user must belong
 * to the acting business, hold the staff role and not be deactivated
 * (status `deleted`, per WS-20's soft deactivation) — deactivated staff are no
 * longer assignable anywhere. It scopes itself through the authenticated
 * caller, so no controller state has to travel with it.
 *
 * Accepted consequence of the extraction: the rule now runs before the
 * controller body's authorizeStore() guard, so a foreign store combined with
 * a malformed payload is a 422 rather than a 403. A valid payload from an
 * unauthorised caller still gets 403.
 */
final class AssignStoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:stores settings` middleware; the
        // store guard stays in the controller body on purpose.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('business_id', (int) $this->user()?->business_id)
                    ->where('role', 'staff')
                    ->where('status', '!=', 'deleted')),
            ],
        ];
    }
}
