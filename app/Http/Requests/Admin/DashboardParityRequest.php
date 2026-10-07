<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS7 (admin console) — dashboard-parity filter validation.
 *
 * `days` and `low_stock_threshold` are validated rather than coerced, and a
 * non-scalar `store_id` (`?store_id[]=`) is a 422 rather than a cast warning.
 * The range pills are the new-stack window list; legacy hard-coded a 30-day
 * window.
 *
 * Two guards deliberately stay in the controller: the unknown-store 422
 * (`store_id` accepts either the numeric id or the public store code, and its
 * message string is part of the endpoint's contract) and the from/to ordering
 * 422 (reachable when `from` is in the future and `to` defaults to now). The
 * platform-admin gate stays on the route middleware — guard order is asserted.
 */
class DashboardParityRequest extends FormRequest
{
    /**
     * The new-stack range pills.
     *
     * @var list<int>
     */
    public const DAY_WINDOWS = [7, 30, 90];

    public const MAX_LOW_STOCK_THRESHOLD = 100;

    public function authorize(): bool
    {
        // The platform-admin gate rides the route middleware on purpose:
        // guard order (403 ahead of validation and the inline 422s) is
        // asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'days' => ['nullable', 'integer', Rule::in(self::DAY_WINDOWS)],
            'low_stock_threshold' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LOW_STOCK_THRESHOLD],
            // A scalar only: `?store_id[]=` must be a 422, not a cast warning.
            'store_id' => ['nullable', 'string'],
        ];
    }
}
