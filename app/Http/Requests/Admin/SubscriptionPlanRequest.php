<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-11 (admin console) — the subscription-plan create/edit payload.
 *
 * Create and edit share one contract on purpose: both actions were validated
 * with the same rule set before the extraction (the edit form submits the same
 * fields as create, `name` included — the update is a full replacement, not a
 * patch).
 *
 * Two legacy defects are fixed here rather than cloned:
 *
 *  - the legacy modals rendered `is_trial` / `trial_days` but the validator
 *    dropped them, so the fields never persisted; both are accepted, and
 *    `trial_days` is required whenever `is_trial` is true.
 *  - `amount_kobo` (the house unit) and a decimal `amount` are both accepted
 *    but at least one is required, so a plan can never be created priceless.
 *
 * The cross-field refusal rides `after()` and keeps the controller's exact
 * timing: it only runs once every rule has passed, so a payload that also
 * fails a rule still reports just its own rule errors.
 *
 * The platform-admin guard deliberately stays in the controller — it must not
 * move into FormRequest::authorize() or middleware, so its order relative to
 * route binding is unchanged.
 */
final class SubscriptionPlanRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount_kobo' => ['nullable', 'integer', 'min:0', 'max:9999999999', 'required_without:amount'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2', 'required_without:amount_kobo'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'interval_count' => ['required', 'integer', 'min:1', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_trial' => ['sometimes', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365', Rule::requiredIf(fn () => $this->boolean('is_trial'))],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The controller ran this check only after the whole rule set
                // passed; keep that so a malformed payload still reports just
                // its own rule errors.
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                // A trial-plan template can never be the platform default:
                // every checkout path excludes `is_trial` plans and the
                // early-access redemption action activates the *active
                // default* plan, so a trial default would silently break
                // redemption.
                if ($this->boolean('is_trial') && $this->boolean('is_default')) {
                    $validator->errors()->add('is_default', 'A trial plan cannot be the default plan.');
                }
            },
        ];
    }
}
