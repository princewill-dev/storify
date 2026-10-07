<?php

namespace App\Repositories\Management\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Support\Collection;

/**
 * WS-23 — chart-of-accounts data access.
 *
 * Everything the ChartOfAccountsController used to build inline: the ordered
 * chart with its parent link, the posted debit/credit totals that back each
 * row's balance, and the parent-chain walk the write path uses to refuse
 * loops.
 *
 * Read-only: no DB::transaction and no abort() live here — the controller
 * keeps the HTTP guards and the resource shapes the payload.
 */
final class LedgerAccountRepository
{
    /**
     * The business's chart, ordered by code, with the parent link the list
     * renders. Scoped by business_id so another business's chart never leaks.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function listForBusiness(int $businessId): Collection
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->with('parent:id,code,name')
            ->orderBy('code')
            ->get();
    }

    /**
     * Posted debit/credit totals keyed by ledger account id.
     *
     * @return Collection<int, \stdClass>
     */
    public function postedBalances(int $businessId): Collection
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
     * Does $candidateId sit anywhere in the parent chain of the subtree headed
     * by $account? The guard exists because legacy only hid the current
     * account in the picker and never validated the tree, so a crafted request
     * could close a loop.
     *
     * Walks up from the candidate one parent at a time, exactly as the
     * controller did; charts are shallow, so the per-level relation load is
     * kept.
     */
    public function isInSubtreeOf(LedgerAccount $account, int $candidateId): bool
    {
        $cursor = LedgerAccount::query()->find($candidateId);

        while ($cursor?->parent_id) {
            if ((int) $cursor->parent_id === (int) $account->id) {
                return true;
            }

            $cursor = $cursor->parent;
        }

        return false;
    }
}
