<?php

namespace App\Services\Accounting;

use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Repositories\Management\Accounting\BankReconciliationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WS-37 — bank reconciliation workflows.
 *
 * The multi-table steps and their transaction boundaries, extracted from
 * ReconciliationController: persisting an import with its statement lines,
 * the one-to-one auto-match sweep, and the completion snapshot. HTTP shape
 * (status codes, messages, guard order) stays in the controller.
 */
final class BankReconciliationService
{
    public function __construct(private readonly BankReconciliationRepository $repository) {}

    /**
     * Create the import and each parsed statement line in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{date: string, description: ?string, reference: ?string, amount_kobo: int}>  $rows
     */
    public function importStatement(array $attributes, array $rows): BankStatementImport
    {
        return DB::transaction(function () use ($attributes, $rows) {
            $import = $this->repository->createImport($attributes);

            foreach ($rows as $row) {
                $this->repository->createLine([
                    'bank_statement_import_id' => $import->id,
                    'business_id' => $import->business_id,
                    'transaction_date' => $row['date'],
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                    'amount_kobo' => $row['amount_kobo'],
                    'status' => 'unmatched',
                ]);
            }

            return $import;
        });
    }

    /**
     * Auto-match unmatched statement lines: ±7 days, exact amount, nearest
     * date first, and each journal line claimed at most once.
     *
     * The one-to-one invariant has two halves and both must survive: the
     * business-wide claimed ids loaded before the transaction keep already
     * matched ledger lines out, and the in-memory $usedJournalLineIds list is
     * appended to inside the walk so a journal line found for one statement
     * line can never be reused by the next.
     *
     * @return int the number of statement lines matched
     */
    public function autoMatch(BankStatementImport $import): int
    {
        // Every journal line already claimed anywhere in this business's
        // books, so auto-match never double-books the same ledger line.
        $usedJournalLineIds = $this->repository->claimedJournalLineIds((int) $import->business_id);

        $matched = 0;

        DB::transaction(function () use ($import, &$matched, &$usedJournalLineIds) {
            $lines = $this->repository->unmatchedLines($import);

            foreach ($lines as $line) {
                $date = Carbon::parse($line->transaction_date);
                $amountKobo = (int) $line->amount_kobo;

                $candidate = $this->repository->firstAutoMatchCandidate(
                    (int) $import->business_id,
                    (int) $import->ledger_account_id,
                    $date,
                    $amountKobo,
                    $usedJournalLineIds,
                );

                if (! $candidate) {
                    continue;
                }

                $usedJournalLineIds[] = $candidate->journal_line_id;

                $this->repository->markLineMatched($line, (int) $candidate->journal_line_id);

                $matched++;
            }
        });

        return $matched;
    }

    /**
     * Snapshot the completed reconciliation and freeze the import, atomically.
     *
     * @param  array<string, mixed>  $data  validated start_date/end_date
     */
    public function complete(
        BankStatementImport $import,
        array $data,
        int $statementClosingKobo,
        int $clearedBalanceKobo,
        int $completedBy,
    ): BankReconciliation {
        return DB::transaction(function () use ($import, $data, $statementClosingKobo, $clearedBalanceKobo, $completedBy) {
            $reconciliation = $this->repository->createReconciliation([
                'business_id' => $import->business_id,
                'store_bank_id' => $import->store_bank_id,
                'ledger_account_id' => $import->ledger_account_id,
                'bank_statement_import_id' => $import->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'statement_closing_balance_kobo' => $statementClosingKobo,
                'cleared_balance_kobo' => $clearedBalanceKobo,
                'difference_kobo' => $statementClosingKobo - $clearedBalanceKobo,
                'status' => 'completed',
                'completed_by' => $completedBy,
                'completed_at' => now(),
            ]);

            $this->repository->markImportReconciled($import);

            return $reconciliation;
        });
    }
}
