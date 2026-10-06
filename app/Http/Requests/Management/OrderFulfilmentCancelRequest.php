<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the cancel action's optional reason.
 *
 * The reason is appended to the order notes by the fulfilment service; the
 * 500 character ceiling is preserved from the legacy validation.
 */
final class OrderFulfilmentCancelRequest extends FormRequest
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
