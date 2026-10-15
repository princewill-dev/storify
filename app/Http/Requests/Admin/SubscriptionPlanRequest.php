<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionPlan;
use App\Observers\SubscriptionPlanObserver;
use App\Support\Money\Naira;
use App\Support\Plans\AnnualPricing;
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

            // What the plan actually grants, keyed by
            // {@see \App\Support\Plans\EntitlementRegistry}. Deliberately
            // validated as a free-form map and then narrowed by the registry in
            // the service rather than enumerated here: enumerating the keys in
            // two places is how a new entitlement ships on the API and is
            // silently dropped by a validator nobody remembered to update.
            //
            // A limit arrives as a number or as null/blank — and blank means
            // unlimited, which is the top tier's defining feature, so it must be
            // accepted rather than treated as missing.
            'entitlements' => ['sometimes', 'nullable', 'array'],
            'entitlements.*' => ['nullable'],

            // The rank a plan change compares on. Not `sort_order`, which is
            // display order, and not `amount`, which cannot compare a yearly
            // plan against a monthly one.
            'tier' => ['sometimes', 'integer', 'min:1', 'max:255'],
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

                $this->refuseTypedAnnualAmount($validator);
            },
        ];
    }

    /**
     * An annual price is computed, never typed.
     *
     * A yearly plan is priced from its monthly twin at the same tier — see
     * {@see AnnualPricing} — and
     * {@see SubscriptionPlanObserver} puts a hand-typed figure
     * back the moment it is saved. Refusing it here is what turns that into
     * something the admin is *told*, rather than an edit that silently does not
     * stick. The observer is still the backstop, because the console is not the
     * only thing that writes a plan.
     */
    private function refuseTypedAnnualAmount(Validator $validator): void
    {
        if ($this->input('interval') !== 'yearly' || $this->boolean('is_trial')) {
            return;
        }

        $monthly = $this->monthlyTwin();

        // No twin to derive from: a yearly plan being created before its
        // monthly row exists, or a tier the ladder does not pair. There is no
        // expected figure, so there is nothing to refuse — the observer will
        // settle it if a twin turns up.
        if ($monthly === null) {
            return;
        }

        $submitted = $this->submittedAmountKobo();

        // No amount in the payload at all. `amount` and `amount_kobo` are
        // `required_without` each other, so this only happens on a payload that
        // has already failed its own rules.
        if ($submitted === null) {
            return;
        }

        if ($submitted === AnnualPricing::annualKobo($monthly->amount)) {
            return;
        }

        $validator->errors()->add(
            'amount',
            'Annual plans are priced automatically — '.AnnualPricing::DISCOUNT_PERCENT
            ."% off twelve months of the monthly plan. Change the {$monthly->name} monthly plan's "
            .'price instead and this one follows.',
        );
    }

    /**
     * The monthly plan at the tier this payload would put the plan on.
     *
     * `tier` is `sometimes`, so a partial payload falls back to the tier the
     * plan already sits at — an edit that changes only the name and price must
     * still find the right twin.
     */
    private function monthlyTwin(): ?SubscriptionPlan
    {
        $existing = $this->route('plan');
        $tier = $this->input('tier') ?? ($existing instanceof SubscriptionPlan ? $existing->tier : null);

        if ($tier === null) {
            return null;
        }

        return SubscriptionPlan::query()
            ->where('tier', (int) $tier)
            ->where('is_trial', false)
            ->where('interval', 'monthly')
            ->orderBy('id')
            ->first();
    }

    /**
     * Whatever the payload said the price was, in kobo — or null if it said
     * nothing. `amount_kobo` wins, because it is the house unit and is what the
     * service writes when both are present.
     */
    private function submittedAmountKobo(): ?int
    {
        if ($this->filled('amount_kobo')) {
            return (int) $this->input('amount_kobo');
        }

        if ($this->filled('amount')) {
            return Naira::koboFromStrict((string) $this->input('amount'));
        }

        return null;
    }
}
