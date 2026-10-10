<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking whether a card charge has landed yet.
 *
 * The method rides along because a reference on its own does not say who issued
 * it — the store's connection is what the provider is asked through, and
 * asking the wrong provider about someone else's reference is not a question
 * that has an answer. The amount rides along so the answer is "paid the amount
 * this sale is for", not merely "paid".
 */
final class PosPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:255'],
            // The id the provider issued for this payment, echoed back from
            // `initialize` when it gave us one other than our own reference.
            // Absent for every provider but Bitfra.
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // What the provider was asked to charge, in its own minor units,
            // echoed back from `initialize`. Sent whenever the till has one —
            // for a provider charging in the store's own currency it equals the
            // amount above, and for one charging in another it is the only
            // figure that can be compared against what the provider reports.
            'charge_amount' => ['nullable', 'integer', 'min:0'],
            'charge_currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
