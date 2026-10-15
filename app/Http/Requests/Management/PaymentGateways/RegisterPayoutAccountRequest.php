<?php

namespace App\Http\Requests\Management\PaymentGateways;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The form a business fills in to say which bank account its money is paid into.
 *
 * Two ways to name a bank, and exactly one of them is required. A business that
 * has already registered an account for manual bank transfers picks it by
 * `store_bank_id` — one unambiguous resolution, and the account number is
 * already verified through Paystack — while a business with none types the
 * details. Accepting both at once would leave the server guessing which one was
 * meant, and guessing wrong sends a stranger's money to the wrong bank.
 *
 * `entity_type` and `terms_accepted` used to be here, both required. They were
 * inputs to opening an account at the provider — the entity type decided which
 * documents would be demanded and was immutable once set, and the acceptance was
 * submitted as the provider's `tos_acceptance`. No account is opened any more, so
 * they were asking the owner to classify their business and agree to a contract
 * for something that no longer happens.
 *
 * The messages name no provider, deliberately. Which one is in force is the
 * platform's decision and not the business's, and this screen otherwise never
 * mentions the provider at all.
 */
final class RegisterPayoutAccountRequest extends FormRequest
{
    /** Nigerian NUBAN account numbers are ten digits. */
    private const ACCOUNT_NUMBER_LENGTH = 10;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            // Scoped to this business. `resolveStore()` checks it again with the
            // tenant guard, but a bare `exists:stores,id` here would let one
            // business name another's store and learn from the *shape* of the
            // refusal that the id was real — a 403 for a store that exists
            // against a 422 for one that does not is a probe.
            'store_id' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where('business_id', (int) $businessId),
            ],

            // An account the business already holds, verified through the
            // bank-transfer flow. Scoped to this business so one tenant's id
            // cannot name another's account.
            'store_bank_id' => [
                'nullable',
                'integer',
                Rule::exists('store_banks', 'id')->where('business_id', (int) $businessId),
            ],

            // Typed details, required only when no stored account was chosen.
            'account_number' => [
                'required_without:store_bank_id',
                'nullable',
                'string',
                'digits:'.self::ACCOUNT_NUMBER_LENGTH,
            ],
            'account_name' => ['required_without:store_bank_id', 'nullable', 'string', 'max:150'],
            'bank_code' => ['required_without:store_bank_id', 'nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_bank_id.exists' => 'That bank account is not one of this business\'s accounts.',
            'account_number.required_without' => 'Enter the account number, or choose a bank account already on file.',
            'account_number.digits' => 'A Nigerian account number is ten digits.',
            'account_name.required_without' => 'Enter the name the bank account is held in.',
            'bank_code.required_without' => 'Choose the bank this account is held with.',
            'account_name.max' => 'The account name cannot be longer than 150 characters.',
            'bank_name.max' => 'The bank name cannot be longer than 150 characters.',
        ];
    }
}
