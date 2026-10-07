<?php

namespace App\Http\Resources\Management;

use App\Http\Requests\Management\DispatchIndexRequest;
use App\Models\OrderDelivery;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-26 — the dispatches board payload: the filtered rows, the metric cards
 * and the filter-modal option lists the legacy view received from its
 * controller. The stats are business-wide and deliberately independent of the
 * active filters (the pagination meta answers the page count).
 *
 * The rows and stores arrive already scoped and eager-loaded from
 * DispatchRepository, so this resource issues no queries of its own; there is
 * no single model to wrap, hence the explicit pieces.
 */
final class DispatchBoardResource extends JsonResource
{
    /**
     * @param  Collection<int, OrderDelivery>  $dispatches
     * @param  array<string, int>  $stats
     * @param  Collection<int, Store>  $stores
     */
    public function __construct(
        private readonly Collection $dispatches,
        private readonly array $stats,
        private readonly Collection $stores,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dispatches' => $this->dispatches
                ->map(fn (OrderDelivery $delivery) => DispatchResource::make($delivery)->resolve($request))
                ->values()
                ->all(),
            'stats' => $this->stats,
            'stores' => $this->stores
                ->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])
                ->values()
                ->all(),
            'statuses' => array_map(
                fn (string $status) => ['value' => $status, 'label' => DispatchResource::label($status)],
                DispatchIndexRequest::STATUSES,
            ),
        ];
    }
}
