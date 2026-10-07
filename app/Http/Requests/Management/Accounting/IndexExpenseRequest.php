<?php

namespace App\Http\Requests\Management\Accounting;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-16 expenses list filters (GET accounting/expenses).
 *
 * `category` and `category_id` are both accepted — legacy links used the
 * former, the current form the latter — and both map to the same strict
 * expense_category_id match.
 */
final class IndexExpenseRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([
                Expense::STATUS_DRAFT,
                Expense::STATUS_APPROVED,
                Expense::STATUS_PAID,
                Expense::STATUS_VOID,
            ])],
            'category' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
