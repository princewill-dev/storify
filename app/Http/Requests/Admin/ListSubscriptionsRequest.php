<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-11 (admin console) — subscription oversight list filters.
 *
 * The filter set the list has always accepted: `q` over the business or plan
 * name, `per_page`, and the status pills. `trial` is accepted alongside the
 * five row statuses because trial-ness never lives on `status` — it is either
 * a plan flagged `is_trial` or a subscription metadata flag — so the filter
 * treats it as a facet of its own and the counts agree with the pills.
 */
final class ListSubscriptionsRequest extends FormRequest
{
    /**
     * The five row statuses plus the derived `trial` facet.
     *
     * @var array<int, string>
     */
    private const STATUSES = ['active', 'pending', 'suspended', 'expired', 'cancelled', 'trial'];

    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 before the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
