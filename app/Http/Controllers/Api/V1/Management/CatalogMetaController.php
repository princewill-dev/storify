<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-30 — catalog meta.
 *
 * The management API had no currency source at all, so a service price panel
 * (legacy offered a dropdown of every currency with the default preselected)
 * had nothing to populate from. This follows the same fallback chain the
 * product form documents — default currency, then the business's own currency
 * code, then NGN — without coupling services to that form's options endpoint.
 */
class CatalogMetaController extends ApiController
{
    use ResolvesManagementContext;

    public function currencies(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $default = Currency::where('is_default', true)->first();

        return $this->ok([
            'currencies' => Currency::orderBy('name')->get()->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'is_default' => (bool) $currency->is_default,
            ])->values()->all(),
            'default_currency_id' => $default?->id,
            'default_currency_code' => $default?->code ?: ($user->business?->currency ?: 'NGN'),
        ]);
    }
}
