<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-18 — reject a pending payment.
 */
class RejectTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Legacy made the reason optional — the customer still receives the
     * rejection mail, just without an explanation. Keeping that contract
     * rather than inventing a mandatory field.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
