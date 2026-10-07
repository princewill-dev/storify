<?php

namespace App\Http\Resources\Storefront;

use App\Models\DigitalDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account downloads-list row — the controller's inline map moved
 * verbatim: field names, order and types unchanged.
 *
 * The product is null-safe because this list can hold downloads whose product
 * has been removed, exactly as the inline `$download->product?->name` did.
 */
final class AccountDownloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DigitalDownload $download */
        $download = $this->resource;

        return [
            'product_name' => $download->product?->name,
            'token' => $download->token,
            'downloads_remaining' => $download->downloadsRemaining(),
            'max_downloads' => (int) $download->max_downloads,
            'expires_at' => $download->expires_at?->toISOString(),
            'status' => $download->status_label,
            'is_active' => $download->isActive(),
        ];
    }
}
