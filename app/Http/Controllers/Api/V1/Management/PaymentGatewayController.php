<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\PaymentGateways\ConnectGatewayRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Access\TenantGuard;
use App\Services\Management\PaymentGatewayService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * WS-39 — Payment gateways.
 *
 * The provider catalogue is code, not rows: {@see PaymentGatewayRegistry}
 * carries each provider's fee, its credentials and their rules, so the screen
 * renders and validates from data and a new provider is one registry entry plus
 * one driver.
 *
 * `store_id` is an optional parameter on every verb rather than a path segment.
 * Absent, the business-wide default is configured; present, that one store's
 * override is. It is the same shape the Plugins endpoints use, because it is
 * the same idea.
 */
final class PaymentGatewayController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly PaymentGatewayResolver $resolver,
        private readonly PaymentGatewayService $service,
        private readonly PaymentGatewayManager $manager,
        private readonly TenantGuard $guard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $store = $this->resolveStore($request, $user);

        return $this->ok([
            'catalogue' => PaymentGatewayRegistry::cataloguePayload(),
            'providers' => $this->resolver->states((int) $user->business_id, $store),
            'stores' => $this->storesPayload($user),
            'scope' => ['store_id' => $store?->id],
        ]);
    }

    public function update(ConnectGatewayRequest $request, string $provider): JsonResponse
    {
        $user = $this->user($request);
        $definition = $this->providerOrFail($provider);
        $store = $this->resolveStore($request, $user);

        $this->service->connect(
            $provider,
            (array) $request->validated('config'),
            (bool) $request->validated('is_enabled'),
            $user,
            $store,
        );

        return $this->ok([
            'code' => $provider,
            'providers' => $this->resolver->states((int) $user->business_id, $store),
        ], $definition['name'].' saved.');
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        $user = $this->user($request);
        $definition = $this->providerOrFail($provider);
        $store = $this->resolveStore($request, $user);

        $this->service->disconnect($provider, $user, $store);

        return $this->ok([
            'code' => $provider,
            'providers' => $this->resolver->states((int) $user->business_id, $store),
        ], $definition['name'].' disconnected.');
    }

    /**
     * Confirm the stored credentials actually authenticate.
     *
     * This is the same check the pre-deploy audit runs over every enabled
     * connection, exposed so a business can answer it themselves.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $user = $this->user($request);
        $definition = $this->providerOrFail($provider);
        $store = $this->resolveStore($request, $user);

        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return $this->error(
                $definition['name'].' is not available yet.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $connection = $this->resolver->connection((int) $user->business_id, $provider, $store?->id);

        if ($connection === null || ! $connection->isEnabled) {
            return $this->ok([
                'success' => false,
                'message' => $definition['name'].' is not connected for this scope.',
            ]);
        }

        $credentials = $this->credentialsWithContext($connection->credentials, $user, $provider);
        $result = $driver->testConnection($credentials);

        return $this->ok([
            'success' => $result->success,
            'message' => $result->message,
        ]);
    }

    /**
     * Bank transfer has no API to authenticate against, so its "connection" is
     * whether the business has somewhere to receive money. The driver reads
     * that as a credential rather than querying the database itself.
     */
    private function credentialsWithContext(GatewayCredentials $credentials, User $user, string $provider): GatewayCredentials
    {
        if ((PaymentGatewayRegistry::get($provider)['credential_source'] ?? 'keys') !== 'bank_accounts') {
            return $credentials;
        }

        $hasAccount = DB::table('store_banks')
            ->where('business_id', (int) $user->business_id)
            ->where('is_verified', true)
            ->exists();

        return GatewayCredentials::make(
            $provider,
            $credentials->all() + ['has_bank_account' => $hasAccount ? '1' : ''],
            [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerOrFail(string $provider): array
    {
        $definition = PaymentGatewayRegistry::get($provider);

        if ($definition === null) {
            abort(Response::HTTP_NOT_FOUND, 'That payment provider does not exist.');
        }

        return $definition;
    }

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
