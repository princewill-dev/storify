<?php

namespace App\Http\Resources\Management\Pos;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-17 — one row of the POS nav group: the store identity plus its live open
 * session count.
 */
final class PosStoreRowResource extends JsonResource
{
    public function __construct(Store $store, private readonly int $openSessionsCount)
    {
        parent::__construct($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            ...(new PosStoreResource($store))->toArray($request),
            'open_sessions_count' => $this->openSessionsCount,
        ];
    }
}
