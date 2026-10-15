<?php

namespace App\Http\Requests\Management;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-19 — the customers list filters.
 *
 * Inputs are normalised before the rules run: payloads speak lowercase
 * statuses across this API, and an uppercase (legacy-shaped) client must
 * still work.
 *
 * The status whitelist is deliberate: the business list carried Active/
 * Suspended only; DELETED is an admin-side filter in legacy and stays out of
 * this list.
 */
class CustomerParityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guards stay in the controller on purpose: they are 403s
        // the controller owns ahead of the read (and tests assert their
        // order), not validation gates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $this->merge(['status' => strtolower(trim((string) $this->input('status')))]);
        }

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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            // The derived account states, a second axis rather than a
            // replacement: `status` answers "is this account usable", and this
            // answers "how far through signing up is it". They compose — the
            // storefront sends `account_state`, and `status` stays for the
            // suspend/activate filters that already shipped.
            'account_state' => ['nullable', Rule::in(Customer::ACCOUNT_STATES)],
            'country' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
