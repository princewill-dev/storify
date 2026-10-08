<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Plugins\UpdatePluginRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Access\TenantGuard;
use App\Services\Management\PluginService;
use App\Services\Management\PluginVerifier;
use App\Services\Plugins\PluginResolver;
use App\Support\Plugins\PluginRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WS-38 — Plugins (third-party marketing and analytics integrations).
 *
 * The catalogue is not stored; it is read from {@see PluginRegistry} on every
 * request, so adding a service is a code change and never a migration. What is
 * stored is only which plugins a business — or one of its stores — has
 * connected, and the values that service issued them.
 *
 * Scoping follows the payments precedent: business-wide by default, overridable
 * per store, with a `store_plugins` row winning over `business_plugins` for the
 * same key. See {@see PluginResolver}.
 */
final class PluginController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly PluginResolver $resolver,
        private readonly PluginService $service,
        private readonly PluginVerifier $verifier,
        private readonly TenantGuard $guard,
    ) {}

    /**
     * The catalogue, plus the state of every plugin for the current scope.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $store = $this->resolveStore($request, $user);

        return $this->ok([
            'categories' => PluginRegistry::CATEGORIES,
            'catalogue' => PluginRegistry::cataloguePayload(),
            'plugins' => $this->resolver->states((int) $user->business_id, $store),
            'stores' => $this->storesPayload($user),
            'scope' => ['store_id' => $store?->id],
        ]);
    }

    /**
     * Connect or update a plugin.
     */
    public function update(UpdatePluginRequest $request, string $pluginKey): JsonResponse
    {
        $user = $this->user($request);
        $plugin = $this->pluginOrFail($pluginKey);

        $store = $this->resolveStore($request, $user);

        $this->service->connect(
            $pluginKey,
            (array) $request->validated('config'),
            (bool) $request->validated('is_enabled'),
            $user,
            $store,
        );

        return $this->ok([
            'plugin_key' => $pluginKey,
            'plugins' => $this->resolver->states((int) $user->business_id, $store),
        ], $plugin['name'].' saved.');
    }

    /**
     * Disconnect a plugin.
     *
     * At business level this removes the default. For a store it removes the
     * override, so that store falls back to the default — not to "off".
     */
    public function destroy(Request $request, string $pluginKey): JsonResponse
    {
        $user = $this->user($request);
        $plugin = $this->pluginOrFail($pluginKey);

        $store = $this->resolveStore($request, $user);

        $this->service->disconnect($pluginKey, $user, $store);

        return $this->ok([
            'plugin_key' => $pluginKey,
            'plugins' => $this->resolver->states((int) $user->business_id, $store),
        ], $plugin['name'].' disconnected.');
    }

    /**
     * Check that a connected plugin is actually on the storefront.
     *
     * Throttled at the route: it makes an outbound request to the business's
     * own storefront, so it is the one endpoint here worth rate-limiting.
     */
    public function test(Request $request, string $pluginKey): JsonResponse
    {
        $user = $this->user($request);
        $plugin = $this->pluginOrFail($pluginKey);

        $store = $this->resolveStore($request, $user) ?? $this->defaultStore($user);

        if ($store === null) {
            return $this->error('Create a store first — there is no storefront to check.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $enabled = $this->resolver->forStore($store);

        if (! array_key_exists($pluginKey, $enabled)) {
            return $this->ok([
                'reachable' => true,
                'found' => false,
                'status' => null,
                'url' => null,
                'message' => $plugin['name'].' is not connected for '.$store->name.', so there is nothing on the storefront to find.',
            ]);
        }

        return $this->ok($this->verifier->verify($store, $pluginKey, $enabled[$pluginKey]));
    }

    /**
     * @return array<string, mixed>
     */
    private function pluginOrFail(string $pluginKey): array
    {
        $plugin = PluginRegistry::get($pluginKey);

        if ($plugin === null) {
            abort(Response::HTTP_NOT_FOUND, 'That plugin does not exist.');
        }

        return $plugin;
    }

    /**
     * The store whose scope the request acts in, or null for business-wide.
     */
    private function resolveStore(Request $request, User $user): ?Store
    {
        $storeId = $request->input('store_id') ?? $request->query('store_id');

        if ($storeId === null || $storeId === '') {
            return null;
        }

        $store = Store::query()->findOrFail((int) $storeId);

        $this->guard->authorizeStore($user, $store, 'You do not have access to this store.');

        return $store;
    }

    private function defaultStore(User $user): ?Store
    {
        return $user->accessibleStores()->orderBy('id')->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storesPayload(User $user): array
    {
        return $user->accessibleStores()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ])
            ->values()
            ->all();
    }
}
