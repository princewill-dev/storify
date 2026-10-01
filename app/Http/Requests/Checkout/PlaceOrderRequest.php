<?php

namespace App\Http\Requests\Checkout;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = Store::query()
            ->where('slug', $this->route('store_subdomain'))
            ->where('status', Store::STATUS_ACTIVE)
            ->first();
        $guest = ! auth()->guard('customer')->check();
        $requiresShipping = $this->cartRequiresShipping($store);

        return [
            'first_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:255'],
            'last_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:255'],
            'email' => [Rule::requiredIf($guest), 'nullable', 'email', 'max:255'],
            'phone' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:20'],
            'street_address' => [Rule::requiredIf($requiresShipping), 'nullable', 'string'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'state' => [Rule::requiredIf($requiresShipping), 'nullable', 'string', 'max:255'],
            'city' => [Rule::requiredIf($requiresShipping), 'nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'delivery_route_id' => [
                'nullable',
                Rule::exists('delivery_routes', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store?->id ?? 0)
                    ->where('active', true)),
            ],
            'checkout_token' => ['nullable', 'string'],
        ];
    }

    /**
     * Shipping is only required when the cart contains at least one physical item.
     */
    private function cartRequiresShipping(?Store $store): bool
    {
        if (! $store) {
            return true;
        }

        $token = $this->input('checkout_token');
        $customer = auth()->guard('customer')->user();

        $cart = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->when($token, fn ($q) => $q->where('checkout_token', $token))
            ->when(! $token && $customer, fn ($q) => $q->where('user_id', $customer->id)->where('user_type', Customer::class))
            ->when(! $token && ! $customer && $this->cookie('guest_token'), fn ($q) => $q->where('guest_token', $this->cookie('guest_token')))
            ->with('items.product')
            ->latest()
            ->first();

        if (! $cart) {
            return true;
        }

        return $cart->items->contains(fn ($item) => ! (bool) ($item->product?->is_digital));
    }
}
