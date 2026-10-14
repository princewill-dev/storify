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
 *
 * ## `start_trial` is what separates choosing a plan from claiming a trial
 *
 * These used to be the same act, which was harmless while plan selection *was*
 * the trial question: the only reason to pick a plan was to start the free
 * one. The onboarding plan page makes them peers — pay, apply a coupon, or
 * take the trial, all from one screen for one selected plan — and under the
 * old behaviour that screen would have burned the once-only trial the moment
 * somebody clicked a plan on their way to paying for it. A business gets one
 * trial and never another, so that mistake is not recoverable.
 *
 * Omitted means false, and the default is the whole point: the trial has to be
 * *asked for*. The service still holds the once-only guard on top, so a caller
 * that asks twice for a trial it has already had does not get a second one.
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
            'start_trial' => ['nullable', 'boolean'],
        ];
    }
}
