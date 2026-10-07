<?php

namespace App\Http\Requests\Management\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class ApplyEarlyPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
        ];
    }
}
