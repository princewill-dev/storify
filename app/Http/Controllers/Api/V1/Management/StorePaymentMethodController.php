<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\PaymentSettings\AssignStoreBankRequest;
use App\Http\Resources\Management\PaymentSettings\StoreBankAssignmentResource;
use App\Http\Resources\Management\PaymentSettings\StoreGatewayResource;
use App\Http\Resources\Management\PaymentSettings\StoreSummaryResource;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Repositories\Management\PaymentSettingsRepository;
use App\Repositories\Management\StorePaymentMethodRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\StorePaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-11 — the per-store "Manage Payment Methods" surface (legacy finance 3.5,
 * rebuilt off `StoreTabController` data).
 *
 * Gateway assignment writes the `store_payment_method` pivot; bank assignment
 * additionally writes the `store_bank` pivot, because a store accepts a
 * specific account, not just the abstract bank_transfer method. Every lookup
 * is scoped to the store's business — the legacy store-bank routes only
 * checked the store, never the bank's owner.
 *
 * Layering: the store guard is TenantGuard; the payload's query building is in
 * PaymentSettingsRepository (shared with the payment-settings screens) plus
 * StorePaymentMethodRepository for the tenancy-scoped bank read that is
 * store-side only; the assignment workflows and their transaction boundaries
 * are in StorePaymentMethodService; the row shapes are the PaymentSettings
 * resources. This controller keeps the HTTP contract — status codes, the
 * 404/403 refusals, the log line and the message strings.
 */
class StorePaymentMethodController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this store.';

    private const BANK_NOT_FOUND = 'Bank account not found.';

    public function __construct(
        private readonly PaymentSettingsRepository $repository,
        private readonly StorePaymentMethodRepository $storeMethods,
        private readonly StorePaymentMethodService $service,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->tenantGuard->authorizeStore($this->user($request), $store, self::ACCESS_DENIED);

        return $this->ok($this->payload($store));
    }

    public function assignBank(AssignStoreBankRequest $request, Store $store): JsonResponse
    {
        $this->tenantGuard->authorizeStore($this->user($request), $store, self::ACCESS_DENIED);

        $data = $request->validated();

        $bank = $this->storeMethods->findBankForBusiness((int) $store->business_id, (int) $data['store_bank_id']);

        if (! $bank) {
            abort(404, self::BANK_NOT_FOUND);
        }

        $this->service->assignBank($store, $bank);

        Log::info('payment-settings.bank_assigned_to_store', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'bank_id' => $bank->id,
        ]);

        return $this->ok([], $bank->bank_name.' assigned to this store.');
    }

    public function removeBank(Request $request, Store $store, StoreBank $bank): JsonResponse
    {
        $this->tenantGuard->authorizeStore($this->user($request), $store, self::ACCESS_DENIED);

        if ((int) $bank->business_id !== (int) $store->business_id) {
            abort(404, self::BANK_NOT_FOUND);
        }

        $this->service->removeBank($store, $bank);

        return $this->ok([], $bank->bank_name.' removed from this store.');
    }

    /**
     * The four lists the management modal renders: assigned and still-available
     * gateways and bank accounts. Adjacent to `PaymentSettingsController`'s
     * index, but from the store's point of view rather than the method's.
     *
     * @return array<string, mixed>
     */
    private function payload(Store $store): array
    {
        $businessId = (int) $store->business_id;

        // load, not loadMissing: the payload has always refreshed both
        // relations rather than trusting whatever a caller loaded earlier.
        $store->load(['assignedBanks', 'paymentMethods']);

        $assignedMethodIds = $store->paymentMethods
            ->filter(fn (PaymentMethod $method) => (bool) $method->pivot->is_active)
            ->pluck('id');

        $gateways = $this->repository->businessGateways($businessId);
        $businessBanks = $this->repository->businessBanks($businessId);

        $assignedBankIds = $store->assignedBanks->pluck('id');

        $gatewayPayload = fn ($row) => (new StoreGatewayResource(
            $row,
            $assignedMethodIds->contains((int) $row->payment_method_id),
        ))->resolve();

        $bankPayload = fn (StoreBank $bank) => (new StoreBankAssignmentResource($bank))->resolve();

        return [
            'store' => (new StoreSummaryResource($store))->resolve(),
            'assigned_gateways' => $gateways
                ->filter(fn ($row) => $assignedMethodIds->contains((int) $row->payment_method_id))
                ->map($gatewayPayload)->values()->all(),
            'available_gateways' => $gateways
                ->reject(fn ($row) => $assignedMethodIds->contains((int) $row->payment_method_id))
                ->map($gatewayPayload)->values()->all(),
            'assigned_banks' => $businessBanks
                ->filter(fn (StoreBank $bank) => $assignedBankIds->contains($bank->id))
                ->map($bankPayload)->values()->all(),
            'available_banks' => $businessBanks
                ->reject(fn (StoreBank $bank) => $assignedBankIds->contains($bank->id))
                ->map($bankPayload)->values()->all(),
            'bank_transfer_assigned' => $assignedMethodIds->contains(
                (int) ($this->repository->findPaymentMethodByCode('bank_transfer')?->id ?? 0),
            ),
        ];
    }
}
