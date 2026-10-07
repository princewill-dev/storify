<?php

namespace App\Http\Requests\Management\Accounting;

use App\Services\Accounting\AccountingSettingsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-37 — the one-time opening balance form.
 *
 * The field set is the service's debit/credit key lists: debit keys post
 * positive, credit keys travel to the posting service as negatives. Every
 * amount is an unsigned kobo integer, so the one-time entry can never be off
 * by a rounding cent.
 */
final class StoreOpeningBalancesRequest extends FormRequest
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
        $rules = [
            'as_of' => ['required', 'date'],
        ];

        foreach ([...AccountingSettingsService::DEBIT_KEYS, ...AccountingSettingsService::CREDIT_KEYS] as $key) {
            $rules[$key.'_kobo'] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }
}
