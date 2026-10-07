<?php

namespace App\Http\Requests\Management;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-13 — the order board's legacy list filters.
 *
 * Rules are byte-for-byte the ones the controller validated inline; the
 * source/store/date-range filters are the ones the legacy screens offered.
 */
class OrderIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'store_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
