<?php

namespace App\Http\Requests\Management;

use App\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-18 — legacy list filters (store + date range) for the transactions list
 * and its CSV export.
 */
class TransactionIndexRequest extends FormRequest
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
            // Legacy called the search box "reference"; the SPA already used
            // "q", so both names resolve to the same filter.
            'reference' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(TransactionStatus::values())],
            'store_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
