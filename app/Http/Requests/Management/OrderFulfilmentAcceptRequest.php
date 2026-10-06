<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the accept action's payload.
 *
 * Legacy enforced the pending-payment gate with a modal only; the override
 * flag is the server-side escape hatch the controller reads from here.
 */
final class OrderFulfilmentAcceptRequest extends FormRequest
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
            'override_pending_payment' => ['nullable', 'boolean'],
        ];
    }
}
