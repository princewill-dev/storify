<?php

namespace App\Http\Resources\Management\Service;

use App\Models\Service;
use App\Models\ServiceImage;
use Illuminate\Http\Request;

/**
 * The detailed WS-30 ServiceController row — the inline `detail()` verbatim:
 * the `summary()` keys then `images`, appended after `created_at` in the order
 * the old array spread produced, so the exact-JSON assertions on the
 * show/create/update responses keep reading the same shape.
 *
 * The relations this reads (store, images, currency) are expected to be
 * loaded by the caller — the controller's detail adapter applies the same
 * `loadMissing()` the old private shaper did.
 *
 * @property Service $resource
 */
final class ServiceDetailResource extends ServiceResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = $this->resource;

        $data = parent::toArray($request);

        $data['images'] = $service->images->map(fn (ServiceImage $image) => [
            'id' => $image->id,
            'url' => asset('storage/'.$image->path),
            'is_primary' => (bool) $image->is_primary,
            'position' => (int) $image->position,
        ])->values()->all();

        return $data;
    }
}
