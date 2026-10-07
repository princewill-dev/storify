<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\BankAccountRequest;
use App\Http\Requests\Admin\ListBankAccountsRequest;
use App\Http\Resources\Admin\BankAccountResource;
use App\Models\BankAccount;
use App\Repositories\Admin\BankAccountRepository;
use App\Services\Admin\BankAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-12 — platform receiving bank accounts.
 *
 * These rows are what storefront/manual-transfer instructions surface to
 * customers, so bank_name and account_number are required and the logo is a
 * public-disk upload replacing (and deleting) the previous file.
 *
 * The legacy resource route also exposed a `show` action whose Blade view
 * never existed (every hit 500'd); there is deliberately no show route here.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListBankAccountsRequest` for the list filters, `BankAccountRequest` for
 * the shared create/edit payload), query building in BankAccountRepository,
 * the row/file writes and their transaction boundary in BankAccountService,
 * and response shaping in `BankAccountResource`; the platform-admin guard
 * deliberately stays here so its order is unchanged. The toggle is a single
 * model write with no transaction or shared query, so it stays on the
 * controller (wrapping it would be indirection with no benefit).
 */
class BankAccountController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly BankAccountRepository $accounts,
        private readonly BankAccountService $service,
    ) {}

    public function index(ListBankAccountsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $accounts = $this->accounts->paginateForAdmin($request->validated());

        return $this->ok(
            ['bank_accounts' => $accounts->getCollection()->map(fn (BankAccount $account) => $this->payload($account))->values()->all()],
            null,
            200,
            $this->paginationMeta($accounts),
        );
    }

    public function store(BankAccountRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $account = $this->service->create(
            $request->validated(),
            $request->hasFile('logo') ? $request->file('logo') : null,
            $request->boolean('is_active', true),
        );

        Log::info('api.admin.bank_account_created', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $account->id,
            'bank_name' => $account->bank_name,
        ]);

        return $this->ok(['bank_account' => $this->payload($account)], 'Bank account created successfully.', 201);
    }

    public function update(BankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // `has`/`boolean` are read here, not in the rules: an omitted flag
        // leaves the stored value alone, and sending `false` is not the same
        // as sending nothing.
        $this->service->update(
            $bankAccount,
            $request->validated(),
            $request->hasFile('logo') ? $request->file('logo') : null,
            $request->has('is_active') ? $request->boolean('is_active') : null,
        );

        Log::info('api.admin.bank_account_updated', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return $this->ok(['bank_account' => $this->payload($bankAccount->fresh())], 'Bank account updated successfully.');
    }

    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->service->delete($bankAccount);

        Log::info('api.admin.bank_account_deleted', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return $this->ok([], 'Bank account deleted successfully.');
    }

    public function toggleActive(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $bankAccount->update(['is_active' => ! $bankAccount->is_active]);

        Log::info('api.admin.bank_account_toggled', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'is_active' => $bankAccount->is_active,
        ]);

        return $this->ok(
            ['bank_account' => $this->payload($bankAccount->fresh())],
            'Bank account status updated successfully.',
        );
    }

    /**
     * The row payload, shaped by BankAccountResource. Kept as a thin private
     * seam so the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(BankAccount $account): array
    {
        return BankAccountResource::make($account)->resolve();
    }
}
