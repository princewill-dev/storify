<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\PaymentSettings\AssignMethodRequest;
use App\Http\Requests\Management\PaymentSettings\StoreBankAccountRequest;
use App\Http\Requests\Management\PaymentSettings\StoreGatewayRequest;
use App\Http\Requests\Management\PaymentSettings\TogglePaymentModeRequest;
use App\Http\Requests\Management\PaymentSettings\UpdateBankAccountRequest;
use App\Http\Requests\Management\PaymentSettings\UpdateGatewayRequest;
use App\Http\Requests\Management\PaymentSettings\VerifyBankAccountRequest;
use App\Http\Resources\Management\PaymentSettings\AvailableGatewayResource;
use App\Http\Resources\Management\PaymentSettings\BusinessGatewayResource;
use App\Http\Resources\Management\PaymentSettings\MethodHeaderResource;
use App\Http\Resources\Management\PaymentSettings\PaystackBankResource;
use App\Http\Resources\Management\PaymentSettings\ResolvedAccountResource;
use App\Http\Resources\Management\PaymentSettings\StoreBankResource;
use App\Http\Resources\Management\PaymentSettings\StoreSummaryResource;
use App\Models\Store;
use App\Models\StoreBank;
use App\Repositories\Management\PaymentSettingsRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\PaymentSettingsService;
use App\Services\PaystackService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * WS-11 — Payment configuration: business bank accounts, Paystack gateways and
 * payment-method → store assignment.
 *
 * Legacy lived in `Management\PaymentSettingsController`; this rebuild keeps
 * its capability but fixes three defects called out by the audit:
 *
 *  - the Paystack secret key was round-tripped to the browser in the edit
 *    modal — it is write-only here (only a masked public key is ever returned);
 *  - `destroyPaystackKeys` deleted store assignments platform-wide — removal
 *    is scoped to the acting business's stores;
 *  - assign/unassign resolved the method from an ambiguous `type` string that
 *    turned the store modal's Paystack assign into a no-op and made the
 *    method-info unassign strip bank transfer instead. Resolution here is
 *    unambiguous: `gateway` ids are business gateway pivot ids, `bank` ids are
 *    `store_banks` ids.
 *
 * The read/write model lives in PaymentSettingsRepository, the multi-table
 * workflows (and their transaction boundaries) in PaymentSettingsService and
 * the response shapes in the PaymentSettings resources; this controller keeps
 * the HTTP contract — status codes, message strings, the envelope — plus the
 * 404/403/422 guards around a `{type}/{id}` resolution.
 */
class PaymentSettingsController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED_BANK = 'You do not have access to this bank account.';

    public function __construct(
        private readonly PaystackService $paystack,
        private readonly PaymentSettingsRepository $repository,
        private readonly PaymentSettingsService $service,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $storeIds = $this->accessibleStoreIds($request);

        $banks = $this->repository->businessBanks($businessId);

        $bankTransfer = $this->repository->businessMethodRow($businessId, 'bank_transfer');

        return $this->ok([
            'banks' => $banks->map(fn (StoreBank $bank) => $this->bankPayload($bank, $storeIds))->all(),
            'gateways' => $this->repository->businessGateways($businessId)
                ->map(fn ($row) => $this->gatewayPayload($row, $storeIds))->all(),
            'available_gateways' => AvailableGatewayResource::collection(
                $this->repository->availableGatewayMethods($businessId)
            )->resolve($request),
            'stores' => StoreSummaryResource::collection(
                $this->repository->storeOptions($this->user($request))
            )->resolve($request),
            'bank_transfer_enabled' => (bool) ($bankTransfer?->is_active ?? false),
        ]);
    }

    public function storeBankAccount(StoreBankAccountRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $data = $request->validated();

        if ($this->repository->bankAccountExists($businessId, $data['account_number'], $data['bank_code'])) {
            return $this->duplicateBankAccount();
        }

        $store = ! empty($data['store_id'])
            ? $this->repository->accessibleStores($user)->whereKey($data['store_id'])->firstOrFail()
            : null;

        try {
            $bank = $this->service->addBankAccount($businessId, $data, $store);
        } catch (UniqueConstraintViolationException) {
            // The check above and the insert are two statements, so two
            // submissions of the same account can both clear the check — a
            // double-tapped button is enough. The database is what actually
            // decides, and it has to give the same answer the check would
            // have. The only unique key reachable from here is this table's:
            // the store pivots are attached with syncWithoutDetaching() and an
            // existence check rather than a blind insert.
            return $this->duplicateBankAccount();
        }

        Log::info('payment-settings.bank_added', [
            'user_id' => $user->id,
            'bank_id' => $bank->id,
            'store_id' => $data['store_id'] ?? null,
        ]);

        return $this->ok(
            ['bank' => $this->bankPayload($bank, $this->accessibleStoreIds($request))],
            'Bank account added successfully.',
            201,
        );
    }

    public function updateBankAccount(UpdateBankAccountRequest $request, StoreBank $bank): JsonResponse
    {
        $this->tenantGuard->authorizeBusiness($bank, $this->user($request), self::ACCESS_DENIED_BANK);

        $this->service->updateBankAccount($bank, $request->validated(), (int) $this->user($request)->business_id);

        return $this->ok(
            ['bank' => $this->bankPayload($bank->fresh(), $this->accessibleStoreIds($request))],
            'Bank account updated successfully.',
        );
    }

    /**
     * The primary account cannot be deleted (legacy store-bank rule, applied
     * to business accounts too): a storefront must always have a payout
     * account to fall back to, so the owner has to promote another first.
     */
    public function destroyBankAccount(Request $request, StoreBank $bank): JsonResponse
    {
        $this->tenantGuard->authorizeBusiness($bank, $this->user($request), self::ACCESS_DENIED_BANK);

        if ($bank->is_primary) {
            return $this->error('Cannot delete the primary bank account. Set another account as primary first.', 422);
        }

        $this->service->deleteBankAccount($bank, (int) $this->user($request)->business_id);

        return $this->ok([], 'Bank account deleted successfully.');
    }

    public function setPrimaryBank(Request $request, StoreBank $bank): JsonResponse
    {
        $this->tenantGuard->authorizeBusiness($bank, $this->user($request), self::ACCESS_DENIED_BANK);

        $this->service->setPrimaryBank($bank, (int) $this->user($request)->business_id);

        return $this->ok([], 'Primary bank account updated.');
    }

    public function verifyBankAccount(VerifyBankAccountRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->paystack->resolveAccountNumber($data['account_number'], $data['bank_code']);

        if (! $result['success']) {
            return $this->error($result['message'] ?? 'Could not verify account number.', 422, [
                'account_number' => [$result['message'] ?? 'Could not verify account number.'],
            ]);
        }

        return $this->ok(
            (new ResolvedAccountResource($result['data'] ?? [], $data['account_number']))->resolve($request),
            'Account verified successfully.',
        );
    }

    /**
     * Paystack bank list proxy — the add-account modal picks a bank from this,
     * exactly like legacy's cached (1 day) `getBanks` call.
     */
    public function banks(): JsonResponse
    {
        $result = $this->paystack->getBanks();

        if (! ($result['success'] ?? false)) {
            return $this->error('Could not load the bank list from Paystack. Please try again shortly.', 502);
        }

        return $this->ok(['banks' => PaystackBankResource::fromPayload($result['data'] ?? [])]);
    }

    public function storeGateway(StoreGatewayRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $data = $request->validated();

        $method = $this->service->paymentMethodOrFail('paystack');

        if ($this->repository->businessMethodRow($businessId, 'paystack') !== null) {
            return $this->error('Paystack is already connected. Edit the existing keys instead.', 422, [
                'public_key' => ['Paystack is already connected.'],
            ]);
        }

        $config = ['public_key' => $data['public_key'], 'secret_key' => $data['secret_key']];

        $this->service->connectGateway($businessId, $method, $config);

        Log::info('payment-settings.business_paystack_added', ['user_id' => $user->id]);

        return $this->ok(
            ['gateway' => $this->gatewayPayload($this->repository->businessMethodRow($businessId, 'paystack'), $this->accessibleStoreIds($request))],
            'Paystack connected.',
            201,
        );
    }

    public function updateGateway(UpdateGatewayRequest $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $data = $request->validated();

        $config = json_decode($row->config ?: '{}', true) ?: [];
        $config['public_key'] = $data['public_key'];

        if (! empty($data['secret_key'])) {
            $config['secret_key'] = $data['secret_key'];
        }

        if (empty($config['secret_key'])) {
            return $this->error('A secret key is required for the gateway.', 422, [
                'secret_key' => ['A secret key is required for the gateway.'],
            ]);
        }

        $method = $this->repository->findPaymentMethodOrFail((int) $row->payment_method_id);

        $this->service->updateGatewayConfig($row, $method, $config, $businessId);

        return $this->ok(
            ['gateway' => $this->gatewayPayload($this->repository->businessMethodRow($businessId, $method->code), $this->accessibleStoreIds($request))],
            'Paystack keys updated.',
        );
    }

    public function destroyGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $this->service->removeGateway($row, $this->accessibleStoreIds($request));

        return $this->ok([], 'Paystack removed.');
    }

    public function toggleGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $this->repository->setBusinessGatewayActive((int) $row->id, ! $row->is_active);

        return $this->ok([], 'Paystack '.($row->is_active ? 'disabled' : 'enabled').'.');
    }

    public function testGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $config = json_decode($row->config ?: '{}', true) ?: [];

        if (empty($config['secret_key'])) {
            return $this->error('No secret key is stored for this gateway.', 422);
        }

        $result = $this->paystack->usingGateway((object) [
            'secret_key' => $config['secret_key'],
            'public_key' => $config['public_key'] ?? '',
        ])->testConnection();

        if (! ($result['success'] ?? false)) {
            return $this->error($result['message'] ?? 'Could not reach Paystack.', 422);
        }

        return $this->ok(['connected' => true], $result['message'] ?? 'Connection successful.');
    }

    /**
     * Method header + the stores that do and do not yet accept it (legacy
     * `methodInfo`). `type` is `gateway` (id = business gateway pivot id) or
     * `bank` (id = store_banks id) — one unambiguous resolution.
     */
    public function methodInfo(Request $request, string $type, string $id): JsonResponse
    {
        $resolved = $this->service->resolveMethod($this->user($request), $type, $id);

        return $this->ok([
            'method' => (new MethodHeaderResource($resolved))->resolve($request),
            'assigned_stores' => StoreSummaryResource::collection(
                $this->repository->namedStores($resolved->assignedQuery)
            )->resolve($request),
            'available_stores' => StoreSummaryResource::collection(
                $this->repository->namedStores($resolved->availableQuery)
            )->resolve($request),
            'method_code' => $resolved->method->code,
        ]);
    }

    public function assignMethod(AssignMethodRequest $request, string $type, string $id): JsonResponse
    {
        $user = $this->user($request);
        $resolved = $this->service->resolveMethod($user, $type, $id);

        $store = $this->repository->accessibleStores($user)
            ->whereKey($request->validated()['store_id'])->firstOrFail();

        $this->service->assignMethod($store, $resolved->method, $type, (int) $id, (int) $user->business_id);

        return $this->ok([], 'Assigned to '.$store->name.'.');
    }

    public function unassignMethod(Request $request, string $type, string $id, Store $store): JsonResponse
    {
        $resolved = $this->service->resolveMethod($this->user($request), $type, $id);

        $this->tenantGuard->authorizeStore($this->user($request), $store, 'You do not have access to this store.');

        $this->service->unassignMethod($store, $resolved->method, $type, (int) $id);

        return $this->ok([], 'Payment method removed from '.$store->name.'.');
    }

    /**
     * Per-store Auto (card) / Manual (transfer) mode. Legacy's only caller was
     * dead UI; the guardrails are still worth keeping — they are what stops a
     * store offering a method it cannot actually take.
     */
    public function togglePaymentMode(TogglePaymentModeRequest $request, Store $store): JsonResponse
    {
        $user = $this->user($request);

        $this->tenantGuard->authorizeStore($user, $store, 'You do not have access to this store.');

        if ((int) $store->user_id !== (int) $user->id && ! $user->isPlatformAdmin()) {
            abort(403, 'Only the store owner can change the payment mode.');
        }

        $mode = $request->validated()['payment_mode'];

        if ($mode === 'auto') {
            $gateway = $this->repository->businessMethodRow((int) $store->business_id, 'paystack');

            if (! $gateway || ! $gateway->is_active || ! $this->repository->storeHasActiveMethod($store, 'paystack')) {
                return $this->error('Assign an active Paystack gateway to this store before switching to automatic card payments.', 422);
            }
        }

        if ($mode === 'manual'
            && ! $this->repository->businessHasBanks((int) $store->business_id)) {
            return $this->error('Add a business bank account before switching to manual transfers.', 422);
        }

        $this->repository->updateStorePaymentMode($store, $mode);

        return $this->ok(
            ['store' => (new StoreSummaryResource($store->fresh()))->resolve($request)],
            $store->name.' payment mode set to '.($mode === 'auto' ? 'Auto (Card)' : 'Manual (Transfer)').'.',
        );
    }

    /**
     * The single answer for a bank account the business already holds, whether
     * it was caught by the pre-check or by the unique index.
     */
    private function duplicateBankAccount(): JsonResponse
    {
        return $this->error('This bank account already exists.', 422, [
            'account_number' => ['This bank account already exists.'],
        ]);
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     * @return array<string, mixed>
     */
    private function bankPayload(StoreBank $bank, $storeIds): array
    {
        return (new StoreBankResource($bank, $this->repository->assignedBankStoreCount($bank, $storeIds)))->resolve();
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     * @return array<string, mixed>
     */
    private function gatewayPayload(?object $row, $storeIds): array
    {
        if (! $row) {
            return [];
        }

        return (new BusinessGatewayResource($row, $this->repository->assignedGatewayStoreCount($row, $storeIds)))->resolve();
    }

    private function authorizeGateway(int $gateway, int $businessId): object
    {
        $row = $this->repository->gatewayRow($gateway);

        if (! $row) {
            abort(404, 'Gateway not found.');
        }

        if ((int) $row->business_id !== $businessId) {
            abort(403, 'You do not have access to this gateway.');
        }

        return $row;
    }
}
