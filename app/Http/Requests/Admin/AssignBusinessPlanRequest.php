<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payload for assigning a plan to a business from the admin console.
 *
 * The plan is addressed by `plan_code`, which is the key the whole platform
 * already uses for a plan — it is the route binding on the plan CRUD, it is what
 * a checkout URL carries, and it is what the business console receives. Taking a
 * numeric id here would be the one place that speaks a different language, and
 * it would go stale in a bookmark the moment somebody re-seeded.
 *
 * Only an *active* plan may be assigned. A deactivated plan is one the admin has
 * withdrawn from sale, and handing it to a business would give it entitlements
 * no screen offers and no price list explains — usually by mistake, in a
 * dropdown that still listed it.
 */
final class AssignBusinessPlanRequest extends FormRequest
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
            'plan_code' => [
                'required',
                'string',
                Rule::exists('subscription_plans', 'plan_code')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_code.exists' => 'Select an active plan.',
        ];
    }

    public function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('plan_code', $this->validated('plan_code'))->firstOrFail();
    }
}
