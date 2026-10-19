<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StorefrontSettings\StoreStorefrontPageRequest;
use App\Http\Requests\Management\StorefrontSettings\UpdateStorefrontPageRequest;
use App\Http\Resources\Management\StorefrontSettings\StorefrontPageResource;
use App\Models\Store;
use App\Repositories\Management\StorefrontSettingsRepository;
use App\Services\Management\StorefrontSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-58 — owner-written storefront pages.
 *
 * `{page}` is a plain id, not a route-model binding: it is resolved through the
 * store relation, so a page belonging to another store is a 404 rather than an
 * edit target. The route constrains it to digits for the same reason the
 * address book does.
 */
class StorefrontPageController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StorefrontSettingsRepository $repository,
        private readonly StorefrontSettingsService $service,
    ) {}

    public function show(Request $request, Store $store, string $page): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $model = $this->repository->pageFor($store, (int) $page);

        return $this->ok((new StorefrontPageResource($model))->resolve($request));
    }

    public function store(StoreStorefrontPageRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $page = $this->service->createPage($store, $request->validated());

        Log::info('api.management.storefront_page_created', [
            'store_id' => $store->id,
            'page_id' => $page->id,
            'user_id' => $request->user()?->id,
        ]);

        return $this->ok(
            (new StorefrontPageResource($page))->resolve($request),
            'Page created.',
            201,
        );
    }

    public function update(UpdateStorefrontPageRequest $request, Store $store, string $page): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $model = $this->repository->pageFor($store, (int) $page);
        $model = $this->service->updatePage($model, $request->validated());

        Log::info('api.management.storefront_page_updated', [
            'store_id' => $store->id,
            'page_id' => $model->id,
            'user_id' => $request->user()?->id,
        ]);

        return $this->ok(
            (new StorefrontPageResource($model))->resolve($request),
            'Page saved.',
        );
    }

    public function destroy(Request $request, Store $store, string $page): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $model = $this->repository->pageFor($store, (int) $page);
        $this->service->deletePage($model);

        Log::info('api.management.storefront_page_deleted', [
            'store_id' => $store->id,
            'page_id' => $model->id,
            'user_id' => $request->user()?->id,
        ]);

        return $this->ok([], 'Page deleted.');
    }
}
