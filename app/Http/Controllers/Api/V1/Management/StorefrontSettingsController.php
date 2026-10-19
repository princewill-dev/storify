<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StorefrontSettings\UpdateStorefrontSettingsRequest;
use App\Http\Resources\Management\StorefrontSettings\StorefrontSettingsResource;
use App\Models\Store;
use App\Repositories\Management\StorefrontSettingsRepository;
use App\Services\Management\StorefrontSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-58 — the storefront's site metadata and page list.
 *
 * The GET is deliberately ungated by plan: a business that lost the storefront
 * entitlement can still see what its shop is serving, and the public pages
 * keep serving regardless. The writes carry `plan.feature:storefront`, the
 * same rule ws05 applies to enabling and renaming.
 */
class StorefrontSettingsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StorefrontSettingsRepository $repository,
        private readonly StorefrontSettingsService $service,
    ) {}

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        return $this->ok($this->workspace($request, $store));
    }

    public function update(UpdateStorefrontSettingsRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $this->service->updateSettings(
            $store,
            $request->validated(),
            $request->file('og_image'),
        );

        Log::info('api.management.storefront_settings_updated', [
            'store_id' => $store->id,
            'user_id' => $request->user()?->id,
        ]);

        return $this->ok($this->workspace($request, $store), 'Storefront settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function workspace(Request $request, Store $store): array
    {
        return (new StorefrontSettingsResource(
            $store,
            $this->repository->settingsFor($store),
            $this->repository->pagesFor($store),
        ))->resolve($request);
    }
}
