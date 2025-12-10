<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'percentage' => ['required','numeric','min:0','max:100'],
            'effective_at' => ['nullable','date'],
            'active' => ['sometimes','boolean'],
        ];
    }
}
