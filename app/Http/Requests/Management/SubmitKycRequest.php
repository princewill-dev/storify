<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class SubmitKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:50'],
            'date_of_birth' => ['required', 'date', 'before:-18 years'],
            'address_line' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
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
            'date_of_birth.before' => 'You must be at least 18 years old to onboard as a vendor.',
            'identification_document.mimes' => 'Identification must be a JPG, PNG, or PDF file.',
            'identification_document.max' => 'Identification file size cannot exceed 5MB.',
            'selfie_image.mimes' => 'Selfie must be a JPG or PNG file.',
            'selfie_image.max' => 'Selfie image size cannot exceed 4MB.',
        ];
    }
}
