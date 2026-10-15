<?php

namespace App\Http\Requests\Management;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-56 — the pagination and filter inputs for everything that hangs off one
 * customer (orders, payments, addresses, notes, messages, consents) and for the
 * deletion queue.
 *
 * One request for all of them, deliberately. Every tab takes the same few
 * inputs and nothing else, and six request classes holding an identical
 * `per_page` rule would be six places to forget to raise the cap.
 *
 * The 100 ceiling is the same as the customer list's: it exists so a client
 * cannot ask for the whole book in one response.
 *
 * The filter keys are the customer list's, spelled the same, because the
 * deletion queue is composed by narrowing that list and the Messages composer's
 * audience is resolved from the same filter set. A queue that accepted a filter
 * the list does not is a queue people cannot predict.
 */
class CustomerAccountIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guards stay in the controller: they are 403s the
        // controller owns ahead of the read, not validation gates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('account_state')) {
            $this->merge(['account_state' => strtolower(trim((string) $this->input('account_state')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'account_state' => ['nullable', Rule::in(Customer::ACCOUNT_STATES)],
            'country' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer'],
        ];
    }
}
