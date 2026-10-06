<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
 */
class PaymentSettingsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly PaystackService $paystack) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $storeIds = $this->accessibleStoreIds($request);

        $banks = StoreBank::query()
            ->where('business_id', $businessId)
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get();

        $bankTransfer = $this->businessMethodRow($businessId, 'bank_transfer');

        return $this->ok([
            'banks' => $banks->map(fn (StoreBank $bank) => $this->bankPayload($bank, $storeIds))->all(),
            'gateways' => $this->businessGateways($businessId)
                ->map(fn ($row) => $this->gatewayPayload($row, $storeIds))->all(),
            'available_gateways' => PaymentMethod::query()
                ->where('is_active', true)
                ->where('type', 'gateway')
                ->whereDoesntHave('businesses', fn ($q) => $q->where('businesses.id', $businessId))
                ->orderBy('name')
                ->get()
                ->map(fn (PaymentMethod $method) => [
                    'code' => $method->code,
                    'name' => $method->name,
                    'description' => $method->description,
                ])->all(),
            'stores' => $this->accessibleStoresQuery($request)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => $this->storeSummary($store))
                ->all(),
            'bank_transfer_enabled' => (bool) ($bankTransfer?->is_active ?? false),
        ]);
    }

    public function storeBankAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $data = $request->validate([
            'store_id' => ['nullable', 'integer', $this->accessibleStoreRule($request)],
            'bank_code' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:255'],
            // Paystack's account-name resolution only accepts NUBAN 10-digit
            // numbers, so the submit button was gated on one; enforce it here
            // too instead of legacy's looser max:20.
            'account_number' => ['required', 'string', 'digits:10'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        if (StoreBank::where('business_id', $businessId)
            ->where('account_number', $data['account_number'])
            ->where('bank_code', $data['bank_code'])
            ->exists()) {
            return $this->error('This bank account already exists.', 422, [
                'account_number' => ['This bank account already exists.'],
            ]);
        }

        $bank = DB::transaction(function () use ($businessId, $data, $request) {
            $isFirst = ! StoreBank::where('business_id', $businessId)->exists();

            $bank = StoreBank::create([
                'business_id' => $businessId,
                'bank_name' => $data['bank_name'],
                'bank_code' => $data['bank_code'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'is_primary' => $isFirst,
                'is_verified' => true,
            ]);

            // Adding a bank connects bank_transfer for the business — legacy
            // did this too, but only on the business pivot. The store pivot
            // follows when the caller picked a store.
            $this->syncBankTransferMethod($businessId);

            if (! empty($data['store_id'])) {
                $store = $this->accessibleStoresQuery($request)->whereKey($data['store_id'])->firstOrFail();
                $this->assignBankToStore($bank, $store);
            }

            return $bank;
        });

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

    public function updateBankAccount(Request $request, StoreBank $bank): JsonResponse
    {
        $this->authorizeBank($request, $bank);

        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $businessId = (int) $this->user($request)->business_id;

        DB::transaction(function () use ($bank, $data, $businessId) {
            $attributes = ['account_name' => $data['account_name']];

            // Partial semantics: editing the name must not silently demote the
            // account, so primary only moves when the caller asks for it.
            if (($data['is_primary'] ?? false) === true) {
                StoreBank::where('business_id', $businessId)
                    ->whereKeyNot($bank->getKey())
                    ->update(['is_primary' => false]);

                $attributes['is_primary'] = true;
            }

            $bank->update($attributes);

            $this->syncBankTransferMethod($businessId);
        });

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
        $this->authorizeBank($request, $bank);

        if ($bank->is_primary) {
            return $this->error('Cannot delete the primary bank account. Set another account as primary first.', 422);
        }

        $businessId = (int) $this->user($request)->business_id;

        DB::transaction(function () use ($bank, $businessId) {
            // The store_bank pivot cascades with the account, but the
            // store-level bank_transfer method does not: a store that accepted
            // only this account would otherwise keep offering transfers with
            // nowhere to send them. Capture the stores it served first.
            $storeIds = DB::table('store_bank')
                ->where('store_bank_id', $bank->id)
                ->whereIn('store_id', Store::where('business_id', $businessId)->pluck('id'))
                ->pluck('store_id');

            $bank->delete();

            $method = PaymentMethod::where('code', 'bank_transfer')->first();

            if ($method && $storeIds->isNotEmpty()) {
                $stillBanked = DB::table('store_bank')->whereIn('store_id', $storeIds)->pluck('store_id');

                $emptyStoreIds = $storeIds->diff($stillBanked);

                if ($emptyStoreIds->isNotEmpty()) {
                    DB::table('store_payment_method')
                        ->whereIn('store_id', $emptyStoreIds)
                        ->where('payment_method_id', $method->id)
                        ->delete();
                }
            }

            $this->syncBankTransferMethod($businessId);
        });

        return $this->ok([], 'Bank account deleted successfully.');
    }

    public function setPrimaryBank(Request $request, StoreBank $bank): JsonResponse
    {
        $this->authorizeBank($request, $bank);

        $businessId = (int) $this->user($request)->business_id;

        DB::transaction(function () use ($bank, $businessId) {
            StoreBank::where('business_id', $businessId)->update(['is_primary' => false]);
            $bank->update(['is_primary' => true]);
            $this->syncBankTransferMethod($businessId);
        });

        return $this->ok([], 'Primary bank account updated.');
    }

    public function verifyBankAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_number' => ['required', 'string', 'digits:10'],
            'bank_code' => ['required', 'string', 'max:20'],
        ]);

        $result = $this->paystack->resolveAccountNumber($data['account_number'], $data['bank_code']);

        if (! $result['success']) {
            return $this->error($result['message'] ?? 'Could not verify account number.', 422, [
                'account_number' => [$result['message'] ?? 'Could not verify account number.'],
            ]);
        }

        return $this->ok([
            'account_number' => $result['data']['account_number'] ?? $data['account_number'],
            'account_name' => $result['data']['account_name'] ?? null,
        ], 'Account verified successfully.');
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

        $banks = collect($result['data'] ?? [])
            ->filter(fn ($bank) => ($bank['active'] ?? true) !== false)
            ->map(fn ($bank) => [
                'name' => $bank['name'] ?? null,
                'code' => $bank['code'] ?? null,
                'slug' => $bank['slug'] ?? null,
            ])
            ->filter(fn ($bank) => $bank['name'] && $bank['code'])
            ->values()
            ->all();

        return $this->ok(['banks' => $banks]);
    }

    public function storeGateway(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
        ]);

        $method = $this->paymentMethodOrFail('paystack');

        if ($this->businessMethodRow($businessId, 'paystack') !== null) {
            return $this->error('Paystack is already connected. Edit the existing keys instead.', 422, [
                'public_key' => ['Paystack is already connected.'],
            ]);
        }

        $config = ['public_key' => $data['public_key'], 'secret_key' => $data['secret_key']];

        DB::transaction(function () use ($businessId, $method, $config) {
            DB::table('business_payment_method')->insert([
                'business_id' => $businessId,
                'payment_method_id' => $method->id,
                'is_active' => true,
                'config' => json_encode($config),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Mirror the keys onto already-assigned stores so the storefront
            // checkout (which reads `store_payment_method.api_keys`) can use
            // them. No-op until that column exists — see mirrorGatewayKeys().
            $this->mirrorGatewayKeys($businessId, $method, $config);
        });

        Log::info('payment-settings.business_paystack_added', ['user_id' => $user->id]);

        return $this->ok(
            ['gateway' => $this->gatewayPayload($this->businessMethodRow($businessId, 'paystack'), $this->accessibleStoreIds($request))],
            'Paystack connected.',
            201,
        );
    }

    public function updateGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:255'],
            // Write-only: the browser never receives the stored secret, so an
            // empty value means "keep the existing key".
            'secret_key' => ['nullable', 'string', 'max:255'],
        ]);

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

        $method = PaymentMethod::findOrFail($row->payment_method_id);

        DB::transaction(function () use ($row, $config, $businessId, $method) {
            DB::table('business_payment_method')->where('id', $row->id)->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);

            $this->mirrorGatewayKeys($businessId, $method, $config);
        });

        return $this->ok(
            ['gateway' => $this->gatewayPayload($this->businessMethodRow($businessId, $method->code), $this->accessibleStoreIds($request))],
            'Paystack keys updated.',
        );
    }

    public function destroyGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        $storeIds = $this->accessibleStoreIds($request);

        DB::transaction(function () use ($row, $storeIds) {
            DB::table('business_payment_method')->where('id', $row->id)->delete();

            // Scoped to this business's stores. Legacy deleted
            // store_payment_method by payment_method_id across *every* store
            // platform-wide, silently unassigning other businesses.
            DB::table('store_payment_method')
                ->where('payment_method_id', $row->payment_method_id)
                ->whereIn('store_id', $storeIds)
                ->delete();
        });

        return $this->ok([], 'Paystack removed.');
    }

    public function toggleGateway(Request $request, int $gateway): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;
        $row = $this->authorizeGateway($gateway, $businessId);

        DB::table('business_payment_method')->where('id', $row->id)->update([
            'is_active' => ! $row->is_active,
            'updated_at' => now(),
        ]);

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
        [$method, $header, $assignedQuery, $availableQuery] = $this->resolveMethod($request, $type, $id);

        return $this->ok([
            'method' => $header,
            'assigned_stores' => $assignedQuery->orderBy('name')->get()->map(fn (Store $store) => $this->storeSummary($store))->all(),
            'available_stores' => $availableQuery->orderBy('name')->get()->map(fn (Store $store) => $this->storeSummary($store))->all(),
            'method_code' => $method->code,
        ]);
    }

    public function assignMethod(Request $request, string $type, string $id): JsonResponse
    {
        [$method] = $this->resolveMethod($request, $type, $id);

        $data = $request->validate([
            'store_id' => ['required', 'integer', $this->accessibleStoreRule($request)],
        ]);

        $store = $this->accessibleStoresQuery($request)->whereKey($data['store_id'])->firstOrFail();
        $businessId = (int) $this->user($request)->business_id;

        DB::transaction(function () use ($store, $method, $type, $id, $businessId) {
            $this->attachMethodToStore($store, (int) $method->id, $businessId);

            // A bank assignment is per account, not just per method, so the
            // store's accepted bank list moves too.
            if ($type === 'bank') {
                $store->assignedBanks()->syncWithoutDetaching([(int) $id => ['is_active' => true]]);
            }
        });

        return $this->ok([], 'Assigned to '.$store->name.'.');
    }

    public function unassignMethod(Request $request, string $type, string $id, Store $store): JsonResponse
    {
        [$method] = $this->resolveMethod($request, $type, $id);
        $this->authorizeStore($request, $store);

        DB::transaction(function () use ($store, $method, $type, $id) {
            // A bank assignment is per account, not just per method: removing
            // one of several accounts must leave bank_transfer in place. Only
            // the last account turns the store's transfers off.
            if ($type === 'bank') {
                $store->assignedBanks()->detach((int) $id);

                if ($store->assignedBanks()->count() > 0) {
                    return;
                }
            }

            DB::table('store_payment_method')
                ->where('store_id', $store->id)
                ->where('payment_method_id', $method->id)
                ->delete();
        });

        return $this->ok([], 'Payment method removed from '.$store->name.'.');
    }

    /**
     * Per-store Auto (card) / Manual (transfer) mode. Legacy's only caller was
     * dead UI; the guardrails are still worth keeping — they are what stops a
     * store offering a method it cannot actually take.
     */
    public function togglePaymentMode(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $user = $this->user($request);

        if ((int) $store->user_id !== (int) $user->id && ! $user->isPlatformAdmin()) {
            abort(403, 'Only the store owner can change the payment mode.');
        }

        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(['auto', 'manual'])],
        ]);

        if ($data['payment_mode'] === 'auto') {
            $gateway = $this->businessMethodRow((int) $store->business_id, 'paystack');

            $assigned = DB::table('store_payment_method')
                ->join('payment_methods', 'payment_methods.id', '=', 'store_payment_method.payment_method_id')
                ->where('store_payment_method.store_id', $store->id)
                ->where('payment_methods.code', 'paystack')
                ->where('store_payment_method.is_active', true)
                ->exists();

            if (! $gateway || ! $gateway->is_active || ! $assigned) {
                return $this->error('Assign an active Paystack gateway to this store before switching to automatic card payments.', 422);
            }
        }

        if ($data['payment_mode'] === 'manual'
            && ! StoreBank::where('business_id', $store->business_id)->exists()) {
            return $this->error('Add a business bank account before switching to manual transfers.', 422);
        }

        $store->update(['payment_mode' => $data['payment_mode']]);

        return $this->ok(
            ['store' => $this->storeSummary($store->fresh())],
            $store->name.' payment mode set to '.($data['payment_mode'] === 'auto' ? 'Auto (Card)' : 'Manual (Transfer)').'.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function businessGateways(int $businessId)
    {
        return DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $businessId)
            ->where('payment_methods.type', 'gateway')
            ->select(
                'business_payment_method.id',
                'business_payment_method.payment_method_id',
                'business_payment_method.is_active',
                'business_payment_method.config',
                'business_payment_method.created_at',
                'payment_methods.name',
                'payment_methods.code',
            )
            ->orderBy('payment_methods.name')
            ->get();
    }

    private function businessMethodRow(int $businessId, string $code): ?object
    {
        return DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $businessId)
            ->where('payment_methods.code', $code)
            ->select('business_payment_method.*', 'payment_methods.name', 'payment_methods.code')
            ->first();
    }

    private function paymentMethodOrFail(string $code): PaymentMethod
    {
        $method = PaymentMethod::where('code', $code)->first();

        if (! $method) {
            abort(500, 'The '.$code.' payment method is not configured on this platform.');
        }

        return $method;
    }

    /**
     * Resolve a `{type}/{id}` pair into a payment method plus the store lists
     * around it. Returns [method, header, assignedQuery, availableQuery].
     *
     * @return array{0: PaymentMethod, 1: array<string, mixed>, 2: mixed, 3: mixed}
     */
    private function resolveMethod(Request $request, string $type, string $id): array
    {
        $businessId = (int) $this->user($request)->business_id;

        if ($type === 'gateway') {
            $row = DB::table('business_payment_method')->where('id', (int) $id)->first();

            if (! $row) {
                abort(404, 'Payment method not found.');
            }

            if ((int) $row->business_id !== $businessId) {
                abort(403, 'You do not have access to this gateway.');
            }

            $method = PaymentMethod::findOrFail($row->payment_method_id);
            $config = json_decode($row->config ?: '{}', true) ?: [];

            $assigned = $this->accessibleStoresQuery($request)
                ->whereHas('paymentMethods', fn ($q) => $q->where('payment_methods.id', $method->id)
                    ->where('store_payment_method.is_active', true));

            $available = $this->accessibleStoresQuery($request)
                ->whereDoesntHave('paymentMethods', fn ($q) => $q->where('payment_methods.id', $method->id)
                    ->where('store_payment_method.is_active', true));

            $header = [
                'type' => 'gateway',
                'id' => (int) $row->id,
                'code' => $method->code,
                'name' => $method->name,
                'is_active' => (bool) $row->is_active,
                'public_key_masked' => $this->maskKey($config['public_key'] ?? null),
                'has_secret_key' => ! empty($config['secret_key']),
            ];

            return [$method, $header, $assigned, $available];
        }

        if ($type === 'bank') {
            $bank = StoreBank::find((int) $id);

            if (! $bank) {
                abort(404, 'Payment method not found.');
            }

            if ((int) $bank->business_id !== $businessId) {
                abort(403, 'You do not have access to this bank account.');
            }

            $method = $this->paymentMethodOrFail('bank_transfer');

            $assigned = $this->accessibleStoresQuery($request)
                ->whereHas('assignedBanks', fn ($q) => $q->where('store_banks.id', $bank->id)
                    ->where('store_bank.is_active', true));

            $available = $this->accessibleStoresQuery($request)
                ->whereDoesntHave('assignedBanks', fn ($q) => $q->where('store_banks.id', $bank->id)
                    ->where('store_bank.is_active', true));

            $header = [
                'type' => 'bank',
                'id' => $bank->id,
                'code' => $method->code,
                'name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => (bool) $bank->is_primary,
                'is_verified' => (bool) $bank->is_verified,
            ];

            return [$method, $header, $assigned, $available];
        }

        throw ValidationException::withMessages(['type' => 'Unknown payment method type.']);
    }

    private function accessibleStoresQuery(Request $request)
    {
        return $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);
    }

    /**
     * A store id on a form must be one the user can reach; a plain
     * `exists:stores,id` rule would let a business attach a method to a
     * competitor's store — the check legacy omitted.
     */
    private function accessibleStoreRule(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
            if ($value && ! $this->accessibleStoresQuery($request)->whereKey($value)->exists()) {
                $fail('You do not have access to that store.');
            }
        };
    }

    private function authorizeBank(Request $request, StoreBank $bank): void
    {
        if ((int) $bank->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this bank account.');
        }
    }

    private function authorizeGateway(int $gateway, int $businessId): object
    {
        $row = DB::table('business_payment_method')->where('id', $gateway)->first();

        if (! $row) {
            abort(404, 'Gateway not found.');
        }

        if ((int) $row->business_id !== $businessId) {
            abort(403, 'You do not have access to this gateway.');
        }

        return $row;
    }

    private function attachMethodToStore(Store $store, int $methodId, int $businessId): void
    {
        $exists = DB::table('store_payment_method')
            ->where('store_id', $store->id)
            ->where('payment_method_id', $methodId)
            ->exists();

        if ($exists) {
            DB::table('store_payment_method')
                ->where('store_id', $store->id)
                ->where('payment_method_id', $methodId)
                ->update(['is_active' => true, 'updated_at' => now()]);

            return;
        }

        DB::table('store_payment_method')->insert([
            'store_id' => $store->id,
            'payment_method_id' => $methodId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = PaymentMethod::find($methodId);

        if ($method) {
            $businessMethod = $this->businessMethodRow($businessId, $method->code);

            if ($businessMethod) {
                $config = json_decode($businessMethod->config ?: '{}', true) ?: [];
                $this->mirrorGatewayKeys($businessId, $method, $config);
            }
        }
    }

    private function assignBankToStore(StoreBank $bank, Store $store): void
    {
        $store->assignedBanks()->syncWithoutDetaching([$bank->id => ['is_active' => true]]);

        $method = PaymentMethod::where('code', 'bank_transfer')->first();

        if ($method) {
            $this->attachMethodToStore($store, (int) $method->id, (int) $bank->business_id);
        }
    }

    /**
     * Keep the business-level bank_transfer method in step with its banks:
     * config carries the primary account (legacy shape) and the method turns
     * off when the last bank is removed, so checkout cannot offer a transfer
     * with nowhere to send it.
     */
    private function syncBankTransferMethod(int $businessId): void
    {
        $method = PaymentMethod::where('code', 'bank_transfer')->first();

        if (! $method) {
            return;
        }

        $banks = StoreBank::where('business_id', $businessId)->orderByDesc('is_primary')->get();

        if ($banks->isEmpty()) {
            $this->upsertBusinessMethod($businessId, $method, [], false);

            return;
        }

        $primary = $banks->firstWhere('is_primary', true) ?? $banks->first();

        $this->upsertBusinessMethod($businessId, $method, [
            'bank_name' => $primary->bank_name,
            'account_number' => $primary->account_number,
            'account_name' => $primary->account_name,
            'bank_accounts_count' => $banks->count(),
        ], true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function upsertBusinessMethod(int $businessId, PaymentMethod $method, array $config, bool $isActive): void
    {
        $existing = DB::table('business_payment_method')
            ->where('business_id', $businessId)
            ->where('payment_method_id', $method->id)
            ->first();

        if ($existing) {
            DB::table('business_payment_method')->where('id', $existing->id)->update([
                'is_active' => $isActive,
                'config' => json_encode(array_merge(json_decode($existing->config ?: '{}', true) ?: [], $config)),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('business_payment_method')->insert([
            'business_id' => $businessId,
            'payment_method_id' => $method->id,
            'is_active' => $isActive,
            'config' => json_encode($config),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gateway keys live canonically in `business_payment_method.config`
     * (legacy shape, `{public_key, secret_key}`). The storefront checkout
     * reads `store_payment_method.api_keys`, a column the current schema never
     * gained — so keys written by legacy could never reach checkout. When the
     * column exists we mirror the keys onto each assigned store; until then
     * this is a deliberate no-op and the config remains the single source.
     *
     * @param  array<string, mixed>  $config
     */
    private function mirrorGatewayKeys(int $businessId, PaymentMethod $method, array $config): void
    {
        if (empty($config['secret_key']) || ! Schema::hasColumn('store_payment_method', 'api_keys')) {
            return;
        }

        $storeIds = Store::where('business_id', $businessId)->pluck('id');

        DB::table('store_payment_method')
            ->where('payment_method_id', $method->id)
            ->whereIn('store_id', $storeIds)
            ->update([
                'api_keys' => json_encode(['public_key' => $config['public_key'] ?? null, 'secret_key' => $config['secret_key']]),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     * @return array<string, mixed>
     */
    private function bankPayload(StoreBank $bank, $storeIds): array
    {
        return [
            'id' => $bank->id,
            'bank_name' => $bank->bank_name,
            'bank_code' => $bank->bank_code,
            'account_name' => $bank->account_name,
            'masked_account_number' => $bank->masked_account_number,
            'is_primary' => (bool) $bank->is_primary,
            'is_verified' => (bool) $bank->is_verified,
            'assigned_stores_count' => DB::table('store_bank')
                ->where('store_bank_id', $bank->id)
                ->where('is_active', true)
                ->whereIn('store_id', $storeIds)
                ->count(),
            'created_at' => $bank->created_at?->toISOString(),
        ];
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

        $config = json_decode($row->config ?: '{}', true) ?: [];

        return [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'is_active' => (bool) $row->is_active,
            // The secret is never serialized — legacy round-tripped it into
            // the edit modal in plaintext.
            'public_key_masked' => $this->maskKey($config['public_key'] ?? null),
            'has_secret_key' => ! empty($config['secret_key']),
            'assigned_stores_count' => DB::table('store_payment_method')
                ->where('payment_method_id', $row->payment_method_id)
                ->where('is_active', true)
                ->whereIn('store_id', $storeIds)
                ->count(),
            'created_at' => isset($row->created_at) ? Carbon::parse($row->created_at)->toISOString() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeSummary(Store $store): array
    {
        $status = $store->status;

        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'payment_mode' => $store->payment_mode,
        ];
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
