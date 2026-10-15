<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class SubmitKycRequest extends FormRequest
{
    /**
     * The one country this endpoint is currently written for.
     *
     * Every rule below assumes a Nigerian identity document, and the dialling
     * code is the only place that assumption is written down. A second country
     * means a country field and a document-type lookup that depends on it, not
     * a longer list here.
     */
    public const DEFAULT_DIAL_CODE = '+234';

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // The name, in parts. `legal_name` is not accepted: it is the three
            // joined, written by the controller, so the two representations
            // cannot disagree about what the applicant is called.
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],

            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+[0-9]{1,4}$/'],
            'phone_number' => ['required', 'string', 'max:50'],

            'date_of_birth' => ['required', 'date', 'before:-18 years'],
            'address_line' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:120'],

            'kyc_document_type_id' => ['required', 'integer', 'exists:kyc_document_types,id'],
            'kyc_document_id' => ['required', 'string', 'max:120'],

            'identification_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'selfie_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Enter your first name as it appears on your ID.',
            'last_name.required' => 'Enter your surname as it appears on your ID.',
            'phone_country_code.regex' => 'The dialling code must look like +234.',
            'date_of_birth.before' => 'You must be at least 18 years old to onboard as a business owner.',
            'identification_document.mimes' => 'Identification must be a JPG, PNG, or PDF file.',
            'identification_document.max' => 'Identification file size cannot exceed 5MB.',
            'selfie_image.mimes' => 'Selfie must be a JPG or PNG file.',
            'selfie_image.max' => 'Selfie image size cannot exceed 4MB.',
        ];
    }

    /**
     * The parts joined, as the single field the review queue has always read.
     *
     * Middle names are optional and a missing one must not leave a double space
     * in the middle of somebody's legal name, which is the kind of detail that
     * survives into a certificate.
     */
    public function legalName(): string
    {
        $data = $this->validated();

        $parts = array_filter([
            $data['first_name'] ?? null,
            $data['middle_name'] ?? null,
            $data['last_name'] ?? null,
        ], fn ($part) => filled($part));

        return implode(' ', array_map('trim', $parts));
    }

    public function dialCode(): string
    {
        return $this->validated()['phone_country_code'] ?? self::DEFAULT_DIAL_CODE;
    }
}
