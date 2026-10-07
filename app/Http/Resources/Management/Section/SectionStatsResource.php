<?php

namespace App\Http\Resources\Management\Section;

use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — the five legacy metric cards.
 *
 * Money on the products table is the legacy decimal-naira `amount` column, so
 * the SUM is parsed as a string into integer kobo — no float arithmetic on
 * money. The controller's private converter handled a trailing sign, split on
 * the first dot and read two fraction digits, which is exactly the
 * `Naira::koboFromLenient()` contract; `Naira::decimalFromKobo()` emits the
 * `-N.NN` string the private `koboToNaira()` did. The column is
 * decimal(12,2), so the two agree on every value this surface reads.
 *
 * Field names, order and types are the controller's inline `stats()` verbatim.
 *
 * @property array{amount_total: mixed, products_count: int, active_products_count: int, stock_count: int, out_of_stock_count: int} $resource
 */
final class SectionStatsResource extends JsonResource
{
    /**
     * @param  array{amount_total: mixed, products_count: int, active_products_count: int, stock_count: int, out_of_stock_count: int}  $stats
     */
    public function __construct(private readonly array $stats)
    {
        parent::__construct($stats);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $valueKobo = Naira::koboFromLenient($this->stats['amount_total']);

        return [
            'products_count' => $this->stats['products_count'],
            'active_products_count' => $this->stats['active_products_count'],
            'stock_count' => $this->stats['stock_count'],
            'stock_value_kobo' => $valueKobo,
            'stock_value' => Naira::decimalFromKobo($valueKobo),
            'out_of_stock_count' => $this->stats['out_of_stock_count'],
        ];
    }
}
