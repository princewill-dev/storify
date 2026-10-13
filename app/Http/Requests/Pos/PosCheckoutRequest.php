<?php

namespace App\Http\Requests\Pos;

use App\Models\Store;
use App\Support\Payments\SystemProcessor;
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

        // Cash and a transfer into the business's own bank account, and nothing
        // else — see {@see \App\Http\Controllers\Api\V1\Pos\PaymentMethodController}
        // for why the till takes no provider. `transfer` is not a catalogue code,
        // it is the POS client's older name for `bank_transfer`, and tills in the
        // field still send it.
        //
        // This is the list's only server-side enforcement, and it is load-bearing
        // rather than a courtesy. `/checkout` no longer asks a provider whether a
        // leg was paid — the till's word is the record — so for a method the till
        // was never offered, this rule is the one thing standing between a stale
        // or edited client and a written, confirmed sale carrying a reference
        // nobody checked. It is written as a constant rather than resolved from
        // the storefront's catalogue for the same reason: the storefront's list
        // would put card back on it. Widening this reopens that hole.
        //
        // Under an enforced system processor the list is empty, matching the till
        // being shown nothing: neither cash nor a transfer into the business's own
        // account is money the platform sees and can split. Refusing it here as
        // well is the point — a till that is never offered a method but can still
        // post one has the rule on the client.
        $allowedMethods = SystemProcessor::enforced() ? [] : ['cash', 'bank_transfer', 'transfer'];

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
            // The reference a hosted checkout issued for this leg. Required for a
            // leg the catalogue settles by a provider's checkout and refused when
            // absent — but no longer checked against the provider, because no
            // method reaching here has one. `paystack_reference` is the older,
            // provider-specific name and still accepted.
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.paystack_reference' => ['nullable', 'string'],
            // The provider's own id for the leg, when it did not take ours.
            // Recorded on the transaction when a client sends it; nothing here
            // resolves it against a provider any more.
            'payments.*.provider_reference' => ['nullable', 'string', 'max:255'],
            // The amount the provider was asked to charge for this leg, in its
            // own minor units — echoed from `initialize`. Kept in the schema for
            // tills and clients that still send it; recorded on the transaction
            // and compared against nothing on this path.
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
