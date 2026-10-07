<?php

namespace App\Http\Requests\Management;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The customer edit form.
 *
 * The rules are the controller's inline set, moved verbatim: `sometimes`
 * fields leave omitted values untouched, and the email uniqueness check
 * ignores the row being edited.
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: it is a 403 the
        // controller owns ahead of the write, not a validation gate, and it
        // must not move into middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'first_name' => ['sometimes', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:190'],
        ];
    }
}
