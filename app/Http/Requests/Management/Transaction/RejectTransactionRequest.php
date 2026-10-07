<?php

namespace App\Http\Requests\Management\Transaction;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base TransactionController's reject payload — the rules that controller
 * carried inline, verbatim.
 *
 * Deliberately separate from App\Http\Requests\Management\RejectTransactionRequest
 * (the WS-18 slice behind the shared routes, served by TransactionParityController):
 * that one made the reason optional to match the legacy screen, while the base
 * controller required it, and this refactor must not change what the base
 * controller does. The two are not interchangeable.
 */
class RejectTransactionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
