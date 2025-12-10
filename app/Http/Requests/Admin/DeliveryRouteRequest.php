<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'country' => ['required','string','max:100'],
            'state' => ['required','string','max:100'],
            'area' => ['required','string','max:150'],
            'fee' => ['required','integer','min:0'],
            'delivery_days' => ['required','integer','min:1','max:60'],
            'active' => ['sometimes','boolean'],
        ];
    }
}
