<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-35 — the web-metrics date range.
 *
 * The rule set is a verbatim move from StoreWebMetricsController::show(): the
 * two dates travel as a pair — a half-open range is a client bug, not a
 * silent "since the beginning of time" read.
 *
 * The access guards deliberately stay in the controller body: the store
 * access/deleted 403s and the `no_website` 422 marker are HTTP shape, and the
 * deleted-store check must keep answering 403 for a valid payload from an
 * authorised caller. The repo-wide accepted consequence of this extraction:
 * rules now run during parameter resolution, so a request that is both
 * malformed and unauthorised returns 422 before the controller's guards can
 * answer 403. A valid payload from an unauthorised caller still gets the 403,
 * and route-binding 404 still precedes both.
 */
final class StoreWebMetricsRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ];
    }
}
