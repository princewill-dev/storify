<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-28 — the dashboard's trend window.
 *
 * `store_id` behaves exactly as it does on `DashboardWidgetsRequest`: an
 * integer or nothing, with the access circle checked in the controller (it
 * needs the id and must abort with a 403, which a rule cannot do). An id
 * outside the circle is therefore a 403, never a 404, so ids can't be probed.
 *
 * `days` is a closed set rather than a free integer. The window is a product
 * decision — three ranges the range control offers — and it is also the
 * number of points the zero-filled series returns. Accepting `days=3650` would
 * be accepting a request to build a 3,650-row response and run the daily
 * GROUP BYs over a decade, from a query string. The set is the guardrail.
 */
final class DashboardTrendRequest extends FormRequest
{
    /**
     * The ranges the dashboard's control offers, and the only ones accepted.
     */
    public const WINDOWS = [7, 30, 90];

    public const DEFAULT_WINDOW = 30;

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
            'store_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', Rule::in(self::WINDOWS)],
        ];
    }

    /**
     * Absent `days` means the middle window, which is what the control opens on.
     */
    public function window(): int
    {
        return $this->filled('days') ? $this->integer('days') : self::DEFAULT_WINDOW;
    }
}
