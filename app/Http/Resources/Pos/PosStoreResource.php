<?php

namespace App\Http\Resources\Pos;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The POS terminal's store row — the `stores` list entries and the single
 * `store` / `active_store` blocks.
 *
 * Field names, types and order are frozen. The full shape (with `logo`) is
 * what login, verify-pin and switch-store always emitted; me() has always
 * emitted the short shape without a logo, hence `withoutLogo()` rather than a
 * second resource.
 *
 * `logo` remains the `asset('storage/...')` URL or null for a store without
 * a path — never an empty string.
 */
final class PosStoreResource extends JsonResource
{
    private bool $withLogo = true;

    public function withoutLogo(): static
    {
        $this->withLogo = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        $data = [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'address' => $store->address,
        ];

        if ($this->withLogo) {
            $data['logo'] = $store->logo_path ? asset('storage/'.$store->logo_path) : null;
        }

        return $data;
    }
}
