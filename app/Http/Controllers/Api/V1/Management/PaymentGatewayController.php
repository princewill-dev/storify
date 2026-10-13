<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\PaymentGateways\ConnectGatewayRequest;
use App\Http\Requests\Management\PaymentGateways\RegisterPayoutAccountRequest;
use App\Http\Requests\Management\PaymentGateways\SetGatewayActivationRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Access\TenantGuard;
use App\Services\Management\PaymentGatewayService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\GatewayGrant;
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

    /**
     * The one provider that survives a plan without own-gateway access.
     *
     * Named because two places now depend on the same fact — the reduced
     * catalogue, and the sentence that tells the owner to connect a bank account
     * instead — and a business told to use a rail that the same response hides
     * would have nowhere to go.
     */
    private const BANK_RAIL = 'bank_transfer';

    /**
     * Where the screen sends a business that needs a bigger plan. The management
     * app's own plans route, which is where the refusal contract's
     * `subscription_required` points too.
     */
    private const UPGRADE_URL = '/plans';

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
        // Asked once and used for both answers, so that the menu a business is
        // handed cannot disagree with the explanation printed above it.
        $refusal = GatewayGrant::refusalReason((int) $user->business_id);
        $mayConnectOwn = $refusal === null;

        return $this->ok([
            'catalogue' => $this->catalogueFor($mayConnectOwn),
            // Their own connection state, sent whole whatever the answer above.
            // It is the business's data about its own account, and a business
            // that has just dropped to a smaller plan must still be able to see
            // — and disconnect — what it holds. Hiding a live connection would
            // strand it: still charging at checkout, and with no screen left
            // that admits it exists.
            'providers' => $this->resolver->states((int) $user->business_id, $store),
            'stores' => $this->storesPayload($user),
            'scope' => ['store_id' => $store?->id],
            // Why the catalogue is one entry long, when it is. Stated by the
            // server rather than inferred by the screen from a short list, so
            // "you cannot connect your own keys" and "this platform has one
            // provider" do not render as the same page.
            'gateway_access' => [
                'allowed' => $mayConnectOwn,
                'reason' => $refusal,
                'upgrade_url' => self::UPGRADE_URL,
            ],
        ]);
    }

    /**
     * The provider menu, cut down when the business may not connect anything.
     *
     * The strip is here rather than in the screen because it is the plan talking,
     * not a display preference: a business on a plan without own-gateway access
     * has nothing to do with eleven provider cards, and the screen that renders
     * whatever it is handed cannot drift from the rule if it is never handed
     * anything else. The write is refused independently by {@see GatewayGrant} —
     * this is what stops the screen offering it.
     *
     * `bank_transfer` is the whole of the reduced menu, and it needs no
     * special-casing to survive: it is provisioned from the business's own bank
     * accounts rather than from API keys, and {@see GatewayGrant::usesOwnKeys()}
     * gates only providers that need keys. It is also the rail the refusal itself
     * points the owner at — telling somebody to have their bank account
     * connected while hiding the screen that adds one would be a dead end.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogueFor(bool $mayConnectOwn): array
    {
        $catalogue = PaymentGatewayRegistry::cataloguePayload();

        if ($mayConnectOwn) {
            return $catalogue;
        }

        return array_values(array_filter(
            $catalogue,
            static fn (array $provider): bool => $provider['code'] === self::BANK_RAIL,
        ));
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
     * Name the bank account this business's money is paid into.
     *
     * The form a business with no gateway of its own sees: no keys, no
     * dashboard, just where the money should land. It used to open an account at
     * the provider and submit a payout destination; under collect-and-pay-out
     * there is nothing at the provider to open, so this writes one column and
     * the money is paid out of the wallet to the account it names.
     *
     * **This is not a precondition of selling.** It used to be — a business with
     * no destination could not take a customer's money — and that is the failure
     * the whole design was changed to remove. Collected money is held for the
     * business and paid out as soon as there is somewhere to pay it, so failing
     * here costs a payout, never a sale.
     */
    public function payoutBank(RegisterPayoutAccountRequest $request, string $provider): JsonResponse
    {
        $user = $this->user($request);
        $definition = $this->providerOrFail($provider);
        $store = $this->resolveStore($request, $user);

        $this->service->setPayoutBank($provider, $request->validated(), $user, $store);

        return $this->ok([
            'code' => $provider,
            'success' => true,
            'providers' => $this->resolver->states((int) $user->business_id, $store),
        ], 'Sales will be paid into that bank account.');
    }

    /**
     * Stop taking payments through Storify, or start again.
     *
     * Setting up a bank is not consent to keep selling, so a business being
     * collected for has to be able to stop — and without this there was no way
     * to: there are no keys of its own to switch off and nothing at the provider
     * to delete.
     *
     * Switching off stops new charges and nothing else. Payouts keep running,
     * because the money already collected is still the business's and stopping
     * them would hold it hostage over a decision about new sales.
     */
    public function activation(SetGatewayActivationRequest $request, string $provider): JsonResponse
    {
        $user = $this->user($request);
        $definition = $this->providerOrFail($provider);
        $store = $this->resolveStore($request, $user);

        $isActive = (bool) $request->validated('is_active');

        $this->service->setManagedActive($provider, $isActive, $user, $store);

        return $this->ok([
            'code' => $provider,
            'is_active' => $isActive,
            'providers' => $this->resolver->states((int) $user->business_id, $store),
        ], $definition['name'].($isActive ? ' is taking payments again.' : ' has stopped taking payments.'));
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
