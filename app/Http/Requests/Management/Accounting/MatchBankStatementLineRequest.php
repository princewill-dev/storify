<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-37 — manual match payload. The ledger line itself is looked up and
 * tenant-checked in the controller; a missing or non-numeric id is a plain
 * validation failure.
 */
final class MatchBankStatementLineRequest extends FormRequest
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
            'journal_line_id' => ['required', 'integer'],
        ];
    }
}
