<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the dispatch action's delivery details.
 *
 * `tracking_number` and `delivery_agent_id` were validated and then dropped
 * by legacy; both are persisted by the fulfilment service now.
 */
final class OrderFulfilmentDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'delivery_notes' => ['nullable', 'string', 'max:500'],
            'estimated_delivery_at' => ['nullable', 'date'],
            'delivery_agent_id' => ['nullable', 'integer'],
        ];
    }
}
