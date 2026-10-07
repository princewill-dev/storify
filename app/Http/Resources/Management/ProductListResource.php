<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;

/**
 * WS-25 — a product row as the products table renders it.
 *
 * A strict superset of the WS-14 list payload: it inherits ProductResource's
 * fields and money handling (amount/display_amount/discount_amount, the
 * variant span and the low-stock flag) and adds `display_price_range`, the
 * variant span after the product-level discount, so the table can strike
 * through the original range without doing money arithmetic in the browser.
 *
 * `display_price_range` is emitted directly after `price_range`, where the
 * in-controller payload placed it — field order is part of the response
 * contract, not an accident of array building.
 */
class ProductListResource extends ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = parent::toArray($request);

        $ordered = [];

        foreach ($payload as $key => $value) {
            $ordered[$key] = $value;

            if ($key === 'price_range') {
                $ordered['display_price_range'] = $this->displayPriceRange($value, $payload['discount_percentage']);
            }
        }

        return $ordered;
    }

    /**
     * The same span after the product-level discount. Discount is applied in
     * integer kobo and basis points — never float arithmetic on the money
     * itself — and the division mirrors the original float output.
     *
     * @param  array{min: float, max: float}|null  $range
     * @return array{min: float, max: float}|null
     */
    private function displayPriceRange(?array $range, ?float $discountPercent): ?array
    {
        if ($range === null || ! $discountPercent) {
            return $range;
        }

        $discounted = fn (float $amount) => (int) round(((int) round($amount * 100)) * (int) round($discountPercent * 100) / 10000);

        return [
            'min' => ((int) round($range['min'] * 100) - $discounted($range['min'])) / 100,
            'max' => ((int) round($range['max'] * 100) - $discounted($range['max'])) / 100,
        ];
    }
}
