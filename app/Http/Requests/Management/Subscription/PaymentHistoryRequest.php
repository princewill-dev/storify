<?php

namespace App\Http\Requests\Management\Subscription;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentHistoryRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([Payment::STATUS_SUCCESS, Payment::STATUS_PENDING, Payment::STATUS_FAILED, Payment::STATUS_ABANDONED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
