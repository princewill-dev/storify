<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-26 — filters for the dispatches board.
 *
 * Each pair of alias fields is a legacy-shape accommodation: the old search
 * box posted `search` and the filter modal posted `date_from`/`date_to`, while
 * the SPA sends `q`, `from` and `to`. Both spellings resolve to the same
 * filter in DispatchRepository::paginateForUser().
 *
 * The store_id ownership check deliberately stays in the controller, after
 * validation: putting it in authorize() would run it before the rules and
 * change the guard order.
 */
final class DispatchIndexRequest extends FormRequest
{
    /**
     * The eight delivery states the legacy filter modal offered. The column
     * comment on `order_deliveries.status` lists the same set. Moved here with
     * the whitelist it validates; DispatchBoardResource reads the same
     * constant for the filter-modal option list.
     */
    public const STATUSES = [
        'pending',
        'assigned',
        'picked_up',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'failed',
        'returned',
    ];

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
            'q' => ['nullable', 'string', 'max:100'],
            // Legacy's search box posted `search`; accept it as an alias so the
            // endpoint is shape-compatible with old bookmarks and integrations.
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Legacy's filter modal named these date_from/date_to.
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
