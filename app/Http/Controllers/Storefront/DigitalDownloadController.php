<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\DigitalDownload;
use App\Models\ProductFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DigitalDownloadController extends Controller
{
    public function show(string $token): View
    {
        $download = DigitalDownload::query()
            ->where('token', $token)
            ->with(['product.files', 'order.store'])
            ->firstOrFail();

        $store = $download->order?->store;

        return view('storefront.pages.downloads.show', compact('download', 'store', 'token'));
    }

    public function file(Request $request, string $token, ProductFile $file): StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $download = DigitalDownload::query()
            ->where('token', $token)
            ->firstOrFail();

        if ((int) $file->product_id !== (int) $download->product_id) {
            abort(404);
        }

        if (! $download->isActive()) {
            return redirect()->route('downloads.show', ['token' => $token])
                ->with('error', $download->isExpired()
                    ? 'This download link has expired.'
                    : 'You have reached the download limit for this item.');
        }

        if (! $file->existsOnDisk()) {
            abort(404);
        }

        $download->increment('download_count');
        $download->update(['last_downloaded_at' => now()]);

        return Storage::disk($file->disk ?: 'local')->download($file->path, $file->original_name);
    }
}
