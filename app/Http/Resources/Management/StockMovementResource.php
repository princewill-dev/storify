<?php

namespace App\Http\Resources\Management;

use App\Enums\StockMovementType;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * WS-29 — one row of the stock-movement ledger: the legacy Activity-tab shape
 * (signed quantity, balances, performed-by) plus the reference and counterpart
 * links the SPA renders.
 *
 * The relations are eager-loaded by StockMovementRepository::paginateForUser(),
 * so shaping here issues no queries of its own. Field order is the response
 * contract and matches the pre-refactor payload exactly.
 */
final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockMovement $movement */
        $movement = $this->resource;
        $location = $movement->stockLocation;
        $type = (string) $movement->type;
        $direction = $this->direction($movement, $location);
        $quantity = (int) $movement->quantity;

        return [
            'id' => $movement->id,
            'movement_code' => $movement->movement_code,
            'type' => $type,
            'type_label' => StockMovementType::tryFrom($type)?->label() ?? Str::headline($type),
            'direction' => $direction,
            'quantity' => $quantity,
            // Legacy printed the raw quantity; signing it makes an OUT row
            // readable at a glance without reading the badge.
            'signed_quantity' => $direction === 'out' ? -$quantity : $quantity,
            'balance_before' => (int) $movement->balance_before,
            'balance_after' => (int) $movement->balance_after,
            'product' => $movement->product ? [
                'id' => $movement->product->id,
                'name' => $movement->product->name,
                'product_code' => $movement->product->product_code,
                'is_digital' => (bool) $movement->product->is_digital,
                'image_url' => $movement->product->primaryImage()?->path
                    ? asset('storage/'.$movement->product->primaryImage()->path)
                    : null,
            ] : null,
            'variant_label' => $movement->productVariant?->variant_code,
            'location' => $this->locationRef($location?->locationable),
            'counterpart' => $type === StockMovement::TYPE_TRANSFERRED
                ? $this->locationRef($direction === 'out' ? $movement->toLocation : $movement->fromLocation)
                : null,
            'reference' => $this->reference($movement),
            'performed_by' => $movement->performedBy ? [
                'id' => $movement->performedBy->id,
                'name' => $movement->performedBy->name,
                'account_code' => $movement->performedBy->account_code,
            ] : null,
            'notes' => $movement->notes,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }

    /**
     * "in"/"out" is derived from the movement's own location against its
     * from/to sides, not from the type: a transfer writes two rows of the same
     * type, one on each side of the move.
     */
    private function direction(StockMovement $movement, ?StockLocation $location): string
    {
        if ($location) {
            $isSource = $movement->from_location_type !== null
                && $movement->from_location_type === $location->locationable_type
                && (int) $movement->from_location_id === (int) $location->locationable_id;

            $isDestination = $movement->to_location_type === $location->locationable_type
                && (int) $movement->to_location_id === (int) $location->locationable_id;

            if ($isSource && ! $isDestination) {
                return 'out';
            }

            if ($isDestination && ! $isSource) {
                return 'in';
            }
        }

        return (string) $movement->type === StockMovement::TYPE_ADDED ? 'in' : 'out';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reference(StockMovement $movement): ?array
    {
        if (! $movement->reference_type) {
            return null;
        }

        $class = class_basename($movement->reference_type);
        $payload = [
            'type' => Str::snake($class),
            'id' => $movement->reference_id,
            'label' => Str::headline($class).' #'.$movement->reference_id,
            'code' => null,
        ];

        if ($movement->reference instanceof StockTransfer) {
            $payload['code'] = $movement->reference->transfer_code;
            $payload['label'] = $movement->reference->transfer_code;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRef(?object $host): ?array
    {
        if ($host instanceof Warehouse) {
            return ['type' => 'warehouse', 'id' => $host->id, 'code' => $host->warehouse_code, 'name' => $host->name];
        }

        if ($host instanceof Store) {
            return ['type' => 'store', 'id' => $host->id, 'code' => $host->store_id, 'name' => $host->name];
        }

        return null;
    }
}
