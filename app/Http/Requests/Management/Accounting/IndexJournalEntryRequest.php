<?php

namespace App\Http\Requests\Management\Accounting;

use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-23 journal list filters.
 *
 * `q` is the free-text search over entry number, reference and memo; the
 * legacy placeholder promised all three (see JournalRepository).
 */
final class IndexJournalEntryRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([
                JournalEntry::STATUS_DRAFT,
                JournalEntry::STATUS_POSTED,
                JournalEntry::STATUS_VOID,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
