<?php

namespace App\Http\Requests\Admin\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-13 — statement screen filters.
 *
 * `report` accepts the new snake_case keys and the legacy dashed aliases —
 * bookmarked legacy URLs keep working.
 */
final class AccountingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller, so
        // its order relative to route binding and the platform-scope 404s is
        // unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'report' => ['nullable', Rule::in([
                'pnl', 'profit-and-loss', 'profit_and_loss',
                'balance_sheet', 'balance-sheet',
                'trial_balance', 'trial-balance',
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date'],
        ];

        if ($this->filled('from')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        return $rules;
    }

    /**
     * The canonical statement key. The legacy dashed aliases map onto the new
     * snake_case keys, so bookmarked legacy URLs keep working.
     */
    public function reportKey(): string
    {
        return match ($this->validated()['report'] ?? 'pnl') {
            'balance_sheet', 'balance-sheet' => 'balance_sheet',
            'trial_balance', 'trial-balance' => 'trial_balance',
            default => 'pnl',
        };
    }
}
