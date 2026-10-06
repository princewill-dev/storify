<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\DigitalDownload;
use App\Models\ProductFile;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, tokenised digital downloads.
 *
 * Ported from the legacy Storefront\DigitalDownloadController, which served
 * these as Blade pages. The storefront SPA owns the UI now; this keeps the
 * enforcement that only ever lived in that controller:
 *
 *   - the file must belong to the product the token was issued for
 *   - the token must not be expired or exhausted
 *   - the file must actually exist on disk
 *   - a successful fetch increments the counter
 *
 * Tokens are guessed-at only by their 32-byte value, so the token itself is the
 * credential. Scoping to the store is stricter than legacy, which had a single
 * global route — a token issued by one store cannot be redeemed through
 * another's storefront.
 */
class DownloadController extends ApiController
{
    use ResolvesStorefrontContext;

    public function show(Request $request, string $store, string $token): JsonResponse
    {
        $download = $this->resolveDownload($store, $token);

        return $this->ok(['download' => $this->payload($download)]);
    }

    public function file(Request $request, string $store, string $token, ProductFile $file): StreamedResponse|JsonResponse
    {
        $download = $this->resolveDownload($store, $token);

        // A token only ever grants access to its own product's files.
        if ((int) $file->product_id !== (int) $download->product_id) {
            abort(404);
        }

        if ($download->isExpired()) {
            return $this->error('This download link has expired.', 403, ['code' => ['expired']]);
        }

        if ($download->isExhausted()) {
            return $this->error('You have reached the download limit for this item.', 403, ['code' => ['exhausted']]);
        }

        if (! $file->existsOnDisk()) {
            abort(404);
        }

        $download->increment('download_count');
        $download->update(['last_downloaded_at' => now()]);

        return Storage::disk($file->disk ?: 'local')->download($file->path, $file->original_name);
    }

    /**
     * A token is only meaningful within the store that issued it.
     */
    private function resolveDownload(string $slug, string $token): DigitalDownload
    {
        /** @var Store $store */
        $store = $this->resolveStore($slug);

        return DigitalDownload::query()
            ->where('token', $token)
            ->whereHas('order', fn ($order) => $order->where('store_id', $store->id))
            ->with(['product.files', 'order.store'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DigitalDownload $download): array
    {
        return [
            'token' => $download->token,
            'product_name' => $download->product?->name,
            'download_count' => (int) $download->download_count,
            'max_downloads' => (int) $download->max_downloads,
            'downloads_remaining' => $download->downloadsRemaining(),
            'expires_at' => $download->expires_at?->toISOString(),
            'is_active' => $download->isActive(),
            'is_expired' => $download->isExpired(),
            'is_exhausted' => $download->isExhausted(),
            'files' => $download->product?->files
                ->map(fn (ProductFile $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                ])->values()->all() ?? [],
        ];
    }
}
