<?php

namespace App\Http\Requests\Management\Accounting;

use App\Models\Bill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-22 bills list filters.
 *
 * No date inputs: the legacy Blade never rendered from/to on the bill list
 * (see mgmt-accounting.verify.md), so none are exposed here either — unknown
 * query parameters such as `from` are simply ignored.
 */
final class IndexBillRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                Bill::STATUS_DRAFT,
                Bill::STATUS_OPEN,
                Bill::STATUS_PARTIAL,
                Bill::STATUS_PAID,
                Bill::STATUS_VOID,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
