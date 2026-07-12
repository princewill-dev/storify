<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    public function recordAddition(
        StockLocation $location,
        int $qty,
        Model $reference,
        ?User $performedBy = null,
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use ($location, $qty, $reference, $performedBy, $notes) {
            $location->lockForUpdate();

            $balanceBefore = $location->quantity;
            $location->increment('quantity', $qty);
            $location->refresh();

            return StockMovement::create([
                'business_id' => $location->business_id,
                'product_id' => $location->product_id,
                'product_variant_id' => $location->product_variant_id,
                'stock_location_id' => $location->id,
                'to_location_type' => $location->locationable_type,
                'to_location_id' => $location->locationable_id,
                'quantity' => $qty,
                'balance_before' => $balanceBefore,
                'balance_after' => $location->quantity,
                'type' => StockMovementType::ADDED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => $performedBy ? User::class : null,
                'performed_by_id' => $performedBy?->id,
                'idempotency_key' => $this->idempotencyKey($reference, $location, 'added'),
                'notes' => $notes,
            ]);
        });
    }

    public function recordRemoval(
        StockLocation $location,
        int $qty,
        Model $reference,
        ?User $performedBy = null,
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use ($location, $qty, $reference, $performedBy, $notes) {
            $location->lockForUpdate();

            $balanceBefore = $location->quantity;
            $location->decrement('quantity', $qty);
            $location->refresh();

            return StockMovement::create([
                'business_id' => $location->business_id,
                'product_id' => $location->product_id,
                'product_variant_id' => $location->product_variant_id,
                'stock_location_id' => $location->id,
                'from_location_type' => $location->locationable_type,
                'from_location_id' => $location->locationable_id,
                'quantity' => $qty,
                'balance_before' => $balanceBefore,
                'balance_after' => $location->quantity,
                'type' => StockMovementType::REMOVED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => $performedBy ? User::class : null,
                'performed_by_id' => $performedBy?->id,
                'idempotency_key' => $this->idempotencyKey($reference, $location, 'removed'),
                'notes' => $notes,
            ]);
        });
    }

    public function recordTransfer(
        StockLocation $fromLocation,
        StockLocation $toLocation,
        int $qty,
        Model $reference,
        User $performedBy,
        ?string $notes = null
    ): void {
        DB::transaction(function () use ($fromLocation, $toLocation, $qty, $reference, $performedBy, $notes) {
            $fromLocation->lockForUpdate();
            $toLocation->lockForUpdate();

            $sourceBefore = $fromLocation->quantity;
            $destBefore = $toLocation->quantity;

            $fromLocation->decrement('quantity', $qty);
            $toLocation->increment('quantity', $qty);

            $fromLocation->refresh();
            $toLocation->refresh();

            $now = now();
            $sharedKey = $this->idempotencyKey($reference, $fromLocation, 'transferred');

            StockMovement::insert([
                [
                    'business_id' => $fromLocation->business_id,
                    'movement_code' => 'stm_' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
                    'product_id' => $fromLocation->product_id,
                    'product_variant_id' => $fromLocation->product_variant_id,
                    'stock_location_id' => $fromLocation->id,
                    'from_location_type' => $fromLocation->locationable_type,
                    'from_location_id' => $fromLocation->locationable_id,
                    'to_location_type' => $toLocation->locationable_type,
                    'to_location_id' => $toLocation->locationable_id,
                    'quantity' => $qty,
                    'balance_before' => $sourceBefore,
                    'balance_after' => $fromLocation->quantity,
                    'type' => StockMovementType::TRANSFERRED->value,
                    'reference_type' => get_class($reference),
                    'reference_id' => $reference->id,
                    'performed_by_type' => User::class,
                    'performed_by_id' => $performedBy->id,
                    'idempotency_key' => $sharedKey . '-out',
                    'notes' => ($notes ? $notes . ' — ' : '') . 'Transfer out to ' . ($toLocation->locationable?->name ?? 'destination'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'business_id' => $toLocation->business_id,
                    'movement_code' => 'stm_' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
                    'product_id' => $toLocation->product_id,
                    'product_variant_id' => $toLocation->product_variant_id,
                    'stock_location_id' => $toLocation->id,
                    'from_location_type' => $fromLocation->locationable_type,
                    'from_location_id' => $fromLocation->locationable_id,
                    'to_location_type' => $toLocation->locationable_type,
                    'to_location_id' => $toLocation->locationable_id,
                    'quantity' => $qty,
                    'balance_before' => $destBefore,
                    'balance_after' => $toLocation->quantity,
                    'type' => StockMovementType::TRANSFERRED->value,
                    'reference_type' => get_class($reference),
                    'reference_id' => $reference->id,
                    'performed_by_type' => User::class,
                    'performed_by_id' => $performedBy->id,
                    'idempotency_key' => $sharedKey . '-in',
                    'notes' => ($notes ? $notes . ' — ' : '') . 'Transfer in from ' . ($fromLocation->locationable?->name ?? 'source'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }

    private function idempotencyKey(Model $reference, StockLocation $location, string $action): string
    {
        return hash('sha256', get_class($reference) . ':' . $reference->id . ':' . $location->id . ':' . $action);
    }
}
