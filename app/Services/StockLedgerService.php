<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockLedgerService
{
    public function recordAddition(
        StockLocation $location,
        int $qty,
        Model $reference,
        ?User $performedBy = null,
        ?string $notes = null
    ): StockMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($location, $qty, $reference, $performedBy, $notes) {
            $lockedLocation = $this->lockLocation($location->id);
            $idempotencyKey = $this->idempotencyKey($reference, $lockedLocation, 'added');

            $existingMovement = $this->findMovement($lockedLocation, $idempotencyKey);
            if ($existingMovement) {
                return $existingMovement;
            }

            $balanceBefore = $lockedLocation->quantity;
            $lockedLocation->quantity = $balanceBefore + $qty;
            $lockedLocation->save();

            return StockMovement::create([
                'business_id' => $lockedLocation->business_id,
                'product_id' => $lockedLocation->product_id,
                'product_variant_id' => $lockedLocation->product_variant_id,
                'stock_location_id' => $lockedLocation->id,
                'to_location_type' => $lockedLocation->locationable_type,
                'to_location_id' => $lockedLocation->locationable_id,
                'quantity' => $qty,
                'balance_before' => $balanceBefore,
                'balance_after' => $lockedLocation->quantity,
                'type' => StockMovementType::ADDED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => $performedBy ? User::class : null,
                'performed_by_id' => $performedBy?->id,
                'idempotency_key' => $idempotencyKey,
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
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($location, $qty, $reference, $performedBy, $notes) {
            $lockedLocation = $this->lockLocation($location->id);
            $idempotencyKey = $this->idempotencyKey($reference, $lockedLocation, 'removed');

            $existingMovement = $this->findMovement($lockedLocation, $idempotencyKey);
            if ($existingMovement) {
                return $existingMovement;
            }

            $balanceBefore = $lockedLocation->quantity;
            if ($balanceBefore < $qty) {
                throw new DomainException('Insufficient stock for this removal.');
            }

            $lockedLocation->quantity = $balanceBefore - $qty;
            $lockedLocation->save();

            return StockMovement::create([
                'business_id' => $lockedLocation->business_id,
                'product_id' => $lockedLocation->product_id,
                'product_variant_id' => $lockedLocation->product_variant_id,
                'stock_location_id' => $lockedLocation->id,
                'from_location_type' => $lockedLocation->locationable_type,
                'from_location_id' => $lockedLocation->locationable_id,
                'quantity' => $qty,
                'balance_before' => $balanceBefore,
                'balance_after' => $lockedLocation->quantity,
                'type' => StockMovementType::REMOVED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => $performedBy ? User::class : null,
                'performed_by_id' => $performedBy?->id,
                'idempotency_key' => $idempotencyKey,
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
        $this->assertPositiveQuantity($qty);

        if ($fromLocation->is($toLocation)) {
            throw new InvalidArgumentException('Source and destination stock locations must differ.');
        }

        if ($fromLocation->business_id !== $toLocation->business_id
            || $fromLocation->product_id !== $toLocation->product_id
            || $fromLocation->product_variant_id !== $toLocation->product_variant_id) {
            throw new InvalidArgumentException('Stock transfers must stay within the same business and product.');
        }

        DB::transaction(function () use ($fromLocation, $toLocation, $qty, $reference, $performedBy, $notes) {
            $lockedLocations = StockLocation::query()
                ->whereIn('id', [$fromLocation->id, $toLocation->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var StockLocation $lockedSource */
            $lockedSource = $lockedLocations->get($fromLocation->id);
            /** @var StockLocation $lockedDestination */
            $lockedDestination = $lockedLocations->get($toLocation->id);

            if (! $lockedSource || ! $lockedDestination) {
                throw new DomainException('A stock location no longer exists.');
            }

            $sharedKey = $this->idempotencyKey($reference, $lockedSource, 'transferred');
            if ($this->findMovement($lockedSource, $sharedKey.'-out')) {
                return;
            }

            $sourceBefore = $lockedSource->quantity;
            $destBefore = $lockedDestination->quantity;

            if ($sourceBefore < $qty) {
                throw new DomainException('Insufficient stock for this transfer.');
            }

            $lockedSource->quantity = $sourceBefore - $qty;
            $lockedDestination->quantity = $destBefore + $qty;
            $lockedSource->save();
            $lockedDestination->save();

            StockMovement::create([
                'business_id' => $lockedSource->business_id,
                'product_id' => $lockedSource->product_id,
                'product_variant_id' => $lockedSource->product_variant_id,
                'stock_location_id' => $lockedSource->id,
                'from_location_type' => $lockedSource->locationable_type,
                'from_location_id' => $lockedSource->locationable_id,
                'to_location_type' => $lockedDestination->locationable_type,
                'to_location_id' => $lockedDestination->locationable_id,
                'quantity' => $qty,
                'balance_before' => $sourceBefore,
                'balance_after' => $lockedSource->quantity,
                'type' => StockMovementType::TRANSFERRED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => User::class,
                'performed_by_id' => $performedBy->id,
                'idempotency_key' => $sharedKey.'-out',
                'notes' => ($notes ? $notes.' — ' : '').'Transfer out to '.($lockedDestination->locationable?->name ?? 'destination'),
            ]);

            StockMovement::create([
                'business_id' => $lockedDestination->business_id,
                'product_id' => $lockedDestination->product_id,
                'product_variant_id' => $lockedDestination->product_variant_id,
                'stock_location_id' => $lockedDestination->id,
                'from_location_type' => $lockedSource->locationable_type,
                'from_location_id' => $lockedSource->locationable_id,
                'to_location_type' => $lockedDestination->locationable_type,
                'to_location_id' => $lockedDestination->locationable_id,
                'quantity' => $qty,
                'balance_before' => $destBefore,
                'balance_after' => $lockedDestination->quantity,
                'type' => StockMovementType::TRANSFERRED->value,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'performed_by_type' => User::class,
                'performed_by_id' => $performedBy->id,
                'idempotency_key' => $sharedKey.'-in',
                'notes' => ($notes ? $notes.' — ' : '').'Transfer in from '.($lockedSource->locationable?->name ?? 'source'),
            ]);
        });
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock movement quantity must be positive.');
        }
    }

    private function lockLocation(int $locationId): StockLocation
    {
        return StockLocation::query()->lockForUpdate()->findOrFail($locationId);
    }

    private function findMovement(StockLocation $location, string $idempotencyKey): ?StockMovement
    {
        return StockMovement::query()
            ->where('business_id', $location->business_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function idempotencyKey(Model $reference, StockLocation $location, string $action): string
    {
        return hash('sha256', get_class($reference).':'.$reference->id.':'.$location->id.':'.$action);
    }
}
