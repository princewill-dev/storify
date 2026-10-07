<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-28 — the dashboard's stateless store switcher filter.
 *
 * The store switcher is stateless: the SPA persists the choice (Pinia +
 * localStorage) and sends `store_id` on every request, and the controller
 * intersects it against the caller's accessible stores. The legacy switch
 * endpoint trusted `exists:stores,id` before its access check; an id outside
 * the circle is a 403 here, so ids can't be probed. The circle check itself
 * stays in the controller (it needs the request's id and must abort), and only
 * the "is this an integer at all" rule lives here.
 */
final class DashboardWidgetsRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer'],
        ];
    }
}
