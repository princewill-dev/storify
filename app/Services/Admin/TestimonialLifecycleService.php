<?php

namespace App\Services\Admin;

use App\Models\Testimonial;
use App\Repositories\Admin\TestimonialRepository;
use App\Services\ActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-18 (admin console) — testimonial write workflows.
 *
 * Every mutation pairs its row write with the ActivityRecorder audit row
 * inside one transaction (the recorder contract: a rejected audit row must
 * take the mutation down with it). The order the controller used survives
 * here:
 *
 * - **Uploads are ordered safely.** The caller stores a replacement photo
 *   first (via {@see storePhoto()}) so a storage failure happens before any
 *   row points at it. A failed transaction deletes the just-stored file and
 *   rethrows for the controller to map; on success the superseded file is
 *   removed only once the transaction has committed, so a rollback finds the
 *   row still pointing at its old photo (legacy deleted the old file before
 *   storing the new one and could lose both).
 * - **Deletes keep the disk clean**, after the row is gone. Base64 rows and
 *   any absolute URL a seeder may have stored have no file to remove.
 * - **The photo backfill is deliberately idempotent** and safe to re-run:
 *   only rows still starting with `data:` are touched, rows whose payload
 *   cannot be decoded are skipped (never deleted), and the result reports
 *   what is left.
 *
 * The controller keeps the HTTP shape (status codes, message strings and the
 * "could not be saved" / "failed to create|update" refusals), the text
 * sanitation that must run before an upload, and the per-request Log lines
 * that read the actor; queries live in TestimonialRepository.
 */
final class TestimonialLifecycleService
{
    public function __construct(
        private readonly TestimonialRepository $testimonials,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the validated, cleaned create payload
     * @param  string  $photo  the stored photo path
     */
    public function create(array $data, string $photo): Testimonial
    {
        try {
            return DB::transaction(function () use ($data, $photo) {
                $testimonial = Testimonial::create([
                    'name' => $data['name'],
                    'occupation' => $data['occupation'],
                    'message' => $data['message'],
                    'photo' => $photo,
                    'status' => $data['status'],
                    'position' => $data['position'] ?? 0,
                ]);

                ActivityRecorder::record(
                    action: 'testimonial_created',
                    description: "Testimonial from {$testimonial->name} created.",
                    subject: $testimonial,
                    new: $this->auditValues($testimonial),
                );

                return $testimonial;
            });
        } catch (\Throwable $e) {
            // The row did not persist, so the orphaned file must not either.
            $this->deletePhotoFile($photo);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data  the validated, cleaned update payload
     * @param  string|null  $newPhoto  the stored replacement photo, if any
     */
    public function update(Testimonial $testimonial, array $data, ?string $newPhoto): void
    {
        // The replaced file is only removed once the transaction has
        // committed: a rollback must find the row still pointing at its old
        // file, so deletion can never run ahead of a durable write (legacy
        // deleted the old file before storing the new one and could lose both).
        $previousPhoto = $testimonial->photo;

        try {
            DB::transaction(function () use ($newPhoto, $testimonial, $data) {
                $before = $this->auditValues($testimonial);

                $attributes = [
                    'name' => $data['name'],
                    'occupation' => $data['occupation'],
                    'message' => $data['message'],
                    'status' => $data['status'],
                    'position' => $data['position'] ?? 0,
                ];

                if ($newPhoto !== null) {
                    $attributes['photo'] = $newPhoto;
                }

                $testimonial->update($attributes);

                ActivityRecorder::record(
                    action: 'testimonial_updated',
                    description: "Testimonial from {$testimonial->name} updated.",
                    subject: $testimonial,
                    old: $before,
                    new: $this->auditValues($testimonial),
                );
            });
        } catch (\Throwable $e) {
            $this->deletePhotoFile($newPhoto);

            throw $e;
        }

        // The replacement is committed; the superseded file can go now.
        if ($newPhoto !== null && $previousPhoto !== $newPhoto) {
            $this->deletePhotoFile($previousPhoto);
        }
    }

    public function delete(Testimonial $testimonial): void
    {
        $photo = $testimonial->photo;

        DB::transaction(function () use ($testimonial) {
            ActivityRecorder::record(
                action: 'testimonial_deleted',
                description: "Testimonial from {$testimonial->name} deleted.",
                subject: $testimonial,
                old: $this->auditValues($testimonial),
            );

            $testimonial->delete();
        });

        // Legacy hard-deleted the row and left the file behind; the rebuild
        // keeps the disk clean. Base64 rows have no file to remove.
        $this->deletePhotoFile($photo);
    }

    public function toggle(Testimonial $testimonial): void
    {
        DB::transaction(function () use ($testimonial) {
            $before = ['status' => $testimonial->status];

            $testimonial->update([
                'status' => $testimonial->status === 'active' ? 'inactive' : 'active',
            ]);

            ActivityRecorder::record(
                action: 'testimonial_toggled',
                description: "Testimonial from {$testimonial->name} set to {$testimonial->status}.",
                subject: $testimonial,
                old: $before,
                new: ['status' => $testimonial->status],
            );
        });
    }

    /**
     * One-shot repair for the live photo mismatch: legacy stored uploads as
     * `data:{mime};base64,…` strings in the same column the home API builds
     * `asset('storage/'.$photo)` from, so every legacy row renders a broken
     * image. This decodes each base64 row to a real file on the public disk
     * and rewrites the column to the stored path.
     *
     * The controller logs the actor and the outcome counts, and owns the
     * response message.
     *
     * @return array{converted: int, skipped: int, failed: int, remaining: int}
     */
    public function backfillLegacyPhotos(): array
    {
        $converted = 0;
        $skipped = 0;
        $failed = 0;

        $this->testimonials->legacyPhotosQuery()
            ->chunkById(50, function ($testimonials) use (&$converted, &$skipped, &$failed) {
                foreach ($testimonials as $testimonial) {
                    $decoded = $this->decodeLegacyPhoto($testimonial->photo);

                    if ($decoded === null) {
                        $skipped++;

                        continue;
                    }

                    [$binary, $extension] = $decoded;
                    $path = 'testimonials/legacy-'.$testimonial->id.'-'.substr(sha1((string) microtime(true)), 0, 8).'.'.$extension;

                    try {
                        Storage::disk('public')->put($path, $binary);
                        $testimonial->update(['photo' => $path]);
                        $converted++;
                    } catch (\Throwable $e) {
                        $failed++;

                        Log::error('api.admin.testimonial_photo_backfill_failed', [
                            'testimonial_id' => $testimonial->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $remaining = $this->testimonials->legacyPhotosQuery()->count();

        if ($converted > 0) {
            ActivityRecorder::record(
                action: 'testimonials_photos_backfilled',
                description: "Converted {$converted} legacy base64 testimonial photo(s) to storage files.",
                new: ['converted' => $converted, 'skipped' => $skipped, 'failed' => $failed],
            );
        }

        return [
            'converted' => $converted,
            'skipped' => $skipped,
            'failed' => $failed,
            'remaining' => $remaining,
        ];
    }

    /**
     * Store the uploaded photo as a real file. Legacy base64-encoded it into
     * the row; the home API and its tests both expect a `storage/`-relative
     * path, so that is the contract here.
     */
    public function storePhoto(UploadedFile $file): string
    {
        return $file->store('testimonials', 'public');
    }

    /**
     * Removes an uploaded file from disk. Legacy base64 rows and any absolute
     * URL a seeder may have stored have no file to delete.
     */
    private function deletePhotoFile(?string $photo): void
    {
        if (! $photo || str_starts_with($photo, 'data:') || str_starts_with($photo, 'http')) {
            return;
        }

        try {
            Storage::disk('public')->delete($photo);
        } catch (\Throwable $e) {
            Log::warning('api.admin.testimonial_photo_delete_failed', [
                'path' => $photo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode a legacy `data:{mime};base64,…` photo.
     *
     * @return array{0: string, 1: string}|null [binary, extension] or null when
     *                                          the value is not a usable image
     */
    private function decodeLegacyPhoto(?string $photo): ?array
    {
        if (! is_string($photo) || ! preg_match('/^data:(image\/(?:jpeg|jpg|png|gif|webp));base64,(.+)$/s', $photo, $matches)) {
            return null;
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false || strlen($binary) < 32) {
            return null;
        }

        // Legacy validation capped uploads at 2 MB; do not write an absurd
        // blob into the public disk just because a row contains one.
        if (strlen($binary) > 4 * 1024 * 1024) {
            return null;
        }

        $extension = match ($matches[1]) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };

        return $extension === null ? null : [$binary, $extension];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Testimonial $testimonial): array
    {
        return [
            'name' => $testimonial->name,
            'occupation' => $testimonial->occupation,
            // Never copy base64 blobs into the audit table.
            'photo' => str_starts_with((string) $testimonial->photo, 'data:') ? '[base64 photo]' : $testimonial->photo,
            'status' => $testimonial->status,
            'position' => (int) $testimonial->position,
        ];
    }
}
