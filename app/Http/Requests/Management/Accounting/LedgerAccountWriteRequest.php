<?php

namespace App\Http\Requests\Management\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-23 — the create/edit chart-of-accounts payload.
 *
 * Create and edit share one contract on purpose: the previous controller
 * validated both actions with the same rule set. The only difference is the
 * code uniqueness check, which ignores the edited account's own row.
 *
 * Rules are scoped to the authenticated user's business, so another
 * business's account cannot be chosen as a parent. The pre-refactor update
 * path passed the bound account's business id, but its tenant guard ran
 * first, so every request that used to reach validate() carried the same id;
 * scoping to the caller's business keeps that behaviour unchanged and keeps
 * the before-guard validation window from probing another business's chart.
 */
abstract class LedgerAccountWriteRequest extends FormRequest
{
    /**
     * Edit requests ignore the bound account in the code unique check.
     */
    abstract protected function forUpdate(): bool;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $businessId = (int) $this->user()->business_id;

        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('ledger_accounts', 'code')
                    ->where(fn ($q) => $q->where('business_id', $businessId))
                    ->ignore($this->forUpdate() ? $this->route('account')?->id : null),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LedgerAccount::TYPES)],
            // Legacy stored a nullable subtype without exposing it in the form;
            // the field stays optional rather than being dropped.
            'subtype' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
