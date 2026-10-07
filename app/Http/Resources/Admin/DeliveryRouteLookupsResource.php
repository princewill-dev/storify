<?php

namespace App\Http\Resources\Admin;

use App\Data\Nigeria;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-17 — the Nigeria state/area picklist for the route form.
 *
 * The form remains free text (legacy stored any country/state/area string);
 * these are suggestions that keep area names consistent. The payload is built
 * from the static Nigeria list, so there is no model to wrap.
 */
final class DeliveryRouteLookupsResource extends JsonResource
{
    public function __construct()
    {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'countries' => ['Nigeria'],
            'states' => array_values(Nigeria::states()),
            'areas_by_state' => $this->areasByState(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function areasByState(): array
    {
        $areas = [];

        foreach (array_values(Nigeria::states()) as $state) {
            $cities = Nigeria::citiesByState($state);

            if ($cities === []) {
                // citiesByState keys the FCT with an en dash while states()
                // spells it with an em dash — try the alternate spelling.
                // One-way only: a two-entry str_replace swaps em→en and then
                // en→em over its own output, returning the input unchanged
                // and losing the FCT suggestions entirely.
                $alternate = str_contains($state, '—')
                    ? str_replace('—', '–', $state)
                    : str_replace('–', '—', $state);

                $cities = Nigeria::citiesByState($alternate);
            }

            if ($cities !== []) {
                $areas[$state] = array_values($cities);
            }
        }

        return $areas;
    }
}
