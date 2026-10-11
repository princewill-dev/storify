<?php

namespace App\Http\Requests\Management\PaymentGateways;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payout-account form a business fills in when Storify processes its
 * payments.
 *
 * Two ways to name a bank, and exactly one of them is required. A business that
 * has already registered an account for manual bank transfers picks it by
 * `store_bank_id` — one unambiguous resolution, and the account number is
 * already verified through Paystack — while a business with none types the
 * details. Accepting both at once would leave the server guessing which one was
 * meant, and guessing wrong sends a stranger's money to the wrong bank.
 *
 * `entity_type` is required and has no default. It is **immutable** at the
 * provider and decides which documents will be demanded, so it is a decision the
 * business makes deliberately rather than a value we assume; assuming "company"
 * for a sole trader would put them through a registration-number requirement
 * they can never satisfy.
 *
 * `terms_accepted` is the owner's acceptance of Bachs' terms, given here and
 * submitted by us as `tos_acceptance`. It is required rather than defaulted
 * because it is a contractual act: the provider holds the business to it, so it
 * has to be something the owner actually did, on a form that names what they
 * were accepting. The timestamp and the IP we submit are recorded from this
 * request at the moment the box was ticked, not invented at submission time.
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
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'entity_type' => ['required', Rule::in(['company', 'individual'])],

            // The owner's own act, not ours. `accepted` rather than `boolean`
            // so this cannot be satisfied by a form field that happens to be
            // truthy, and it holds on a re-registration too — the acceptance is
            // resubmitted with the destination, so the record stays current.
            'terms_accepted' => ['required', 'accepted'],

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
            'entity_type.required' => 'Tell us whether this business is registered as a company or owned by an individual.',
            'terms_accepted.accepted' => 'Accept Bachs\' terms to register the account your sales will be paid into.',
            'terms_accepted.required' => 'Accept Bachs\' terms to register the account your sales will be paid into.',
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
