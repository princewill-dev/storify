<?php

namespace App\Http\Requests\Management\DeliveryRoute;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-04 — the store delivery-route create/edit payload: the rules
 * StoreDeliveryRouteController carried in its private `validated()` method,
 * verbatim.
 *
 * Create and edit share one class on purpose: both actions called that same
 * method with the same rule set, so two classes would be byte-identical.
 *
 * `fee` is integer kobo here — the SPA form converts the naira it shows
 * before submitting, and checkout divides by 100 when it charges shipping.
 * That is deliberately NOT App\Http\Requests\Admin\DeliveryRouteRequest,
 * whose `fee` is decimal NGN typed on the platform console and converted
 * server-side; the two contracts are not interchangeable.
 *
 * The store guard stays in the controller body on purpose: it is the shared
 * business + reachable-store check whose 403 keeps its place in the refusal
 * order, not a FormRequest::authorize() concern.
 */
class DeliveryRouteRequest extends FormRequest
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
            'country' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'fee' => ['required', 'integer', 'min:0'],
            'delivery_days' => ['required', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
