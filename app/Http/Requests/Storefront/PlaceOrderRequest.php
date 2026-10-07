<?php

namespace App\Http\Requests\Storefront;

use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The storefront checkout payload (`POST /storefront/{store}/checkout`).
 *
 * The rules were never static: a guest order requires the contact block, a
 * cart holding a physical product requires the shipping block, and everything
 * else is nullable. They are moved here verbatim, still computed from the same
 * server state.
 *
 * The store and cart resolution is reused from ResolvesStorefrontContext
 * rather than re-implemented, so the cart these rules inspect is the exact
 * cart the controller checks out — a second definition of the
 * guest/customer scoping could drift from the one the action actually locks.
 *
 * Rules are skipped while the cart cannot be checked out at all: the
 * controller answers that state with its own 422 ("Your cart is empty.")
 * before it reads the payload, and field errors must not pre-empt that —
 * exactly as the inline validation did not.
 */
final class PlaceOrderRequest extends FormRequest
{
    use ResolvesStorefrontContext;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $store = $this->resolveStore((string) $this->route('store'));
        $cart = $this->resolveCart($store, $this, create: false);

        if (! $cart || $cart->items()->count() === 0) {
            return [];
        }

        $customer = $this->currentCustomer($this);
        $requiresShipping = $cart->items()->whereHas('product', fn ($q) => $q->where('is_digital', false))->exists();

        $rules = [
            'notes' => ['nullable', 'string'],
            'delivery_route_id' => ['nullable', 'integer'],
            'email' => [$customer ? 'nullable' : 'required', 'email', 'max:255'],
            'first_name' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'last_name' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'phone' => [$customer ? 'nullable' : 'required', 'string', 'max:20'],
        ];

        if ($requiresShipping) {
            $rules['street_address'] = ['required', 'string'];
            $rules['state'] = ['required', 'string', 'max:255'];
            $rules['city'] = ['required', 'string', 'max:255'];
        } else {
            $rules['street_address'] = ['nullable', 'string'];
            $rules['state'] = ['nullable', 'string', 'max:255'];
            $rules['city'] = ['nullable', 'string', 'max:255'];
        }

        $rules['apartment'] = ['nullable', 'string', 'max:255'];
        $rules['country'] = ['nullable', 'string', 'max:255'];
        $rules['landmark'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }
}
