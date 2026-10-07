<?php

namespace App\Services\Admin;

use App\Models\CompanyService;
use App\Services\ActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-18 (admin console) — company-service write workflows.
 *
 * Every mutation pairs its row write with the ActivityRecorder audit row
 * inside one transaction (the recorder contract: a rejected audit row must
 * take the mutation down with it) and busts the public nav cache only after
 * the transaction commits. The order the controller used survives here:
 *
 * - **Image replacement is ordered safely.** Legacy deleted the old file
 *   before storing the new one; a failed upload destroyed both. The caller
 *   stores the replacement first (via {@see storeImage()}) and the superseded
 *   file is removed only once the row has been saved — after the transaction
 *   commits, so a rollback finds the row still pointing at its old image.
 * - **Deletes keep legacy's file cleanup, after the row is gone.**
 * - **A failed transaction must not leave an orphaned upload**: if the row
 *   write or audit fails, the just-stored file is deleted before the
 *   exception is rethrown for the controller to map.
 *
 * The controller keeps the HTTP shape (status codes, message strings and the
 * "could not be saved" / "failed to create|update" refusals), the text
 * sanitation that must run before an upload, and the audit `Log::info` lines
 * that read the request's actor. Queries live in CompanyServiceRepository.
 */
final class CompanyServiceLifecycleService
{
    /**
     * The public nav cache the home views read (legacy key, kept verbatim so
     * the legacy view composer and the new consumers share one invalidation).
     * Re-exported on the controller for tests and callers.
     */
    public const NAV_CACHE_KEY = 'nav_company_services';

    /**
     * @param  array<string, mixed>  $data  the validated, cleaned create payload
     * @param  string|null  $image  the stored background-image path
     */
    public function create(array $data, ?string $image): CompanyService
    {
        try {
            $service = DB::transaction(function () use ($data, $image) {
                $service = CompanyService::create([
                    'order' => $data['order'] ?? 0,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'page_link' => $data['page_link'] ?? null,
                    'background_image_path' => $image,
                    'status' => $data['status'],
                ]);

                ActivityRecorder::record(
                    action: 'company_service_created',
                    description: "Company service {$service->title} created.",
                    subject: $service,
                    new: $this->auditValues($service),
                );

                return $service;
            });
        } catch (\Throwable $e) {
            // The row did not persist, so the uploaded file must not either.
            $this->deleteImage($image);

            throw $e;
        }

        $this->forgetNavCache();

        return $service;
    }

    /**
     * @param  array<string, mixed>  $data  the validated, cleaned update payload
     * @param  string|null  $newImage  the stored replacement image, if any
     */
    public function update(CompanyService $service, array $data, ?string $newImage): void
    {
        // See TestimonialController::update — the replaced file is removed
        // only after the transaction commits, so a rollback keeps the row and
        // its old image consistent.
        $previousImage = $service->background_image_path;

        try {
            DB::transaction(function () use ($service, $data, $newImage) {
                $before = $this->auditValues($service);

                $attributes = [
                    'order' => $data['order'] ?? 0,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'page_link' => $data['page_link'] ?? null,
                    'status' => $data['status'],
                ];

                if ($newImage !== null) {
                    $attributes['background_image_path'] = $newImage;
                }

                $service->update($attributes);

                ActivityRecorder::record(
                    action: 'company_service_updated',
                    description: "Company service {$service->title} updated.",
                    subject: $service,
                    old: $before,
                    new: $this->auditValues($service),
                );
            });
        } catch (\Throwable $e) {
            $this->deleteImage($newImage);

            throw $e;
        }

        // The replacement is committed; the superseded file can go now.
        if ($newImage !== null && $previousImage !== $newImage) {
            $this->deleteImage($previousImage);
        }

        $this->forgetNavCache();
    }

    public function delete(CompanyService $service): void
    {
        $image = $service->background_image_path;

        DB::transaction(function () use ($service) {
            ActivityRecorder::record(
                action: 'company_service_deleted',
                description: "Company service {$service->title} deleted.",
                subject: $service,
                old: $this->auditValues($service),
            );

            $service->delete();
        });

        // Legacy deleted the file too; keep that, but after the row is gone.
        $this->deleteImage($image);
        $this->forgetNavCache();
    }

    public function toggle(CompanyService $service): void
    {
        DB::transaction(function () use ($service) {
            $before = ['status' => $service->status];

            $service->update([
                'status' => $service->status === 'active' ? 'inactive' : 'active',
            ]);

            ActivityRecorder::record(
                action: 'company_service_toggled',
                description: "Company service {$service->title} set to {$service->status}.",
                subject: $service,
                old: $before,
                new: ['status' => $service->status],
            );
        });

        $this->forgetNavCache();
    }

    /**
     * Drag-and-drop reorder. The SPA posts the visible page as `items` with
     * the order value each row should take, so a page-2 drag stays consistent
     * with page 1 (legacy rewrote order values from 0 per request, which
     * collided across pages). Unknown ids are refused by the controller
     * before this runs, so every write here hits an existing row.
     *
     * @param  array<int, array{id: int|string, order: int|string}>  $items
     */
    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                CompanyService::query()
                    ->whereKey($item['id'])
                    ->update(['order' => $item['order']]);
            }

            ActivityRecorder::record(
                action: 'company_services_reordered',
                description: 'Company services reordered.',
                new: ['items' => $items],
            );
        });

        $this->forgetNavCache();
    }

    /**
     * Store an uploaded background image on the public disk. Kept on this
     * layer so the disk and folder live with the rest of the file lifecycle;
     * the controller maps a failure here to its "could not be saved" response
     * before any row is touched.
     */
    public function storeImage(UploadedFile $file): string
    {
        return $file->store('company_services', 'public');
    }

    /**
     * Removes an uploaded background image from disk. A missing/unwritable
     * file must not fail the row mutation; the warning log keeps the failure
     * visible.
     */
    private function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('api.admin.company_service_image_delete_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function forgetNavCache(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(CompanyService $service): array
    {
        return [
            'order' => (int) $service->order,
            'title' => $service->title,
            'description' => $service->description,
            'page_link' => $service->page_link,
            'background_image_path' => $service->background_image_path,
            'status' => $service->status,
        ];
    }
}
