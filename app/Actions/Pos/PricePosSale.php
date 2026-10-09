<?php

namespace App\Actions\Pos;

use App\Models\Product;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\Vat;
use DomainException;

/**
 * What a till basket costs.
 *
 * Lifted verbatim out of {@see ProcessPosSale}, which had the subtotal, the VAT
 * and the service charge inline. It was lifted because the payment-initialize
 * endpoint has to name a figure to charge the customer's card *before* the
 * order exists, and a second copy of this arithmetic is exactly how a charged
 * amount and a written order total drift apart — after the money has moved.
 *
 * The two callers differ in one respect, hence `$lock`: `ProcessPosSale` runs
 * inside the sale's transaction and must hold the product rows while it prices
 * and then de-stocks them; the quote path only reads, and must not take write
 * locks on the strength of a request that may never become a sale.
 */
final class PricePosSale
{
    /**
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     *
     * @throws DomainException when a product is not sold by this store
     */
    public function execute(Store $store, array $items, ?int $serviceChargeId = null, bool $lock = false): PosSaleQuote
    {
        // Lines for the same product are added together rather than treated as
        // separate lines, so a basket that scanned one item twice prices the
        // same as one that scanned it once with twice the quantity.
        $lines = collect($items)
            ->groupBy('product_id')
            ->map(fn ($group, $productId): array => [
                'product_id' => (int) $productId,
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->values();

        $query = Product::query()
            ->where('store_id', $store->id)
            ->where('business_id', $store->business_id)
            ->whereIn('id', $lines->pluck('product_id'));

        if ($lock) {
            $query->lockForUpdate();
        }

        $products = $query->get()->keyBy('id');

        if ($products->count() !== $lines->count()) {
            throw new DomainException('One or more products are unavailable in this store.');
        }

        $vatPercentage = (float) (Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first()?->percentage ?? 0);

        $subtotal = 0.0;
        $tax = 0.0;
        $priced = [];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);
            $unitPrice = (float) $product->amount;
            $lineSubtotal = $unitPrice * $line['quantity'];
            $subtotal += $lineSubtotal;

            $lineTax = 0.0;
            if ($vatPercentage > 0 && $product->is_taxable) {
                $lineTax = round($lineSubtotal * $vatPercentage / 100, 2);
                $tax += $lineTax;
            }

            $priced[] = [
                'product' => $product,
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'tax_rate' => $product->is_taxable ? $vatPercentage : 0,
                'tax_amount' => $lineTax,
            ];
        }

        $serviceCharge = $serviceChargeId
            ? ServiceCharge::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->find($serviceChargeId)
            : null;

        $serviceChargeAmount = (float) ($serviceCharge?->amount ?? 0);

        return new PosSaleQuote(
            subtotal: $subtotal,
            tax: $tax,
            serviceChargeAmount: $serviceChargeAmount,
            serviceCharge: $serviceCharge,
            total: round($subtotal + $serviceChargeAmount + $tax, 2),
            vatPercentage: $vatPercentage,
            lines: $priced,
        );
    }
}
