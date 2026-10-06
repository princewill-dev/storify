<?php

namespace App\Http\Requests\Admin\Accounting;

use App\Services\Accounting\LedgerAccountTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-13 — save the 20 auto-posting mappings.
 *
 * Only platform accounts are acceptable targets — an id from any business's
 * chart is refused by the scoped exists rule, the same way a platform admin
 * has no business id to confuse it with. The key set is checked against the
 * template here, before the controller opens its transaction.
 */
final class UpdateMappingsRequest extends FormRequest
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
        return [
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->whereNull('business_id')],
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
