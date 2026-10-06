<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Testimonial;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-18 (admin console) — marketing testimonials.
 *
 * Rebuilds `/office/testimonials` (Content › Testimonials): the ordered list
 * with photo / name / occupation / message / position / status columns, the
 * create + per-row edit modal and the hard delete behind a confirm. The
 * public consumer already exists (`Api\V1\Home\HomeController::testimonials()`
 * serves active rows ordered by position, capped at 6), so this controller is
 * the write side of a live dataset.
 *
 * Fixed rather than cloned:
 *
 * - **Photo storage.** Legacy base64-encoded every upload into the `photo`
 *   longText column while the home API serialises `asset('storage/'.$photo)` —
 *   so every legacy row renders as a broken image on the live marketing site.
 *   Uploads are real files on the `public` disk here (the convention the home
 *   app and its tests already expect), and `backfillPhotos()` converts the
 *   existing `data:` rows in place. The admin payload also resolves BOTH
 *   conventions, so the office screen keeps rendering rows the backfill has
 *   not reached yet.
 * - **Escaping.** Legacy printed the message unescaped; a stored `<script>`
 *   executed in the office panel. Fields are stored as plain text and the
 *   API/SPA escape on output — never clone the raw render.
 * - **Pagination and ordering.** Legacy dumped the whole table and the new
 *   home reader silently caps at {@see self::PUBLIC_CAP}; the list paginates,
 *   accepts a whitelisted sort (never a raw `sort_by`) and reports the active
 *   count against the public cap so "why is #7 missing from the site?" has an
 *   answer on screen.
 * - **Traceability.** Every mutation writes an ActivityRecorder row with
 *   old/new values (legacy only wrote a Log line plus a request-log row).
 *
 * The table is platform-wide marketing content, so no tenant scoping applies;
 * the platform-admin guard still matters because an in-business "Super Admin"
 * role carries the full `admin.*` permission bundle.
 */
class TestimonialController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * How many active testimonials the public home API serves. Kept in sync
     * with `Api\V1\Home\HomeController::testimonials()`; legacy admin could
     * manage unlimited rows but only these six ever reached the site.
     */
    public const PUBLIC_CAP = 6;

    private const STATUSES = ['active', 'inactive'];

    /**
     * Whitelisted sort columns — an unknown `sort` is rejected by validation,
     * never passed to orderBy.
     */
    private const SORTS = ['position', 'created_at', 'updated_at', 'name', 'id'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? 'position';
        $direction = $filters['direction'] ?? 'asc';

        $testimonials = Testimonial::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('occupation', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            // Stable tiebreak so pagination can never duplicate or skip a row.
            // Legacy's `position` then `created_at desc` had no final tiebreak.
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'testimonials' => $testimonials->getCollection()
                    ->map(fn (Testimonial $testimonial) => $this->payload($testimonial))
                    ->values()
                    ->all(),
                'public_cap' => self::PUBLIC_CAP,
                'counts' => $this->counts(),
            ],
            null,
            200,
            $this->paginationMeta($testimonials),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request, true);

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $clean = $this->cleanText($data);

        // Write the file before the row so a storage failure never leaves a
        // database row pointing at nothing.
        try {
            $photo = $this->storePhoto($request);
        } catch (\Throwable $e) {
            Log::error('api.admin.testimonial_photo_store_failed', [
                'admin_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The photo could not be saved. Please try again.', 500);
        }

        try {
            $testimonial = DB::transaction(function () use ($clean, $data, $photo) {
                $testimonial = Testimonial::create([
                    'name' => $clean['name'],
                    'occupation' => $clean['occupation'],
                    'message' => $clean['message'],
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

            Log::error('api.admin.testimonial_create_failed', [
                'admin_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to create testimonial. Please try again.', 500);
        }

        Log::info('api.admin.testimonial_created', [
            'admin_id' => $request->user()?->id,
            'testimonial_id' => $testimonial->id,
        ]);

        return $this->ok(['testimonial' => $this->payload($testimonial)], 'Testimonial created.', 201);
    }

    public function update(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Photo is optional on edit — legacy's "Leave empty to keep current
        // photo" hint.
        $data = $this->validated($request, false);

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $clean = $this->cleanText($data);

        // Store the replacement first: the old file is only deleted once the
        // row points at the new one.
        $newPhoto = null;

        if ($request->hasFile('photo')) {
            try {
                $newPhoto = $this->storePhoto($request);
            } catch (\Throwable $e) {
                Log::error('api.admin.testimonial_photo_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'testimonial_id' => $testimonial->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The photo could not be saved. Please try again.', 500);
            }
        }

        // The replaced file is only removed once the transaction has
        // committed: a rollback must find the row still pointing at its old
        // file, so deletion can never run ahead of a durable write (legacy
        // deleted the old file before storing the new one and could lose both).
        $previousPhoto = $testimonial->photo;

        try {
            DB::transaction(function () use ($newPhoto, $testimonial, $data, $clean) {
                $before = $this->auditValues($testimonial);

                $attributes = [
                    'name' => $clean['name'],
                    'occupation' => $clean['occupation'],
                    'message' => $clean['message'],
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

            Log::error('api.admin.testimonial_update_failed', [
                'admin_id' => $request->user()?->id,
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to update testimonial. Please try again.', 500);
        }

        // The replacement is committed; the superseded file can go now.
        if ($newPhoto !== null && $previousPhoto !== $newPhoto) {
            $this->deletePhotoFile($previousPhoto);
        }

        return $this->ok(['testimonial' => $this->payload($testimonial->fresh())], 'Testimonial updated.');
    }

    public function destroy(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorizePlatformAdmin();

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

        Log::info('api.admin.testimonial_deleted', [
            'admin_id' => $request->user()?->id,
            'testimonial_id' => $testimonial->id,
        ]);

        return $this->ok([], 'Testimonial deleted.');
    }

    /**
     * Activate / deactivate from the list, so taking a testimonial off the
     * public site does not require opening (and re-saving) the edit modal.
     */
    public function toggle(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorizePlatformAdmin();

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

        return $this->ok(
            ['testimonial' => $this->payload($testimonial->fresh())],
            $testimonial->status === 'active' ? 'Testimonial activated.' : 'Testimonial deactivated.',
        );
    }

    /**
     * One-shot repair for the live photo mismatch: legacy stored uploads as
     * `data:{mime};base64,…` strings in the same column the home API builds
     * `asset('storage/'.$photo)` from, so every legacy row renders a broken
     * image. This decodes each base64 row to a real file on the public disk
     * and rewrites the column to the stored path.
     *
     * Deliberately idempotent and safe to re-run: only rows still starting
     * with `data:` are touched, rows whose payload cannot be decoded are
     * skipped (never deleted), and the response reports what is left.
     */
    public function backfillPhotos(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $converted = 0;
        $skipped = 0;
        $failed = 0;

        Testimonial::query()
            ->where('photo', 'like', 'data:%')
            ->orderBy('id')
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

        $remaining = Testimonial::query()->where('photo', 'like', 'data:%')->count();

        if ($converted > 0) {
            ActivityRecorder::record(
                action: 'testimonials_photos_backfilled',
                description: "Converted {$converted} legacy base64 testimonial photo(s) to storage files.",
                new: ['converted' => $converted, 'skipped' => $skipped, 'failed' => $failed],
            );
        }

        Log::info('api.admin.testimonial_photo_backfill', [
            'admin_id' => $request->user()?->id,
            'converted' => $converted,
            'skipped' => $skipped,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);

        return $this->ok(
            ['converted' => $converted, 'skipped' => $skipped, 'failed' => $failed, 'remaining' => $remaining],
            $converted > 0
                ? "Converted {$converted} legacy photo(s) to storage files."
                : 'No legacy photos could be converted.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'photo' => $creating
                ? ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048']
                : ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * Store the uploaded photo as a real file. Legacy base64-encoded it into
     * the row; the home API and its tests both expect a `storage/`-relative
     * path, so that is the contract here.
     */
    private function storePhoto(Request $request): string
    {
        return $request->file('photo')->store('testimonials', 'public');
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
     * @param  array<string, mixed>  $data
     * @return array{name: string, occupation: string, message: string}
     */
    private function cleanText(array $data): array
    {
        return [
            'name' => $this->plainText($data['name'], 'name', 'Name'),
            'occupation' => $this->plainText($data['occupation'], 'occupation', 'Occupation'),
            'message' => $this->plainText($data['message'], 'message', 'Message'),
        ];
    }

    /**
     * Store free text as plain text. Legacy rendered the message unescaped, so
     * a stored `<script>` executed in the office panel; stripping markup here
     * means no consumer can ever be the one that has to remember to escape.
     */
    private function plainText(string $value, string $field, string $label): string
    {
        $clean = trim($this->stripMarkup($value));

        if ($clean === '') {
            throw ValidationException::withMessages([
                $field => ["{$label} cannot be only HTML markup."],
            ]);
        }

        return $clean;
    }

    /**
     * `strip_tags()` removes the tags but leaves the *contents* of `<script>` /
     * `<style>` elements behind as loose text ("alert(1)"). The column is
     * plain text, so the element bodies are dropped first and the remaining
     * tag skeleton is stripped afterwards.
     */
    private function stripMarkup(string $value): string
    {
        return strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Testimonial $testimonial): array
    {
        return [
            'id' => $testimonial->id,
            'name' => $testimonial->name,
            'occupation' => $testimonial->occupation,
            'message' => $testimonial->message,
            // Renders both conventions: a real storage path (post-backfill and
            // every new upload) and a legacy `data:` URI that has not been
            // converted yet.
            'photo_url' => $this->photoUrl($testimonial->photo),
            'has_photo' => (bool) $testimonial->photo,
            'is_legacy_photo' => str_starts_with((string) $testimonial->photo, 'data:'),
            'status' => $testimonial->status,
            'position' => (int) $testimonial->position,
            'created_at' => $testimonial->created_at?->toISOString(),
            'updated_at' => $testimonial->updated_at?->toISOString(),
        ];
    }

    private function photoUrl(?string $photo): ?string
    {
        if (! $photo) {
            return null;
        }

        if (str_starts_with($photo, 'data:') || str_starts_with($photo, 'http')) {
            return $photo;
        }

        return asset('storage/'.$photo);
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

    /**
     * One aggregate instead of four counts — the screen shows total / active /
     * inactive and how many rows still need the photo backfill.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = Testimonial::query()->selectRaw("
            count(*) as total,
            sum(case when status = 'active' then 1 else 0 end) as active,
            sum(case when status = 'inactive' then 1 else 0 end) as inactive,
            sum(case when photo like 'data:%' then 1 else 0 end) as legacy_photos
        ")->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
            'legacy_photos' => (int) ($counts->legacy_photos ?? 0),
        ];
    }
}
