<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-9 (admin console) — customer edit validation.
 *
 * The status spelling is normalised before the rules run (payloads speak
 * lowercase, the schema stores uppercase) and the service uppercases the
 * validated value again, so this request never changes what a caller may
 * send, only which casing the rule list sees.
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the refusals below, and neither belongs in
        // FormRequest::authorize() or middleware.
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
            // Emails are unique per business in the schema; the same address
            // may legitimately exist for customers of two different businesses.
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('customers', 'email')
                    ->where(fn ($query) => $query->where('business_id', $customer->business_id))
                    ->ignore($customer->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'deleted'])],
        ];
    }
}
