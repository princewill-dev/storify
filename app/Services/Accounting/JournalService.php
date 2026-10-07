<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Repositories\Management\Accounting\JournalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WS-23 — the manual journal workflows: normalise the request's lines, save
 * a draft, post a draft, post directly, delete a draft and reverse a posted
 * entry.
 *
 * The transaction boundaries live here, unchanged from the controller this
 * was lifted out of: a draft is its entry row plus its lines in one
 * transaction, and deleting a draft removes both together. Posting a draft
 * resolves the open fiscal period before stamping the entry, so a closed
 * period leaves the entry exactly as it was.
 *
 * Ledger posting is delegated to LedgerPostingService; failures deliberately
 * propagate so the controller can turn them into the same 422 message it
 * returned before. HTTP shape (status codes, messages, envelope) stays in
 * the controller.
 */
final class JournalService
{
    public function __construct(
        private readonly JournalRepository $repository,
        private readonly LedgerPostingService $posting,
        private readonly LedgerSetupService $setup,
    ) {}

    /**
     * Drop blank rows, refuse debit-and-credit lines and unknown accounts.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array{account_id: int, debit: int, credit: int, description: ?string}>
     */
    public function normaliseLines(array $rawLines, int $businessId): array
    {
        $lines = [];

        foreach ($rawLines as $index => $line) {
            $debit = (int) ($line['debit_kobo'] ?? 0);
            $credit = (int) ($line['credit_kobo'] ?? 0);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.credit_kobo" => 'A journal line cannot have both a debit and a credit.',
                ]);
            }

            $lines[] = [
                'account_id' => (int) $line['ledger_account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (empty($lines)) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one line with an amount.',
            ]);
        }

        $referenced = array_unique(array_column($lines, 'account_id'));

        $owned = $this->repository->ownedLedgerAccountIds($businessId, $referenced);

        if (array_diff($referenced, $owned)) {
            throw ValidationException::withMessages([
                'lines' => 'One or more accounts are invalid.',
            ]);
        }

        return $lines;
    }

    /**
     * Create the draft entry and its lines in one transaction.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array{account_id: int, debit: int, credit: int, description: ?string}>  $lines
     */
    public function saveDraft(int $businessId, array $validated, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($businessId, $validated, $lines) {
            $entry = JournalEntry::create([
                'business_id' => $businessId,
                'entry_date' => $validated['entry_date'],
                'memo' => $validated['memo'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'status' => JournalEntry::STATUS_DRAFT,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'ledger_account_id' => $line['account_id'],
                    'description' => $line['description'],
                    'debit_kobo' => $line['debit'],
                    'credit_kobo' => $line['credit'],
                    'currency' => 'NGN',
                ]);
            }

            return $entry;
        });
    }

    /**
     * Post a balanced entry straight to the ledger. Returns null when the
     * posting primitive found nothing to post (the caller reports that).
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array{account_id: int, debit: int, credit: int, description: ?string}>  $lines
     */
    public function post(int $businessId, array $validated, array $lines, int $userId): ?JournalEntry
    {
        return $this->posting->post($businessId, $lines, [
            'memo' => $validated['memo'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'user_id' => $userId,
            'date' => $validated['entry_date'],
        ]);
    }

    /**
     * Stamp a draft as posted against its open fiscal period. Throws when
     * the period is closed — the caller reports that message, and the entry
     * is deliberately left as a draft.
     */
    public function postDraft(JournalEntry $entry, int $userId): JournalEntry
    {
        $period = $this->setup->resolveOpenPeriod($entry->business_id, $entry->entry_date->toDateString());

        $entry->update([
            'status' => JournalEntry::STATUS_POSTED,
            'fiscal_period_id' => $period->id,
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        return $entry;
    }

    /**
     * Delete a draft with its lines in one transaction.
     */
    public function deleteDraft(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry->lines()->delete();
            $entry->delete();
        });
    }

    /**
     * Create the contra entry for a posted entry and void the original.
     * Returns null when the entry has no lines to reverse.
     */
    public function reverse(JournalEntry $entry, int $userId): ?JournalEntry
    {
        return $this->posting->reverseEntry($entry, 'Reversal of '.$entry->entry_number, $userId);
    }
}
