<?php

namespace App\Services\Management\Service;

use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The WS-30 ServiceController's write workflows — create, update, delete —
 * with the transaction boundaries the controller used, at the same points in
 * the sequence.
 *
 * The transaction belongs to this layer, never to the repository; the
 * controller keeps the HTTP shape (status codes, message strings, the
 * envelope) and the audit call that runs after each workflow commits.
 *
 * Deliberate details carried over from the controller, not "fixed":
 *
 * 1. `business_id` is force-filled after `Service::create()` because the
 *    multi-tenant migration added the column after the shared model's
 *    $fillable was written; it cannot ride in the create() array without
 *    touching a file this workstream does not own.
 * 2. Legacy forced every new service live; hiding it is an edit.
 * 3. A null `primary_image_index` on update never steals the cover flag from
 *    an existing primary.
 * 4. Deleting the primary (or saving an image-less service) must not leave the
 *    gallery without a cover — the list thumbnail and the storefront both read
 *    primaryImage().
 * 5. `delete()` snapshots the gallery before the transaction but removes the
 *    files only after it commits, exactly as the controller did.
 */
final class ServiceCatalogueService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(Request $request, User $user, array $data): Service
    {
        return DB::transaction(function () use ($request, $user, $data) {
            $service = Service::create([
                'store_id' => $data['store_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'] ?? Currency::where('is_default', true)->value('id'),
                // Legacy forced every new service live; hiding it is an edit.
                'status' => 'active',
            ]);

            // `business_id` was added by the multi-tenant migration after the
            // shared Service model's $fillable was written, so it cannot ride
            // in the create() array without editing a file this workstream
            // does not own.
            $service->forceFill(['business_id' => $user->business_id])->save();

            $this->storeImages($service, $request->file('images'), $data['primary_image_index'] ?? null);

            return $service;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function update(Request $request, Service $service, array $data): void
    {
        $primaryId = $data['primary_image_id'] ?? null;

        DB::transaction(function () use ($request, $service, $data, $primaryId) {
            $service->update([
                'store_id' => $data['store_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'] ?? null,
                'status' => $data['status'],
            ]);

            foreach ($service->images()->whereIn('id', $data['delete_image_ids'] ?? [])->get() as $image) {
                $this->deleteImageFile($image);
                $image->delete();
            }

            // Null index: existing images already elected a primary, so plain
            // uploads never steal the flag; the caller can still nominate one
            // of the new uploads (or an existing image via primary_image_id).
            $this->storeImages($service, $request->file('images'), $data['primary_image_index'] ?? null);

            if ($primaryId !== null) {
                $service->images()->update(['is_primary' => false]);
                $service->images()->whereKey($primaryId)->update(['is_primary' => true]);
            }

            // Deleting the primary (or saving an image-less service) must not
            // leave the gallery without a cover — the list thumbnail and the
            // storefront both read primaryImage().
            if (! $service->images()->where('is_primary', true)->exists()) {
                $service->images()->orderBy('position')->first()?->update(['is_primary' => true]);
            }
        });
    }

    public function delete(Service $service): void
    {
        $images = $service->images()->get();

        DB::transaction(fn () => $service->delete());

        foreach ($images as $image) {
            $this->deleteImageFile($image);
        }
    }

    /**
     * Appends uploads to the gallery and elects a cover.
     *
     * The legacy create form never sent a primary flag — the first image won
     * only because (int) null === 0 happened to match its array index. The
     * caller can name an index explicitly here; when it does not, the first
     * upload still becomes the cover if the gallery has none, which preserves
     * the legacy behaviour without the accidental comparison.
     *
     * @param  array<int, UploadedFile>|null  $files
     */
    private function storeImages(Service $service, ?array $files, ?int $primaryIndex): void
    {
        if (empty($files)) {
            return;
        }

        $position = $service->images()->exists()
            ? ((int) $service->images()->max('position')) + 1
            : 0;

        $created = [];

        foreach (array_values($files) as $index => $file) {
            $created[$index] = ServiceImage::create([
                'service_id' => $service->id,
                'path' => $file->store('services/images', 'public'),
                'is_primary' => false,
                'position' => $position++,
            ]);
        }

        // The index names an upload, not a gallery slot — existing images must
        // not shift it.
        $elected = $primaryIndex !== null ? ($created[$primaryIndex] ?? null) : null;

        if ($elected) {
            $service->images()->update(['is_primary' => false]);
            $elected->update(['is_primary' => true]);

            return;
        }

        if (! $service->images()->where('is_primary', true)->exists()) {
            $service->images()->orderBy('position')->first()?->update(['is_primary' => true]);
        }
    }

    private function deleteImageFile(ServiceImage $image): void
    {
        if (! $image->path) {
            return;
        }

        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // A missing file must not block removing the row — legacy
            // swallowed the same failure.
        }
    }
}
