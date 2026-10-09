<?php

namespace App\Http\Requests\Pos;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a card charge at the till.
 *
 * Carries the basket, not just the amount. The server prices it with the same
 * arithmetic that will write the order, so the figure the customer is charged
 * and the figure the sale is recorded for come from one place — the alternative
 * is a card charged one amount and an order written for another, after the
 * money has moved.
 *
 * The product rules are `PosCheckoutRequest`'s, tenant-scoped the same way, so
 * a till cannot open a checkout against another business's catalogue.
 */
final class PosPaymentInitializeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->where('business_id', $store->business_id)),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            // Whether this store can actually take this method is a question
            // for the gateway resolver, which answers with a better message
            // than a validation rule could.
            'method' => ['required', 'string', 'max:64'],

            // What the cashier intends to put on the card. Never more than the
            // quoted total — a split payment for less is legitimate.
            'amount' => ['required', 'numeric', 'min:0.01'],

            'service_charge_id' => [
                'nullable',
                Rule::exists('service_charges', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true)),
            ],

            // Optional, and only checked when the staff member has one — the
            // same conditional `CheckoutController` applies. A hard `size:6`
            // rule here would lock out everyone who has never set a PIN and is
            // let through today.
            'pin' => ['nullable', 'string', 'size:6'],

            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
