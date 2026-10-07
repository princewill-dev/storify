<?php

namespace App\Http\Requests\Management\Accounting;

use App\Services\Accounting\LedgerAccountTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-37 — save the 20 auto-posting mappings.
 *
 * Only this business's accounts are acceptable targets: the `exists` rule is
 * scoped to the caller's business id, so another business's account id fails
 * validation before any write runs. The key set is checked against the
 * template here, before the controller opens its transaction.
 */
final class UpdateMappingsRequest extends FormRequest
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
        // Uncast: a platform admin's null business_id scopes the rule to the
        // platform accounts (business_id IS NULL), matching the pre-refactor
        // rule. Casting to (int) would scope it to business_id = 0 instead.
        $businessId = $this->user()->business_id;

        return [
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
        ];
    }

    /**
     * Legacy wrote a row for whatever key arrived; a typo shadowed the real
     * key and left the event posting to the old default.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The controller ran this check only after every rule passed,
                // so a malformed value still reports just its own rule error.
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                $mappings = $this->input('mappings');

                if (! is_array($mappings)) {
                    return;
                }

                $unknown = array_diff(array_keys($mappings), array_keys(LedgerAccountTemplate::mappings()));

                if (! empty($unknown)) {
                    $validator->errors()->add('mappings', 'Unknown mapping key(s): '.implode(', ', $unknown).'.');
                }
            },
        ];
    }
}
