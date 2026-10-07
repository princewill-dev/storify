<?php

namespace App\Http\Requests\Management\Subscription;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The payload both plan-selection endpoints carry: the id of the plan the
 * business chose.
 *
 * Whether the plan is *selectable* (active, non-trial) is deliberately not a
 * validation rule — PlanController answers that with 422 "Invalid plan
 * selection.", the same message the endpoint has always used, so validation
 * cannot become a catalogue-probing oracle. The subscription and
 * email-verification guards likewise stay in the controller body, not in
 * authorize().
 */
class PlanSelectionRequest extends FormRequest
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
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ];
    }
}
