<?php

namespace App\Http\Requests\Pos;

use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PosCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        // Whatever this store actually accepts, plus cash, rather than a fixed
        // list. The old `['cash','paystack','transfer']` rejected every provider
        // a business connected beyond the one built in — and `transfer` is not
        // even a catalogue code, it is the POS's older name for `bank_transfer`.
        $allowedMethods = array_values(array_unique(array_merge(
            ['cash'],
            array_keys(app(PaymentGatewayResolver::class)->forStore($store)),
            // Tills in the field still send this; they do not all update at once.
            ['transfer'],
        )));

        return [
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->where('business_id', $store->business_id)),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required_without:payments', 'nullable', Rule::in($allowedMethods)],
            'amount_tendered' => ['nullable', 'integer', 'min:0'],
            'paystack_reference' => ['nullable', 'string'],
            'bank_account_id' => [
                'nullable',
                Rule::exists('store_banks', 'id')->where('business_id', $store->business_id),
            ],
            'payments' => ['required_without:payment_method', 'nullable', 'array', 'min:1'],
            'payments.*.method' => ['required', Rule::in($allowedMethods)],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.amount_tendered' => ['nullable', 'integer', 'min:0'],
            // The reference a hosted checkout issued for this leg, checked
            // against the provider before the sale is written. `paystack_reference`
            // is the older, provider-specific name and still accepted.
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.paystack_reference' => ['nullable', 'string'],
            // The provider's own id for the leg, when it did not take ours.
            'payments.*.provider_reference' => ['nullable', 'string', 'max:255'],
            // The amount the provider was asked to charge for this leg, in its
            // own minor units — echoed from `initialize`. Present only when the
            // till knows it, and required for a leg whose provider charges in a
            // different currency than the store, where nothing else can be
            // compared against the provider's report.
            'payments.*.charge_amount' => ['nullable', 'integer', 'min:0'],
            // The currency that amount is in. Recorded with it so the figure is
            // not a bare integer whose units have to be guessed at.
            'payments.*.charge_currency' => ['nullable', 'string', 'size:3'],
            'payments.*.bank_account_id' => [
                'nullable',
                Rule::exists('store_banks', 'id')->where('business_id', $store->business_id),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'customer_city' => ['nullable', 'string', 'max:100'],
            'customer_state' => ['nullable', 'string', 'max:100'],
            'customer_country' => ['nullable', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'size:6'],
            'service_charge_id' => [
                'nullable',
                Rule::exists('service_charges', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true)),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
