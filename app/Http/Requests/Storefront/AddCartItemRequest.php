<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The add-to-cart payload (`POST /storefront/{store}/cart/items`).
 *
 * The rules — and their order — are exactly the ones the controller validated
 * inline: `product_id` is an integer primary key (the cart does not use the
 * product's `product_code` route key), `qty` is optional and defaults to 1 in
 * the controller, and `variant_key` is the free-form selector the cart line is
 * matched on. Nothing is normalised here, so the controller still clamps qty
 * to at least 1.
 *
 * The endpoint is public — the cart is identified by the X-Guest-Token header
 * or the customer session — so there is no policy to check. Validation now
 * runs during parameter resolution, before the controller's store lookup and
 * cart resolution: a malformed body on an unknown store fails 422 before the
 * 404 it used to get. That ordering shift is the accepted codebase-wide
 * consequence of extracting validation.
 */
class AddCartItemRequest extends FormRequest
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
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'variant_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
