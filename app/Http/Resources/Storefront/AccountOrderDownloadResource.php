<?php

namespace App\Http\Resources\Storefront;

use App\Models\DigitalDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One download row inside the account order-detail payload — the controller's
 * inline map moved verbatim: field names, order and types unchanged.
 *
 * Only rows whose product still exists reach this class, because
 * AccountRepository::downloadsFor drops orphaned downloads — so the product is
 * dereferenced without a null-safe operator, exactly as the inline map did.
 */
final class AccountOrderDownloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DigitalDownload $download */
        $download = $this->resource;

        return [
            'product_name' => $download->product->name,
            'token' => $download->token,
            'downloads_remaining' => $download->downloadsRemaining(),
            'expires_at' => $download->expires_at?->toISOString(),
            'status' => $download->status_label,
        ];
    }
}
