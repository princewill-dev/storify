<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — rename-business-type validation.
 *
 * The unique check ignores the row being edited, so a type may keep its own
 * name.
 */
class UpdateBusinessTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('business_types', 'name')->ignore($this->route('businessType')?->id)],
        ];
    }
}
