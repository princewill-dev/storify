<?php

namespace App\Http\Requests\Management;

use App\Enums\BusinessRegistrationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The registration half of the business step.
 *
 * ## Two states, not one field set
 *
 * `is_registered` is required here even though the column is nullable, because
 * the two answers mean different things and the form is where the owner picks
 * one:
 *
 *  - **false** — not registered. The other three fields are `prohibited`
 *    rather than ignored, so a client that sends them alongside `false` is told
 *    it is confused instead of having them silently dropped. `null` (never
 *    asked) is not an accepted input to this endpoint: you cannot submit the
 *    form without answering the question.
 *  - **true** — registered as something, under some number, with a certificate.
 *
 * ## Why the certificate is not `required` here
 *
 * A certificate is uploaded once and then edited around. Marking it required
 * would mean every later save of, say, the registration number demands the
 * owner find the file again. So the rule is `nullable` at this layer and the
 * controller refuses `is_registered = true` when there is neither an upload nor
 * one already stored — a rule that has to know the current row, which is not
 * something a request class can express.
 */
final class UpdateBusinessRegistrationRequest extends FormRequest
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
            'is_registered' => ['required', 'boolean'],

            'registration_type' => [
                Rule::requiredIf(fn () => $this->boolean('is_registered')),
                'nullable',
                Rule::enum(BusinessRegistrationType::class),
            ],
            'registration_number' => [
                Rule::requiredIf(fn () => $this->boolean('is_registered')),
                'nullable',
                'string',
                'max:64',
            ],

            // PDF and images only, and small. The same shape as the KYC
            // document rule, because it is the same kind of document.
            'certificate' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_registered.required' => 'Tell us whether your business is registered.',
            'registration_type.required' => 'Choose how your business is registered.',
            'registration_number.required' => 'Enter your registration number.',
            'certificate.mimes' => 'The certificate must be a JPG, PNG or PDF file.',
            'certificate.max' => 'The certificate cannot be larger than 5MB.',
        ];
    }
}
