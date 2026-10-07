<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListTestimonialsRequest;
use App\Http\Requests\Admin\StoreTestimonialRequest;
use App\Http\Requests\Admin\UpdateTestimonialRequest;
use App\Http\Resources\Admin\TestimonialResource;
use App\Models\Testimonial;
use App\Repositories\Admin\TestimonialRepository;
use App\Services\Admin\TestimonialLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 *   app and its tests already expect), and the one-shot backfill converts the
 *   existing `data:` rows in place; the file lifecycle and the backfill walk
 *   live in {@see TestimonialLifecycleService}. The admin payload resolves
 *   BOTH conventions, so the office screen keeps rendering rows the backfill
 *   has not reached yet.
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
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin
 * FormRequests (`ListTestimonialsRequest` for the list filters, the
 * `Store`/`UpdateTestimonialRequest` pair for the create/edit payload), query
 * building and the count aggregate in TestimonialRepository, the write
 * workflows, file lifecycle and photo backfill in
 * TestimonialLifecycleService, and response shaping in `TestimonialResource`;
 * the platform-admin guard deliberately stays here so its order is unchanged.
 *
 * Text sanitation stays here too, on purpose: a markup-only field must fail
 * as a normal 422 *before* an upload reaches the disk (the "no orphaned
 * upload" invariant), and the lifecycle service's transaction-failure catch
 * would otherwise swallow that ValidationException.
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

    public function __construct(
        private readonly TestimonialRepository $testimonials,
        private readonly TestimonialLifecycleService $lifecycle,
    ) {}

    public function index(ListTestimonialsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $testimonials = $this->testimonials->paginateForAdmin($request->validated());

        return $this->ok(
            [
                'testimonials' => $testimonials->getCollection()
                    ->map(fn (Testimonial $testimonial) => $this->payload($testimonial))
                    ->values()
                    ->all(),
                'public_cap' => self::PUBLIC_CAP,
                'counts' => $this->testimonials->counts(),
            ],
            null,
            200,
            $this->paginationMeta($testimonials),
        );
    }

    public function store(StoreTestimonialRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $data = $this->cleanText($request->validated());

        // Write the file before the row so a storage failure never leaves a
        // database row pointing at nothing.
        try {
            $photo = $this->lifecycle->storePhoto($request->file('photo'));
        } catch (\Throwable $e) {
            Log::error('api.admin.testimonial_photo_store_failed', [
                'admin_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The photo could not be saved. Please try again.', 500);
        }

        try {
            $testimonial = $this->lifecycle->create($data, $photo);
        } catch (\Throwable $e) {
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

    public function update(UpdateTestimonialRequest $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Photo is optional on edit — legacy's "Leave empty to keep current
        // photo" hint.
        $data = $this->cleanText($request->validated());

        // Store the replacement first: the old file is only deleted once the
        // row points at the new one.
        $newPhoto = null;

        if ($request->hasFile('photo')) {
            try {
                $newPhoto = $this->lifecycle->storePhoto($request->file('photo'));
            } catch (\Throwable $e) {
                Log::error('api.admin.testimonial_photo_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'testimonial_id' => $testimonial->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The photo could not be saved. Please try again.', 500);
            }
        }

        try {
            $this->lifecycle->update($testimonial, $data, $newPhoto);
        } catch (\Throwable $e) {
            Log::error('api.admin.testimonial_update_failed', [
                'admin_id' => $request->user()?->id,
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to update testimonial. Please try again.', 500);
        }

        return $this->ok(['testimonial' => $this->payload($testimonial->fresh())], 'Testimonial updated.');
    }

    public function destroy(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->lifecycle->delete($testimonial);

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

        $this->lifecycle->toggle($testimonial);

        return $this->ok(
            ['testimonial' => $this->payload($testimonial->fresh())],
            $testimonial->status === 'active' ? 'Testimonial activated.' : 'Testimonial deactivated.',
        );
    }

    /**
     * One-shot repair for the live photo mismatch: legacy stored uploads as
     * `data:{mime};base64,…` strings in the same column the home API builds
     * `asset('storage/'.$photo)` from, so every legacy row renders a broken
     * image. The lifecycle service decodes each base64 row to a real file on
     * the public disk and rewrites the column to the stored path; this action
     * logs the actor and maps the run's counts to the response.
     */
    public function backfillPhotos(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $result = $this->lifecycle->backfillLegacyPhotos();

        Log::info('api.admin.testimonial_photo_backfill', [
            'admin_id' => $request->user()?->id,
            'converted' => $result['converted'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
        ]);

        return $this->ok(
            $result,
            $result['converted'] > 0
                ? "Converted {$result['converted']} legacy photo(s) to storage files."
                : 'No legacy photos could be converted.',
        );
    }

    /**
     * Clean the editable text fields, keeping every other validated key (the
     * photo, status and position) on the same payload the service takes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanText(array $data): array
    {
        $data['name'] = $this->plainText($data['name'], 'name', 'Name');
        $data['occupation'] = $this->plainText($data['occupation'], 'occupation', 'Occupation');
        $data['message'] = $this->plainText($data['message'], 'message', 'Message');

        return $data;
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
     * The row payload, shaped by TestimonialResource. Kept as a thin private
     * seam so the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(Testimonial $testimonial): array
    {
        return TestimonialResource::make($testimonial)->resolve();
    }
}
