<?php

namespace App\Http\Requests\Storefront;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Confirming a request to have the account erased
 * (`POST /api/v1/storefront/account/delete`).
 *
 * The password is required and checked here, not merely asserted by the client.
 * A bearer token is enough to read an account; it is not enough to ask for it
 * to be erased, or a borrowed phone would be sufficient to destroy somebody's
 * order history. This is the only destructive action on the storefront side, so
 * it is the only one that re-asks for the credential.
 *
 * A `reason` is optional and stored on the audit row. Customers often explain
 * themselves, and a deletion queue that can show why is easier to action than
 * one that shows a timestamp.
 */
final class AccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Customer|null $customer */
            $customer = $this->user();

            if (! $customer || $validator->errors()->has('password')) {
                return;
            }

            if (! Hash::check((string) $this->input('password'), $customer->password)) {
                $validator->errors()->add('password', 'That password is not correct.');
            }
        });
    }
}
