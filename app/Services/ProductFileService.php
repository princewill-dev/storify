<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Http\UploadedFile;

class ProductFileService
{
    /**
     * Store uploaded digital files for a product on the private disk.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function storeFiles(Product $product, array $files): void
    {
        $position = (int) $product->files()->max('position');
        $position = $position < 0 ? 0 : $position + 1;
        $hasPrimary = $product->files()->exists();

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('products/downloads/'.($product->business_id ?: 'shared'), 'local');

            ProductFile::create([
                'product_id' => $product->id,
                'business_id' => $product->business_id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'is_primary' => ! $hasPrimary && $position === 0,
                'position' => $position++,
            ]);

            $hasPrimary = true;
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function deleteFiles(Product $product, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        foreach ($product->files()->whereIn('id', $ids)->get() as $file) {
            $file->deleteFromDisk();
            $file->delete();
        }
    }

    public function deleteAllFiles(Product $product): void
    {
        foreach ($product->files()->get() as $file) {
            $file->deleteFromDisk();
            $file->delete();
        }
    }
}
