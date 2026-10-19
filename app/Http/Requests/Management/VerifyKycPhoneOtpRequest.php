<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class VerifyKycPhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+[0-9]{1,4}$/'],
            'phone_number' => ['required', 'string', 'max:50'],
            'code' => ['required', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_country_code.regex' => 'The dialling code must look like +234.',
            'phone_number.required' => 'Enter the phone number you want to verify.',
            'code.required' => 'Enter the 6-digit code we sent you.',
            'code.digits' => 'The code is 6 digits.',
        ];
    }

    public function dialCode(): string
    {
        return $this->validated()['phone_country_code'] ?? SubmitKycRequest::DEFAULT_DIAL_CODE;
    }
}
