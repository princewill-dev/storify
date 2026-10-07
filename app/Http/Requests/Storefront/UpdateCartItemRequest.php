<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The cart-line quantity payload (`PATCH /storefront/{store}/cart/items/{item}`).
 *
 * `required|integer|min:0` is exactly the controller's inline rule: an absent
 * qty still fails 422, and 0 is the delete contract. Note the validated value
 * is passed on without casting — the service compares it strictly, so the int
 * 0 deletes the line while the string "0" writes a zero-quantity line, which
 * is the behaviour the controller had and the reason the value is not coerced.
 *
 * Public endpoint — guest token or customer session — so there is no policy to
 * check here. The 404 that a line must belong to the resolved cart stays in
 * the controller: it is an HTTP status and its order relative to route-model
 * binding is asserted.
 */
class UpdateCartItemRequest extends FormRequest
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
            'qty' => ['required', 'integer', 'min:0'],
        ];
    }
}
