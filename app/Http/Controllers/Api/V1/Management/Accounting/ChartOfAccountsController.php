<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\StoreLedgerAccountRequest;
use App\Http\Requests\Management\Accounting\UpdateLedgerAccountRequest;
use App\Http\Resources\Management\Accounting\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Repositories\Management\Accounting\LedgerAccountRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * WS-23 — Chart of Accounts.
 *
 * The list re-registers `accounting/accounts`, the URI the shared
 * AccountingController already serves — feature modules load after the shared
 * route file, so this registration wins and the computed balances land on the
 * endpoint the SPA already calls. The create/update/toggle routes are new.
 *
 * Layering: the HTTP shape (statuses, messages, envelope) stays here; field
 * validation lives in App\Http\Requests\Management\Accounting, queries in
 * App\Repositories\Management\Accounting\LedgerAccountRepository and the
 * payload in App\Http\Resources\Management\Accounting\LedgerAccountResource
 * (which documents the legacy sign correction).
 */
class ChartOfAccountsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly LedgerAccountRepository $repository,
        private readonly TenantGuard $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $accounts = $this->repository->listForBusiness($businessId);

        $balances = $this->repository->postedBalances($businessId);

        $rows = $accounts
            ->map(fn (LedgerAccount $account) => (new LedgerAccountResource($account, $balances))->resolve())
            ->values()
            ->all();

        return $this->ok(['accounts' => $rows]);
    }

    public function store(StoreLedgerAccountRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        $account = LedgerAccount::create([
            'business_id' => $user->business_id,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'subtype' => $data['subtype'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'currency' => 'NGN',
            // Accounts a business creates itself are never system accounts —
            // the flag is what protects the seeded chart from deactivation.
            'is_system' => false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        Log::info('api.management.ledger_account_created', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'code' => $account->code,
        ]);

        return $this->ok(
            ['account' => (new LedgerAccountResource($account->load('parent')))->resolve()],
            'Account created.',
            201,
        );
    }

    public function update(UpdateLedgerAccountRequest $request, LedgerAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        $data = $request->validated();

        // Legacy's edit form could deactivate a system account even though the
        // toggle route refused it — the guard belongs on every write path.
        if ($account->is_system && $account->is_active && ! ($data['is_active'] ?? true)) {
            return $this->error('System accounts cannot be deactivated.');
        }

        $this->assertParentAllowed($account, $data['parent_id'] ?? null);

        $account->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'subtype' => $data['subtype'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? $account->is_active,
        ]);

        return $this->ok(
            ['account' => (new LedgerAccountResource($account->fresh()->load('parent')))->resolve()],
            'Account updated.',
        );
    }

    public function toggle(Request $request, LedgerAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        if ($account->is_system && $account->is_active) {
            return $this->error('System accounts cannot be deactivated.');
        }

        $account->update(['is_active' => ! $account->is_active]);

        return $this->ok(
            ['account' => (new LedgerAccountResource($account->fresh()->load('parent')))->resolve()],
            $account->is_active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    /**
     * A parent must not be the account itself or anything beneath it —
     * legacy only hid the current account in the picker and never validated
     * the tree, so a crafted request could close a loop. The subtree walk
     * lives in the repository; the two refusals stay here, in the write
     * sequence, because their response shape is HTTP.
     */
    private function assertParentAllowed(LedgerAccount $account, ?int $parentId): void
    {
        if (! $parentId) {
            return;
        }

        if ($parentId === (int) $account->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'An account cannot be its own parent.',
            ]);
        }

        if ($this->repository->isInSubtreeOf($account, $parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => 'That account sits below this one, so it cannot be its parent.',
            ]);
        }
    }

    private function authorizeAccount(Request $request, LedgerAccount $account): void
    {
        $this->tenant->authorizeBusiness($account, $this->user($request), 'You do not have access to this account.');
    }
}
