<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-30 — Services catalogue.
 *
 * The storefront has been publicly serving services
 * (`Api\V1\Storefront\CatalogController@services`) while the business had no
 * way to create or edit one. This is that missing management half: CRUD over
 * the legacy `services` table, scoped to the caller's accessible stores.
 *
 * Deliberate departures from the legacy `Management\ServiceController`:
 * - routes bind by `service_code` (legacy parity — the model already declares
 *   it as the route key);
 * - `update()` validates the store, the status enum and any `primary_image_id`
 *   against the service itself, all of which legacy skipped;
 * - the "no accessible stores" branch returns an empty page plus an empty
 *   `stores` list instead of legacy's 500 (it dereferenced an undefined
 *   `$breadcrumbs` on that path);
 * - `amount` is the legacy decimal-naira column (like products), so it is
 *   returned as-is and the SPA formats it from the currency relation rather
 *   than the hardcoded ₦ the legacy views printed.
 */
class ServiceController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $services = Service::query()
            ->whereIn('store_id', $this->accessibleStoreIds($request))
            ->with(['store', 'images', 'currency'])
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, $term) {
                $term = trim((string) $term);
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('service_code', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'services' => $services->getCollection()->map(fn (Service $service) => $this->summary($service))->values()->all(),
                // Legacy's "no stores" banner never rendered because its branch
                // 500'd; the SPA uses this list for both the store filter and
                // the create-a-store empty state.
                'stores' => $this->accessibleStores($request)->map(fn (Store $store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                ])->values()->all(),
            ],
            null,
            200,
            $this->paginationMeta($services)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $this->accessibleStoreIds($request)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $service = DB::transaction(function () use ($request, $user, $data) {
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

        $this->logActivity($request, 'service_created', 'Service created', $service);

        return $this->ok(['service' => $this->detail($service->fresh())], 'Service created.', 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        return $this->ok(['service' => $this->detail($service)]);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            // Legacy persisted any store id and any status string here; both
            // are validated now rather than cloned (verify pass corrections
            // #5/#6).
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'primary_image_id' => ['nullable', 'integer'],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
            'delete_image_ids' => ['nullable', 'array'],
            'delete_image_ids.*' => ['integer'],
        ]);

        if (! $this->accessibleStoreIds($request)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        // The image must already belong to this service. Legacy validated only
        // `exists:service_images,id`, which let a caller point at another
        // service's picture (a silent no-op against a scoped update).
        $primaryId = $data['primary_image_id'] ?? null;

        if ($primaryId !== null && ! $service->images()->whereKey($primaryId)->exists()) {
            return $this->error('The selected primary image does not belong to this service.', 422);
        }

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

        $this->logActivity($request, 'service_updated', 'Service updated', $service);

        return $this->ok(['service' => $this->detail($service->fresh())], 'Service updated.');
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        $images = $service->images()->get();

        DB::transaction(fn () => $service->delete());

        foreach ($images as $image) {
            $this->deleteImageFile($image);
        }

        // Legacy removed services without a trace; the audit trail now records
        // the deletion too (catalog-org feature 16).
        $this->logActivity($request, 'service_deleted', 'Service deleted', $service);

        return $this->ok([], 'Service deleted.');
    }

    /**
     * Stores the caller may put a service in: accessible and not soft-deleted,
     * mirroring the legacy `status != deleted` filter (and the WS-06 fix that
     * stopped deleted records leaking back into lists).
     *
     * @return Collection<int, int>
     */
    private function accessibleStoreIds(Request $request)
    {
        return $this->accessibleStores($request)->pluck('stores.id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    private function accessibleStores(Request $request)
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    private function authorizeService(Request $request, Service $service): void
    {
        $user = $this->user($request);

        // Rows written before the multi-tenant migration carry a null
        // business_id; the store check below is the decisive scope for both
        // cases.
        if ($service->business_id !== null && (int) $service->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this service.');
        }

        if (! $this->accessibleStoreIds($request)->contains((int) $service->store_id)) {
            abort(403, 'You do not have access to this service.');
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

    private function logActivity(Request $request, string $action, string $description, Service $service): void
    {
        $user = $this->user($request);

        ActivityLog::create([
            'user_id' => $user->id,
            'business_id' => $service->business_id ?? $user->business_id,
            'action' => $action,
            'subject_type' => Service::class,
            'subject_id' => $service->id,
            'description' => $description,
            'metadata' => [
                'user_id' => $user->id,
                'service_id' => $service->id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Service $service): array
    {
        $primary = $service->primaryImage();

        return [
            'id' => $service->id,
            'service_code' => $service->service_code,
            'name' => $service->name,
            'slug' => $service->slug,
            'description' => $service->description,
            'amount' => (float) $service->amount,
            'status' => $service->status,
            'currency' => $service->currency ? [
                'id' => $service->currency->id,
                'code' => $service->currency->code,
                'symbol' => $service->currency->symbol,
            ] : null,
            'store' => $service->store ? [
                'id' => $service->store->id,
                'store_id' => $service->store->store_id,
                'name' => $service->store->name,
            ] : null,
            'primary_image' => $primary?->path ? asset('storage/'.$primary->path) : null,
            'images_count' => $service->images->count(),
            'created_at' => $service->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Service $service): array
    {
        $service->loadMissing(['store', 'images', 'currency']);

        return [
            ...$this->summary($service),
            'images' => $service->images->map(fn (ServiceImage $image) => [
                'id' => $image->id,
                'url' => asset('storage/'.$image->path),
                'is_primary' => (bool) $image->is_primary,
                'position' => (int) $image->position,
            ])->values()->all(),
        ];
    }
}
