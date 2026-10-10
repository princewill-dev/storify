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
        ];
    }
}
