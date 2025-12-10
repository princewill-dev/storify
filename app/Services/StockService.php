<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockService
{
    /**
     * Adjust stock atomically and idempotently.
     * Positive $quantityChange increases stock, negative decreases.
     */
    public function adjustStock(int $storeId, int $productId, int $quantityChange, string $movementType, ?string $reason = null, ?string $idempotencyKey = null, ?int $performedBy = null): InventoryMovement
    {
        return DB::transaction(function () use ($storeId, $productId, $quantityChange, $movementType, $reason, $idempotencyKey, $performedBy) {
            if ($idempotencyKey) {
                $existing = InventoryMovement::where('store_id', $storeId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing; // idempotent
                }
            }

            $stock = InventoryStock::lockForUpdate()->firstOrCreate(
                ['store_id' => $storeId, 'product_id' => $productId],
                ['on_hand' => 0]
            );

            $newOnHand = $stock->on_hand + $quantityChange;
            if ($newOnHand < 0) {
                throw new \RuntimeException('Insufficient stock');
            }
            $stock->on_hand = $newOnHand;
            $stock->save();

            $movement = InventoryMovement::create([
                'store_id' => $storeId,
                'product_id' => $productId,
                'quantity_change' => $quantityChange,
                'movement_type' => $movementType,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'performed_by' => $performedBy,
            ]);

            Log::info('inventory_adjusted', [
                'store_id' => $storeId,
                'product_id' => $productId,
                'qty_change' => $quantityChange,
                'type' => $movementType,
                'movement_id' => $movement->id,
            ]);

            return $movement;
        });
    }
}
