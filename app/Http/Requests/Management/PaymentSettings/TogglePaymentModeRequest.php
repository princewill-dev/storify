<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-11 — the per-store Auto (card) / Manual (transfer) mode toggle.
 */
class TogglePaymentModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_mode' => ['required', Rule::in(['auto', 'manual'])],
        ];
    }
}
