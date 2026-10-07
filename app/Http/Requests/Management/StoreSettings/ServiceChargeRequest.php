<?php

namespace App\Http\Requests\Management\StoreSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-04 — the service-charge create/edit payload:
 * StoreSettingsController::validateServiceCharge() moved verbatim. Create and
 * edit share one class on purpose — both actions called that same method with
 * the same rule set, so two classes would be byte-identical.
 *
 * `amount` stays naira-decimal input and is NOT converted here or anywhere in
 * this flow. It is deliberately not a Naira kobo contract: POS checkout
 * (ProcessPosSale) adds this column straight onto naira order totals and the
 * POS read endpoint returns the same unit, so the controller keeps its own
 * `round((float) ..., 2)` exactly as before. Delivery route fees are the kobo
 * side of the same screen.
 */
final class ServiceChargeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
