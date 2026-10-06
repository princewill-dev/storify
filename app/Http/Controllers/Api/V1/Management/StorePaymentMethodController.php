<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 */
class StorePaymentMethodController extends ApiController
{
    use ResolvesManagementContext;

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        return $this->ok($this->payload($store));
    }

    public function assignBank(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $data = $request->validate([
            'store_bank_id' => ['required', 'integer'],
        ]);

        $bank = StoreBank::where('business_id', $store->business_id)->find($data['store_bank_id']);

        if (! $bank) {
            abort(404, 'Bank account not found.');
        }

        DB::transaction(function () use ($store, $bank) {
            $store->assignedBanks()->syncWithoutDetaching([$bank->id => ['is_active' => true]]);
            $this->attachBankTransferMethod($store);
        });

        Log::info('payment-settings.bank_assigned_to_store', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'bank_id' => $bank->id,
        ]);

        return $this->ok([], $bank->bank_name.' assigned to this store.');
    }

    public function removeBank(Request $request, Store $store, StoreBank $bank): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if ((int) $bank->business_id !== (int) $store->business_id) {
            abort(404, 'Bank account not found.');
        }

        DB::transaction(function () use ($store, $bank) {
            $store->assignedBanks()->detach($bank->id);

            // bank_transfer stays available only while the store accepts at
            // least one account.
            if ($store->assignedBanks()->count() === 0) {
                $method = PaymentMethod::where('code', 'bank_transfer')->first();

                if ($method) {
                    DB::table('store_payment_method')
                        ->where('store_id', $store->id)
                        ->where('payment_method_id', $method->id)
                        ->delete();
                }
            }
        });

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

        $store->load(['assignedBanks', 'paymentMethods']);

        $assignedMethodIds = $store->paymentMethods
            ->filter(fn (PaymentMethod $method) => (bool) $method->pivot->is_active)
            ->pluck('id');

        $gateways = DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $businessId)
            ->where('payment_methods.type', 'gateway')
            ->select(
                'business_payment_method.id',
                'business_payment_method.payment_method_id',
                'business_payment_method.is_active',
                'business_payment_method.config',
                'payment_methods.name',
                'payment_methods.code',
            )
            ->orderBy('payment_methods.name')
            ->get();

        $businessBanks = StoreBank::where('business_id', $businessId)
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get();

        $assignedBankIds = $store->assignedBanks->pluck('id');

        $gatewayPayload = fn ($row) => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'is_active' => (bool) $row->is_active,
            'public_key_masked' => $this->maskKey(json_decode($row->config ?: '{}', true)['public_key'] ?? null),
            'assigned' => $assignedMethodIds->contains((int) $row->payment_method_id),
        ];

        $bankPayload = fn (StoreBank $bank) => [
            'id' => $bank->id,
            'bank_name' => $bank->bank_name,
            'account_name' => $bank->account_name,
            'masked_account_number' => $bank->masked_account_number,
            'is_primary' => (bool) $bank->is_primary,
            'is_verified' => (bool) $bank->is_verified,
        ];

        $status = $store->status;

        return [
            'store' => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $status instanceof \BackedEnum ? $status->value : $status,
                'payment_mode' => $store->payment_mode,
            ],
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
                (int) (PaymentMethod::where('code', 'bank_transfer')->value('id') ?? 0),
            ),
        ];
    }

    private function attachBankTransferMethod(Store $store): void
    {
        $method = PaymentMethod::where('code', 'bank_transfer')->first();

        if (! $method) {
            return;
        }

        $exists = DB::table('store_payment_method')
            ->where('store_id', $store->id)
            ->where('payment_method_id', $method->id)
            ->exists();

        if ($exists) {
            DB::table('store_payment_method')
                ->where('store_id', $store->id)
                ->where('payment_method_id', $method->id)
                ->update(['is_active' => true, 'updated_at' => now()]);

            return;
        }

        DB::table('store_payment_method')->insert([
            'store_id' => $store->id,
            'payment_method_id' => $method->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function maskKey(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        if (strlen($key) <= 10) {
            return substr($key, 0, 3).'****';
        }

        return substr($key, 0, 7).'****'.substr($key, -4);
    }
}
