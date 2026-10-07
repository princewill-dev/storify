<?php

namespace App\Http\Requests\Admin;

use App\Repositories\Admin\DeliveryRouteRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-17 — the platform delivery-route list filters.
 *
 * The sort whitelist is load-bearing: `sort` is handed to `orderBy` inside
 * DeliveryRouteRepository, so only a column from `DeliveryRouteRepository::SORTABLE`
 * survives validation — the same class of bug as the legacy order-index sort
 * injection. A `status` other than active/inactive is rejected rather than
 * silently ignored, and `per_page` carries the usual admin cap.
 */
final class ListDeliveryRoutesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(DeliveryRouteRepository::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
