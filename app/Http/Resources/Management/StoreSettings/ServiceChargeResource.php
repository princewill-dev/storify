<?php

namespace App\Http\Resources\Management\StoreSettings;

use App\Models\ServiceCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-04 — a service charge row as the settings workspace and the charge write
 * responses render it: StoreSettingsController::chargePayload() moved
 * verbatim.
 *
 * `amount` is naira-decimal, not kobo — `round((float) ..., 2)` is the
 * controller's exact expression and must stay: POS checkout adds this column
 * straight onto naira order totals and the POS read endpoint returns the same
 * unit, so none of the App\Support\Money\Naira kobo converters apply and none
 * may be substituted here. Float output is the contract; a decimal string
 * would be a regression.
 *
 * @property ServiceCharge $resource
 */
final class ServiceChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $charge = $this->resource;

        return [
            'id' => $charge->id,
            'name' => $charge->name,
            'amount' => round((float) $charge->amount, 2),
            'description' => $charge->description,
            'is_active' => (bool) $charge->is_active,
        ];
    }
}
