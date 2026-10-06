<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Rules\ReservedStoreSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorefrontController extends ApiController
{
    use ResolvesManagementContext;

    /** The single delivery route the storefront checkout charges. */
    private const NATIONWIDE_STATE = 'All States';

    private const NATIONWIDE_COUNTRY = 'Nigeria';

    private const NATIONWIDE_DEFAULT_DAYS = 3;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'storefront' => ['nullable', Rule::in(['live', 'offline'])],
            'status' => ['nullable', Rule::in([
                Store::STATUS_PENDING,
                Store::STATUS_ACTIVE,
                Store::STATUS_SUSPENDED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Each call builds a fresh relation — cloning the user's relation
        // would share one underlying query builder across the four counts.
        $scoped = fn () => $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);

        $stats = [
            'total' => $scoped()->count(),
            'live' => $scoped()->where('has_website', true)->count(),
            'offline' => $scoped()->where('has_website', false)->count(),
        ];

        $stores = $scoped()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%")
                ->orWhere('store_id', 'like', "%{$term}%")))
            ->when($filters['storefront'] ?? null, fn ($q, $state) => $q->where('has_website', $state === 'live'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $nationwide = $this->nationwideRoutesByStore($stores->getCollection()->pluck('id'));

        return $this->ok(
            [
                'stores' => $stores->getCollection()
                    ->map(fn (Store $store) => $this->payload($store, $nationwide->get($store->id)))
                    ->values()
                    ->all(),
                'stats' => $stats,
            ],
            null,
            200,
            $this->paginationMeta($stores),
        );
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStorefront($request, $store);

        $store->loadCount(['products', 'orders']);

        $payload = [
            ...$this->payload($store, $this->nationwideRoute($store)),
            'products_count' => (int) ($store->products_count ?? 0),
            'orders_count' => (int) ($store->orders_count ?? 0),
            'delivery_routes_count' => (int) $store->deliveryRoutes()->count(),
            'can_enable' => ! $store->has_website,
        ];

        return $this->ok(['store' => $payload]);
    }

    /**
     * The storefront forms debounce against this: it slugs the name, walks
     * past collisions (-1, -2…) and reports whether the result is the slug the
     * user typed. Unlike legacy it also treats reserved words as unavailable —
     * legacy only checked collisions, so "admin" read "available" and then
     * failed validation on submit.
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'required_without:slug', 'string', 'max:255'],
            'slug' => ['nullable', 'required_without:name', 'string', 'max:255'],
            'ignore_store' => ['nullable', 'string', 'max:64'],
        ]);

        $input = trim((string) ($data['slug'] ?? ''));

        if ($input === '') {
            $input = (string) ($data['name'] ?? '');
        }

        $base = Str::slug($input);

        if ($base === '') {
            return $this->ok(['available' => false, 'slug' => '', 'url' => '', 'original' => '']);
        }

        $ignoreId = null;

        if (! empty($data['ignore_store'])) {
            // The enable modal re-checks the store's own name; without this the
            // store's current slug looks like a collision with itself.
            $ignoreId = $this->user($request)->accessibleStores()
                ->where('store_id', $data['ignore_store'])
                ->value('id');
        }

        $slug = $base;
        $counter = 1;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base.'-'.$counter++;
        }

        return $this->ok([
            'available' => $slug === $base,
            'slug' => $slug,
            'url' => $this->storefrontUrl($slug),
            'original' => $base,
        ]);
    }

    public function enableWebsite(Request $request, Store $store): JsonResponse
    {
        return $this->enable($request, $store);
    }

    /**
     * The wizard submit. Legacy validated a `template` field (`required|in:basic`)
     * and then never persisted it; the chooser is deliberately dropped rather
     * than cloned, because no per-store theme exists in the schema or in any
     * storefront renderer. An inbound `template` is ignored, not rejected.
     */
    public function store(Request $request, Store $store): JsonResponse
    {
        return $this->enable($request, $store);
    }

    private function enable(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStorefront($request, $store);

        // Legacy's enableWebsite had no has_website guard, so a direct POST
        // renamed and re-slugged an already-live store (verify correction 5).
        // Re-enabling is refused deliberately: the live URL is the store's
        // identity, and delivery/name edits belong on the settings surface.
        if ($store->has_website) {
            $message = 'This store already has an online storefront.';

            return $this->error($message, 422, ['store' => [$message]]);
        }

        $data = $this->validated($request, $store);

        DB::transaction(function () use ($store, $data) {
            $store->update([
                'name' => $data['store_name'],
                'slug' => $data['slug'],
                'has_website' => true,
            ]);

            $this->syncNationwideDelivery($store, $data);
        });

        Log::info('api.management.storefront_enabled', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
        ]);

        return $this->ok(
            ['store' => $this->payload($store->fresh(), $this->nationwideRoute($store))],
            'Storefront enabled at '.$this->storefrontUrl($store->slug).'.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store): array
    {
        // Slugs arrive pre-checked from the availability endpoint, but a hand
        // typed "My Shop" must normalise here too — and the unique rule has to
        // see the value that will actually be written.
        $request->merge([
            'slug' => Str::slug($request->filled('slug')
                ? $request->string('slug')->toString()
                : $request->string('store_name')->toString()),
        ]);

        return $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                new ReservedStoreSlug,
                Rule::unique('stores', 'slug')->ignore($store->getKey()),
            ],
            'is_nationwide' => ['nullable', 'boolean'],
            'nationwide_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'nationwide_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
    }

    /**
     * Upserts the "All States" route the storefront checkout charges.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncNationwideDelivery(Store $store, array $data): void
    {
        if (! ($data['is_nationwide'] ?? false)) {
            return;
        }

        $route = $store->deliveryRoutes()->updateOrCreate(
            ['state' => self::NATIONWIDE_STATE, 'country' => self::NATIONWIDE_COUNTRY],
            [
                // Legacy wrote area = null into a NOT NULL column, which fails
                // under MySQL strict mode; '' is the honest value for a route
                // that covers every state.
                'area' => '',
                'fee' => (int) round((float) ($data['nationwide_fee'] ?? 0) * 100),
                'delivery_days' => (int) ($data['nationwide_days'] ?? self::NATIONWIDE_DEFAULT_DAYS),
                'active' => true,
            ],
        );

        // business_id is not fillable on DeliveryRoute, so legacy's
        // updateOrCreate silently dropped it and left the row unscoped.
        $route->business_id = $store->business_id;
        $route->save();
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        if (in_array($slug, config('storefront.reserved_subdomains', []), true)) {
            return true;
        }

        return Store::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * @param  Collection<int, int>  $storeIds
     * @return Collection<int, DeliveryRoute>
     */
    private function nationwideRoutesByStore(Collection $storeIds): Collection
    {
        if ($storeIds->isEmpty()) {
            return collect();
        }

        return DeliveryRoute::query()
            ->whereIn('store_id', $storeIds)
            ->where('state', self::NATIONWIDE_STATE)
            ->where('country', self::NATIONWIDE_COUNTRY)
            ->get()
            ->keyBy('store_id');
    }

    private function nationwideRoute(Store $store): ?DeliveryRoute
    {
        return $store->deliveryRoutes()
            ->where('state', self::NATIONWIDE_STATE)
            ->where('country', self::NATIONWIDE_COUNTRY)
            ->first();
    }

    private function authorizeStorefront(Request $request, Store $store): void
    {
        $this->authorizeStore($request, $store);

        // A business owner's `stores()` relation still contains soft-deleted
        // rows, and a deleted store cannot host a storefront.
        if ($store->status === Store::STATUS_DELETED) {
            abort(404, 'This store no longer exists.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Store $store, ?DeliveryRoute $nationwide = null): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'storefront_url' => $store->has_website && $store->slug ? $this->storefrontUrl($store->slug) : null,
            'logo_url' => $store->logoUrl(),
            'description' => $store->description,
            'nationwide_delivery' => $nationwide === null ? null : [
                'id' => $nationwide->id,
                'state' => $nationwide->state,
                'country' => $nationwide->country,
                'fee' => (int) $nationwide->fee,
                'delivery_days' => (int) $nationwide->delivery_days,
                'active' => (bool) $nationwide->active,
            ],
        ];
    }

    private function storefrontUrl(string $slug): string
    {
        // Same rule the legacy links used: local dev serves storefronts from
        // the root domain, everything else from {slug}.{main_domain}.
        if (app()->environment('local')) {
            return url($slug);
        }

        $domain = config('app.main_domain', parse_url((string) config('app.url'), PHP_URL_HOST));

        return 'https://'.$slug.'.'.$domain;
    }
}
