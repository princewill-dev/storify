<?php

namespace App\Http\Resources\Admin;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 (admin) — one row of the default-currency picker.
 *
 * The rows come from `SettingsRepository::currencyOptions()`; `is_default` is
 * the exclusive platform-wide flag the save flips.
 *
 * @property-read Currency $resource
 */
final class CurrencyOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Currency $currency */
        $currency = $this->resource;

        return [
            'id' => $currency->id,
            'name' => $currency->name,
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'is_default' => (bool) $currency->is_default,
        ];
    }
}
