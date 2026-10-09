<?php

namespace App\Actions\Pos;

use App\Models\Product;
use App\Models\ServiceCharge;

/**
 * What a POS cart costs, before any of it is written down.
 *
 * `PricePosSale` produces this and two callers read it: `ProcessPosSale` turns
 * it into the order, and the till's payment-initialize endpoint uses `total` to
 * tell the cashier what to charge the customer's card. That second caller is
 * the reason this exists — the amount sent to a gateway and the amount the
 * order is finally written for must come from one piece of arithmetic, not two.
 *
 * Amounts are naira floats, matching the `orders` columns and the
 * `payments.*.amount` values the till already sends.
 */
final readonly class PosSaleQuote
{
    /**
     * @param  array<int, array{
     *     product: Product,
     *     quantity: int,
     *     unit_price: float,
     *     subtotal: float,
     *     tax_rate: float,
     *     tax_amount: float,
     * }>  $lines
     */
    public function __construct(
        public float $subtotal,
        public float $tax,
        public float $serviceChargeAmount,
        public ?ServiceCharge $serviceCharge,
        public float $total,
        public float $vatPercentage,
        public array $lines,
    ) {}
}
