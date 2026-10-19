<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StorefrontEnableRequest;
use App\Http\Requests\Management\StorefrontIndexRequest;
use App\Http\Requests\Management\StorefrontSlugUpdateRequest;
use App\Http\Requests\Management\StoreSlugCheckRequest;
use App\Http\Resources\Management\StorefrontResource;
use App\Models\Store;
use App\Repositories\Management\StorefrontRepository;
use App\Services\Management\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WS-05 — storefront enablement.
 *
 * Layering: this class keeps the HTTP shape — status codes, message strings,
 * the envelope, the pagination meta and the 403/404 store guards, whose order
 * is asserted. The overview filters, the slug check and the enable payloads
 * validate in StorefrontIndexRequest / StoreSlugCheckRequest /
 * StorefrontEnableRequest, the scoped store reads, the nation-wide route
 * lookups and the slug walk live in
 * App\Repositories\Management\StorefrontRepository, the enable write and its
 * transaction in App\Services\Management\StorefrontService, and the store row
 * shape in Management\StorefrontResource.
 *
 * The detail screen's `loadCount()` and per-store delivery-route count stay in
 * this body: each is a single Eloquent call with a single call site, so a
 * repository wrapper would be indirection without benefit.
 */
class StorefrontController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StorefrontRepository $stores,
        private readonly StorefrontService $storefront,
    ) {}

    public function index(StorefrontIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);

        $stats = $this->stores->stats($user);

        $stores = $this->stores->paginateForUser($user, $filters);

        $nationwide = $this->stores->nationwideRoutesByStore($stores->getCollection()->pluck('id'));

        return $this->ok(
            [
                'stores' => $stores->getCollection()
                    ->map(fn (Store $store) => (new StorefrontResource($store, $nationwide->get($store->id)))->resolve($request))
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
            ...(new StorefrontResource($store, $this->stores->nationwideRoute($store)))->resolve($request),
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
    public function checkSlug(StoreSlugCheckRequest $request): JsonResponse
    {
        $data = $request->validated();

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
            $ignoreId = $this->stores->accessibleStoreIdByPublicId($this->user($request), $data['ignore_store']);
        }

        $slug = $this->stores->availableSlug($base, $ignoreId);

        return $this->ok([
            'available' => $slug === $base,
            'slug' => $slug,
            'url' => StorefrontResource::urlFor($slug),
            'original' => $base,
        ]);
    }

    public function enableWebsite(StorefrontEnableRequest $request, Store $store): JsonResponse
    {
        return $this->enable($request, $store);
    }

    /**
     * The wizard submit. Legacy validated a `template` field (`required|in:basic`)
     * and then never persisted it; the chooser is deliberately dropped rather
     * than cloned, because no per-store theme exists in the schema or in any
     * storefront renderer. An inbound `template` is ignored, not rejected.
     */
    public function store(StorefrontEnableRequest $request, Store $store): JsonResponse
    {
        return $this->enable($request, $store);
    }

    private function enable(StorefrontEnableRequest $request, Store $store): JsonResponse
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

        $this->storefront->enable($store, $request->validated());

        Log::info('api.management.storefront_enabled', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
        ]);

        return $this->ok(
            ['store' => (new StorefrontResource($store->fresh(), $this->stores->nationwideRoute($store)))->resolve($request)],
            'Storefront enabled at '.StorefrontResource::urlFor($store->slug).'.',
        );
    }

    /**
     * The off-switch, and the mirror image of the enable path.
     *
     * What it does *not* do is as deliberate as what it does: the slug and the
     * nation-wide delivery route both survive (see StorefrontService::disable),
     * so switching back on lands the storefront on the address it had, and
     * customers who bookmarked it are not sent to a dead host. The refusal for
     * an already-off store mirrors enable's "already has an online storefront".
     */
    public function disableWebsite(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStorefront($request, $store);

        if (! $store->has_website) {
            $message = 'This store has no online storefront to take offline.';

            return $this->error($message, 422, ['store' => [$message]]);
        }

        $url = StorefrontResource::urlFor($store->slug);

        $this->storefront->disable($store);

        Log::info('api.management.storefront_disabled', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
        ]);

        return $this->ok(
            ['store' => (new StorefrontResource($store->fresh(), $this->stores->nationwideRoute($store)))->resolve($request)],
            'Storefront taken offline. '.$url.' no longer serves customers.',
        );
    }

    /**
     * Change a live storefront's public address.
     *
     * Enabling is refused once a storefront is live, and rightly so — it also
     * rewrites the store name and the nation-wide delivery route, and running
     * it again would move a live shop out from under every link already
     * shared. But that left the address itself frozen for good. This is the
     * narrow alternative: one field, one column.
     *
     * The old address stops resolving the moment this returns. There is no
     * alias table and the subdomain is the whole of the lookup key, which is
     * why the console warns about it before submitting.
     *
     * Resubmitting the current address is a success rather than a 422: it
     * changes nothing, and a double submit should not look like a failure.
     */
    public function updateSlug(StorefrontSlugUpdateRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStorefront($request, $store);

        if (! $store->has_website) {
            $message = 'This store has no online storefront to rename.';

            return $this->error($message, 422, ['store' => [$message]]);
        }

        $slug = (string) $request->validated('slug');

        if ($slug === $store->slug) {
            return $this->ok(
                ['store' => (new StorefrontResource($store, $this->stores->nationwideRoute($store)))->resolve($request)],
                'The storefront address is unchanged.',
            );
        }

        $previous = $store->slug;

        $this->storefront->updateSlug($store, $slug);

        Log::info('api.management.storefront_slug_changed', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'from' => $previous,
            'to' => $slug,
        ]);

        return $this->ok(
            ['store' => (new StorefrontResource($store->fresh(), $this->stores->nationwideRoute($store)))->resolve($request)],
            'Storefront address changed to '.StorefrontResource::urlFor($slug).'.',
        );
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
}
