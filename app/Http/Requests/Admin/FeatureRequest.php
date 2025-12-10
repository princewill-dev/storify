<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $iconRules = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        if ($this->isMethod('post')) {
            $iconRules[] = 'required';
        } else {
            $iconRules[] = 'nullable';
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'icon' => $iconRules,
            'order' => ['required', 'integer', 'min:0'],
        ];
    }
}
