<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-23 — Chart of Accounts.
 *
 * The list re-registers `accounting/accounts`, the URI the shared
 * AccountingController already serves — feature modules load after the shared
 * route file, so this registration wins and the computed balances land on the
 * endpoint the SPA already calls. The create/update/toggle routes are new.
 *
 * Balances are sign-corrected per account type exactly as legacy did: assets
 * and expenses grow on the debit side, everything else on the credit side.
 */
class ChartOfAccountsController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->with('parent:id,code,name')
            ->orderBy('code')
            ->get();

        $balances = $this->postedBalances($businessId);

        $rows = $accounts->map(fn (LedgerAccount $account) => $this->accountPayload($account, $balances))->values()->all();

        return $this->ok(['accounts' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request, $user->business_id);

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

        return $this->ok(['account' => $this->accountPayload($account->load('parent'))], 'Account created.', 201);
    }

    public function update(Request $request, LedgerAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        $data = $this->validated($request, $account->business_id, $account->id);

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

        return $this->ok(['account' => $this->accountPayload($account->fresh()->load('parent'))], 'Account updated.');
    }

    public function toggle(Request $request, LedgerAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        if ($account->is_system && $account->is_active) {
            return $this->error('System accounts cannot be deactivated.');
        }

        $account->update(['is_active' => ! $account->is_active]);

        return $this->ok(
            ['account' => $this->accountPayload($account->fresh()->load('parent'))],
            $account->is_active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('ledger_accounts', 'code')
                    ->where(fn ($q) => $q->where('business_id', $businessId))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LedgerAccount::TYPES)],
            // Legacy stored a nullable subtype without exposing it in the form;
            // the field stays optional rather than being dropped.
            'subtype' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * A parent must not be the account itself or anything beneath it —
     * legacy only hid the current account in the picker and never validated
     * the tree, so a crafted request could close a loop.
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

        $cursor = LedgerAccount::query()->find($parentId);

        while ($cursor?->parent_id) {
            if ((int) $cursor->parent_id === (int) $account->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'That account sits below this one, so it cannot be its parent.',
                ]);
            }

            $cursor = $cursor->parent;
        }
    }

    private function authorizeAccount(Request $request, LedgerAccount $account): void
    {
        if ((int) $account->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this account.');
        }
    }

    /**
     * Posted debit/credit totals keyed by ledger account id.
     *
     * @return Collection<int, \stdClass>
     */
    private function postedBalances(int $businessId): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->keyBy('ledger_account_id');
    }

    /**
     * @param  Collection<int, \stdClass>|null  $balances
     * @return array<string, mixed>
     */
    private function accountPayload(LedgerAccount $account, ?Collection $balances = null): array
    {
        $balanceKobo = null;

        if ($balances !== null) {
            $row = $balances->get($account->id);
            $debit = (int) ($row->debit ?? 0);
            $credit = (int) ($row->credit ?? 0);

            $balanceKobo = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
        }

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'parent' => $account->parent ? [
                'id' => $account->parent->id,
                'code' => $account->parent->code,
                'name' => $account->parent->name,
            ] : null,
            'description' => $account->description,
            'is_system' => (bool) $account->is_system,
            'is_active' => (bool) $account->is_active,
            'balance_kobo' => $balanceKobo,
        ];
    }
}
