<?php

namespace App\Http\Requests\Shop4me;

use Illuminate\Foundation\Http\FormRequest;

class Shop4meDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_line' => ['required','string'],
            'landmark' => ['nullable','string'],
            'alt_phone' => ['nullable','string'],
            'map_link' => ['nullable','string'],
        ];
    }
}
