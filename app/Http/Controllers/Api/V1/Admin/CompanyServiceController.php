<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\CompanyService;
use App\Services\ActivityRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-18 (admin console) — company services (services CRUD + toggle + reorder).
 *
 * Rebuilds `/office/company-services`: the ordered list with drag handles and
 * a Visit action, the create/edit modal (title, description, page link with a
 * rendered `https://host/` prefix, background image, status), activate /
 * deactivate, delete and drag-and-drop reorder. This is the one content type
 * WS-18 rebuilds that has a live new-stack consumer — `Api\V1\Home\HomeController
 * ::services()` already serves active rows and the public nav view composer
 * reads the `nav_company_services` cache — so every mutation busts that cache
 * exactly as legacy did.
 *
 * Fixed rather than cloned:
 *
 * - **Page links are validated after normalisation.** Legacy validated
 *   uniqueness on the raw input and stripped the leading slash afterwards, so
 *   `/about` and `about` passed validation as different values and then
 *   collided on the unique index (a 500). The request is normalised before
 *   validation here, so uniqueness, the scheme/`..` guards and the stored
 *   value all see the same string.
 * - **Reorder validation errors are 422s, not 500s.** Legacy wrapped
 *   `validate()` inside the try/catch and answered every failure — including
 *   validation — with `{"success": false}` and a 500. Bad payloads now get a
 *   normal validation response, and ids that no longer exist are reported
 *   before the transaction starts.
 * - **Image replacement is ordered safely.** Legacy deleted the old file
 *   before storing the new one; a failed upload destroyed both. The new file
 *   is stored first and the old one is removed only once the row is saved.
 * - **Every mutation is audited** through ActivityRecorder (legacy wrote Log
 *   lines only), and the public cache is busted after the transaction commits.
 */
class CompanyServiceController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * The public nav cache the home views read (legacy key, kept verbatim so
     * the legacy view composer and the new consumers share one invalidation).
     */
    public const NAV_CACHE_KEY = 'nav_company_services';

    private const STATUSES = ['active', 'inactive'];

    /**
     * Whitelisted sort columns.
     */
    private const SORTS = ['order', 'title', 'created_at', 'updated_at', 'id'];

    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];

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
        $sort = $filters['sort'] ?? 'order';
        $direction = $filters['direction'] ?? 'asc';

        $services = CompanyService::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('page_link', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            // Stable tiebreak for paginated drag-reorder.
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'services' => $services->getCollection()
                    ->map(fn (CompanyService $service) => $this->payload($service))
                    ->values()
                    ->all(),
                'counts' => $this->counts(),
            ],
            null,
            200,
            $this->paginationMeta($services),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request);

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $title = $this->plainText($data['title'], 'title', 'Title');
        $description = $this->plainTextOrNull($data['description'] ?? null);

        $image = null;

        if ($request->hasFile('background_image')) {
            try {
                $image = $request->file('background_image')->store('company_services', 'public');
            } catch (\Throwable $e) {
                Log::error('api.admin.company_service_image_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The background image could not be saved. Please try again.', 500);
            }
        }

        try {
            $service = DB::transaction(function () use ($data, $title, $description, $image) {
                $service = CompanyService::create([
                    'order' => $data['order'] ?? 0,
                    'title' => $title,
                    'description' => $description,
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
            $this->deleteImageFile($image);

            Log::error('api.admin.company_service_create_failed', [
                'admin_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to create service. Please try again.', 500);
        }

        $this->forgetNavCache();

        Log::info('api.admin.company_service_created', [
            'admin_id' => $request->user()?->id,
            'service_id' => $service->id,
        ]);

        return $this->ok(['service' => $this->payload($service)], 'Service created.', 201);
    }

    public function update(Request $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request, $companyService);

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $title = $this->plainText($data['title'], 'title', 'Title');
        $description = $this->plainTextOrNull($data['description'] ?? null);

        $newImage = null;

        if ($request->hasFile('background_image')) {
            try {
                $newImage = $request->file('background_image')->store('company_services', 'public');
            } catch (\Throwable $e) {
                Log::error('api.admin.company_service_image_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'service_id' => $companyService->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The background image could not be saved. Please try again.', 500);
            }
        }

        // See TestimonialController::update — the replaced file is removed
        // only after the transaction commits, so a rollback keeps the row and
        // its old image consistent.
        $previousImage = $companyService->background_image_path;

        try {
            DB::transaction(function () use ($companyService, $data, $title, $description, $newImage) {
                $before = $this->auditValues($companyService);

                $attributes = [
                    'order' => $data['order'] ?? 0,
                    'title' => $title,
                    'description' => $description,
                    'page_link' => $data['page_link'] ?? null,
                    'status' => $data['status'],
                ];

                if ($newImage !== null) {
                    $attributes['background_image_path'] = $newImage;
                }

                $companyService->update($attributes);

                ActivityRecorder::record(
                    action: 'company_service_updated',
                    description: "Company service {$companyService->title} updated.",
                    subject: $companyService,
                    old: $before,
                    new: $this->auditValues($companyService),
                );
            });
        } catch (\Throwable $e) {
            $this->deleteImageFile($newImage);

            Log::error('api.admin.company_service_update_failed', [
                'admin_id' => $request->user()?->id,
                'service_id' => $companyService->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to update service. Please try again.', 500);
        }

        // The replacement is committed; the superseded file can go now.
        if ($newImage !== null && $previousImage !== $newImage) {
            $this->deleteImageFile($previousImage);
        }

        $this->forgetNavCache();

        return $this->ok(['service' => $this->payload($companyService->fresh())], 'Service updated.');
    }

    public function destroy(Request $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $image = $companyService->background_image_path;

        DB::transaction(function () use ($companyService) {
            ActivityRecorder::record(
                action: 'company_service_deleted',
                description: "Company service {$companyService->title} deleted.",
                subject: $companyService,
                old: $this->auditValues($companyService),
            );

            $companyService->delete();
        });

        // Legacy deleted the file too; keep that, but after the row is gone.
        $this->deleteImageFile($image);
        $this->forgetNavCache();

        Log::info('api.admin.company_service_deleted', [
            'admin_id' => $request->user()?->id,
            'service_id' => $companyService->id,
        ]);

        return $this->ok([], 'Service deleted.');
    }

    public function toggle(Request $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        DB::transaction(function () use ($companyService) {
            $before = ['status' => $companyService->status];

            $companyService->update([
                'status' => $companyService->status === 'active' ? 'inactive' : 'active',
            ]);

            ActivityRecorder::record(
                action: 'company_service_toggled',
                description: "Company service {$companyService->title} set to {$companyService->status}.",
                subject: $companyService,
                old: $before,
                new: ['status' => $companyService->status],
            );
        });

        $this->forgetNavCache();

        return $this->ok(
            ['service' => $this->payload($companyService->fresh())],
            $companyService->status === 'active' ? 'Service activated.' : 'Service deactivated.',
        );
    }

    /**
     * Drag-and-drop reorder. The SPA posts the visible page as `items` with
     * the order value each row should take, so a page-2 drag stays consistent
     * with page 1 (legacy rewrote order values from 0 per request, which
     * collided across pages).
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ]);

        $ids = collect($data['items'])->pluck('id')->all();
        $existing = CompanyService::query()->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_values(array_diff($ids, $existing));

        if ($missing !== []) {
            return $this->error(
                'Some services no longer exist. Refresh the list and try again.',
                422,
                ['items' => ['Unknown service id(s): '.implode(', ', $missing).'.']],
            );
        }

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                CompanyService::query()
                    ->whereKey($item['id'])
                    ->update(['order' => $item['order']]);
            }

            ActivityRecorder::record(
                action: 'company_services_reordered',
                description: 'Company services reordered.',
                new: ['items' => $data['items']],
            );
        });

        $this->forgetNavCache();

        Log::info('api.admin.company_services_reordered', [
            'admin_id' => $request->user()?->id,
            'items' => $data['items'],
        ]);

        return $this->ok(['updated' => count($data['items'])], 'Service order updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CompanyService $service = null): array
    {
        // Normalise the page link BEFORE validating: legacy validated the raw
        // input and stripped the leading slash afterwards, so "/about" and
        // "about" both passed uniqueness and then collided on the DB index.
        // Non-string input is left untouched so the `string` rule reports it.
        if (is_string($request->input('page_link'))) {
            $request->merge(['page_link' => $this->normalizePageLink($request->input('page_link'))]);
        }

        return $request->validate([
            'order' => ['nullable', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'page_link' => [
                'nullable',
                'string',
                'max:255',
                // A stored link is a site-relative path, never a scheme or a
                // traversal: the office renders it as `https://host/<link>`.
                function (string $attribute, mixed $value, Closure $fail) {
                    // The `string` rule rejects non-strings; guard anyway so an
                    // array input cannot reach preg_match/str_contains below
                    // and turn a 422 into a 500.
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1) {
                        $fail('The page link must be a path on this site, not a full URL or scheme.');
                    }

                    if (str_contains($value, '..')) {
                        $fail('The page link cannot contain "..".');
                    }

                    if (preg_match('/\s/', $value) === 1) {
                        $fail('The page link cannot contain spaces.');
                    }
                },
                $service === null
                    ? Rule::unique('company_services', 'page_link')
                    : Rule::unique('company_services', 'page_link')->ignore($service->id),
            ],
            'background_image' => self::IMAGE_RULES,
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);
    }

    /**
     * `https://host//about/` -> `about`; empty input -> null. Legacy's
     * normalisation, kept, now applied before validation.
     */
    private function normalizePageLink(string $value): ?string
    {
        $link = trim($value);
        $link = ltrim($link, '/');

        return $link === '' ? null : $link;
    }

    private function forgetNavCache(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
    }

    /**
     * Removes an uploaded background image from disk.
     */
    private function deleteImageFile(?string $path): void
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

    /**
     * Store free text as plain text — the office panel and the public site
     * both render it, so markup never survives the write.
     */
    private function plainText(string $value, string $field, string $label): string
    {
        $clean = trim(strip_tags($value));

        if ($clean === '') {
            throw ValidationException::withMessages([
                $field => ["{$label} cannot be only HTML markup."],
            ]);
        }

        return $clean;
    }

    private function plainTextOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(strip_tags($value));

        return $clean === '' ? null : $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CompanyService $service): array
    {
        return [
            'id' => $service->id,
            'order' => (int) $service->order,
            'title' => $service->title,
            'description' => $service->description,
            'page_link' => $service->page_link,
            // Absolute target for the Visit action; the SPA prefers its
            // runtime HOME_URL when the marketing site lives elsewhere.
            'page_url' => $service->page_link ? url('/'.$service->page_link) : null,
            'image_url' => $service->background_image_path ? asset('storage/'.$service->background_image_path) : null,
            'has_image' => (bool) $service->background_image_path,
            'status' => $service->status,
            'created_at' => $service->created_at?->toISOString(),
            'updated_at' => $service->updated_at?->toISOString(),
        ];
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

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = CompanyService::query()->selectRaw("
            count(*) as total,
            sum(case when status = 'active' then 1 else 0 end) as active,
            sum(case when status = 'inactive' then 1 else 0 end) as inactive,
            sum(case when page_link is not null then 1 else 0 end) as linked
        ")->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
            'linked' => (int) ($counts->linked ?? 0),
        ];
    }
}
