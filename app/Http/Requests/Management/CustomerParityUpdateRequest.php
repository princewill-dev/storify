<?php

namespace App\Http\Requests\Management;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-19 — the customer edit payload.
 *
 * The rules are the controller's inline set moved verbatim. The edit form
 * posts every field, so empty values are refused, but a partial payload (the
 * pre-WS-19 API contract) is still accepted and leaves the omitted fields
 * alone.
 *
 * Emails are unique per business in the schema (2026_09_01 migration); the
 * base endpoint still used a global unique rule.
 *
 * Inputs are normalised before the rules run, and only when the key is
 * actually present — a partial update must not have a blank status forced
 * onto it.
 */
class CustomerParityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: it is a 403 the
        // controller owns ahead of the write, not a validation gate.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            $this->merge(['status' => strtolower(trim((string) $this->input('status')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:190'],
            'last_name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('customers', 'email')
                    ->where(fn ($q) => $q->where('business_id', $customer->business_id))
                    ->ignore($customer->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'deleted'])],
        ];
    }
}
