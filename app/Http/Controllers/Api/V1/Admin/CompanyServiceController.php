<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListCompanyServicesRequest;
use App\Http\Requests\Admin\ReorderCompanyServicesRequest;
use App\Http\Requests\Admin\StoreCompanyServiceRequest;
use App\Http\Requests\Admin\UpdateCompanyServiceRequest;
use App\Http\Resources\Admin\CompanyServiceResource;
use App\Models\CompanyService;
use App\Repositories\Admin\CompanyServiceRepository;
use App\Services\Admin\CompanyServiceLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 *   collided on the unique index (a 500). {@see CompanyServiceWriteRequest}
 *   normalises before the rules run, so uniqueness, the scheme/`..` guards and
 *   the stored value all see the same string.
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
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListCompanyServicesRequest` for the list filters, the
 * `Store`/`UpdateCompanyServiceRequest` pair for the create/edit payload and
 * `ReorderCompanyServicesRequest` for the drag payload), query building in
 * CompanyServiceRepository, the write workflows and their transaction
 * boundaries in CompanyServiceLifecycleService, and response shaping in
 * `CompanyServiceResource`; the platform-admin guard deliberately stays here
 * so its order is unchanged.
 *
 * Text sanitation stays here too, on purpose: a markup-only title/description
 * must fail as a normal 422 *before* an upload reaches the disk (the
 * "no orphaned upload" invariant), and the service's transaction-failure
 * catch would otherwise swallow that ValidationException.
 */
class CompanyServiceController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * The public nav cache the home views read, re-exported from the lifecycle
     * service that busts it (legacy key, kept verbatim so the legacy view
     * composer and the new consumers share one invalidation).
     */
    public const NAV_CACHE_KEY = CompanyServiceLifecycleService::NAV_CACHE_KEY;

    public function __construct(
        private readonly CompanyServiceRepository $services,
        private readonly CompanyServiceLifecycleService $lifecycle,
    ) {}

    public function index(ListCompanyServicesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $services = $this->services->paginateForAdmin($request->validated());

        return $this->ok(
            [
                'services' => $services->getCollection()
                    ->map(fn (CompanyService $service) => $this->payload($service))
                    ->values()
                    ->all(),
                'counts' => $this->services->counts(),
            ],
            null,
            200,
            $this->paginationMeta($services),
        );
    }

    public function store(StoreCompanyServiceRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $data['title'] = $this->plainText($data['title'], 'title', 'Title');
        $data['description'] = $this->plainTextOrNull($data['description'] ?? null);

        $image = null;

        if ($request->hasFile('background_image')) {
            try {
                $image = $this->lifecycle->storeImage($request->file('background_image'));
            } catch (\Throwable $e) {
                Log::error('api.admin.company_service_image_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The background image could not be saved. Please try again.', 500);
            }
        }

        try {
            $service = $this->lifecycle->create($data, $image);
        } catch (\Throwable $e) {
            Log::error('api.admin.company_service_create_failed', [
                'admin_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to create service. Please try again.', 500);
        }

        Log::info('api.admin.company_service_created', [
            'admin_id' => $request->user()?->id,
            'service_id' => $service->id,
        ]);

        return $this->ok(['service' => $this->payload($service)], 'Service created.', 201);
    }

    public function update(UpdateCompanyServiceRequest $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        // Clean the text first, so a markup-only field is a normal 422 and
        // never reaches the disk as an orphaned upload.
        $data['title'] = $this->plainText($data['title'], 'title', 'Title');
        $data['description'] = $this->plainTextOrNull($data['description'] ?? null);

        $newImage = null;

        if ($request->hasFile('background_image')) {
            try {
                $newImage = $this->lifecycle->storeImage($request->file('background_image'));
            } catch (\Throwable $e) {
                Log::error('api.admin.company_service_image_store_failed', [
                    'admin_id' => $request->user()?->id,
                    'service_id' => $companyService->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('The background image could not be saved. Please try again.', 500);
            }
        }

        try {
            $this->lifecycle->update($companyService, $data, $newImage);
        } catch (\Throwable $e) {
            Log::error('api.admin.company_service_update_failed', [
                'admin_id' => $request->user()?->id,
                'service_id' => $companyService->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to update service. Please try again.', 500);
        }

        return $this->ok(['service' => $this->payload($companyService->fresh())], 'Service updated.');
    }

    public function destroy(Request $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->lifecycle->delete($companyService);

        Log::info('api.admin.company_service_deleted', [
            'admin_id' => $request->user()?->id,
            'service_id' => $companyService->id,
        ]);

        return $this->ok([], 'Service deleted.');
    }

    public function toggle(Request $request, CompanyService $companyService): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->lifecycle->toggle($companyService);

        return $this->ok(
            ['service' => $this->payload($companyService->fresh())],
            $companyService->status === 'active' ? 'Service activated.' : 'Service deactivated.',
        );
    }

    /**
     * Drag-and-drop reorder. The SPA posts the visible page as `items` with
     * the order value each row should take, so a page-2 drag stays consistent
     * with page 1 (legacy rewrote order values from 0 per request, which
     * collided across pages). Unknown ids are reported here, before the
     * transaction starts.
     */
    public function reorder(ReorderCompanyServicesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $ids = collect($data['items'])->pluck('id')->all();
        $missing = $this->services->missingIds($ids);

        if ($missing !== []) {
            return $this->error(
                'Some services no longer exist. Refresh the list and try again.',
                422,
                ['items' => ['Unknown service id(s): '.implode(', ', $missing).'.']],
            );
        }

        $this->lifecycle->reorder($data['items']);

        Log::info('api.admin.company_services_reordered', [
            'admin_id' => $request->user()?->id,
            'items' => $data['items'],
        ]);

        return $this->ok(['updated' => count($data['items'])], 'Service order updated.');
    }

    /**
     * Store free text as plain text — the office panel and the public site
     * both render it, so markup never survives the write.
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

    private function plainTextOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($this->stripMarkup($value));

        return $clean === '' ? null : $clean;
    }

    /**
     * `strip_tags()` removes the tags but leaves the *contents* of `<script>` /
     * `<style>` elements behind as loose text ("alert(1)"). Copy is stored as
     * plain text, so the element bodies are dropped first and the remaining
     * tag skeleton is stripped afterwards — the same convention the
     * testimonial writer uses.
     */
    private function stripMarkup(string $value): string
    {
        return strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value));
    }

    /**
     * The row payload, shaped by CompanyServiceResource. Kept as a thin
     * private seam so the response sites read as they did before the
     * extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(CompanyService $service): array
    {
        return CompanyServiceResource::make($service)->resolve();
    }
}
