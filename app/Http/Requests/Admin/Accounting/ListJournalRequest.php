<?php

namespace App\Http\Requests\Admin\Accounting;

use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-13 — journal list and CSV export filters (the same filtered row set
 * serves both).
 */
final class ListJournalRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_POSTED, JournalEntry::STATUS_VOID])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];

        // Only compare the range when a lower bound arrived, so `to` alone
        // stays valid — exactly how the controller built the rule before.
        if ($this->filled('from')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        return $rules;
    }
}
