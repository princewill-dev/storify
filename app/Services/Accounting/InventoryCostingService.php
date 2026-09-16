<?php

namespace App\Services\Accounting;

use App\Models\Product;

class InventoryCostingService
{
    /**
     * Weighted-average unit cost in kobo for a product.
     */
    public function unitCostKobo(Product $product): int
    {
        if ((int) $product->average_cost_kobo > 0) {
            return (int) $product->average_cost_kobo;
        }

        if ($product->cost_price !== null) {
            return (int) round(((float) $product->cost_price) * 100);
        }

        return 0;
    }

    /**
     * Cost of selling the given quantity in kobo.
     */
    public function costForSale(Product $product, int $quantity): int
    {
        return $this->unitCostKobo($product) * max(0, $quantity);
    }

    /**
     * Update the running weighted-average cost when stock is received.
     *
     * @param  int  $quantityReceived  Units received
     * @param  int  $unitCostKobo  Cost per unit in kobo
     */
    public function recordReceipt(Product $product, int $quantityReceived, int $unitCostKobo): void
    {
        if ($quantityReceived <= 0 || $unitCostKobo < 0) {
            return;
        }

        $onHandBefore = (int) \App\Models\StockLocation::query()
            ->where('product_id', $product->id)
            ->sum('quantity');

        $existingValue = $onHandBefore * (int) ($product->average_cost_kobo ?? 0);
        $receivedValue = $quantityReceived * $unitCostKobo;
        $newQuantity = $onHandBefore + $quantityReceived;

        $newAverage = $newQuantity > 0
            ? (int) round(($existingValue + $receivedValue) / $newQuantity)
            : $unitCostKobo;

        $product->update([
            'average_cost_kobo' => $newAverage,
            'cost_price' => round($unitCostKobo / 100, 2),
        ]);
    }

    /**
     * Recalculate the weighted average from current on-hand quantity and a new cost price.
     */
    public function syncFromCostPrice(Product $product): void
    {
        if ($product->cost_price === null) {
            return;
        }

        $product->update([
            'average_cost_kobo' => (int) round(((float) $product->cost_price) * 100),
        ]);
    }
}
