<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the return action's optional reason.
 *
 * The reason is stored on the delivery row and the activity log by the
 * fulfilment service; the 500 character ceiling is preserved from the legacy
 * validation.
 */
final class OrderFulfilmentReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
