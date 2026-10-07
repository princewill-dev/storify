<?php

namespace App\Services\Accounting;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money\Naira;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-16 — the expense workflows: record-and-post, void and delete.
 *
 * The transaction boundaries, the receipt file handling and the non-fatal
 * ledger posting all live here, unchanged from the controller this was lifted
 * out of:
 *
 *  - the receipt is stored, then the expense written in one transaction and
 *    posted; a posting failure warns and leaves the row in place (unposted, so
 *    it can still be deleted or voided) instead of rolling the record back and
 *    losing it;
 *  - a void reverses the original entry and flips the status in one
 *    transaction — a void row without its contra entry (or vice versa) would
 *    misstate the books;
 *  - deleting an unposted expense also removes its receipt file (legacy left
 *    it orphaned on the disk forever).
 *
 * Ledger posting is delegated to LedgerPostingService; this class only owns
 * the money arithmetic, the ordering and the non-fatal failure handling.
 */
class ExpenseService
{
    /** Receipts are public files, stored exactly where the legacy form put them. */
    private const RECEIPT_DISK = 'public';

    public function __construct(private readonly LedgerPostingService $posting) {}

    /**
     * Record the expense and post it to the ledger (non-fatally).
     *
     * The controller's private toKobo() was byte-identical to
     * Naira::koboFromDecimalOrFloat() — exact string path for plain decimals,
     * rounded float cast otherwise — so call sites keep that contract.
     *
     * @param  array<string, mixed>  $validated
     * @return array{expense: Expense, warning: ?string}
     */
    public function recordExpense(User $user, array $validated, ?UploadedFile $receipt): array
    {
        $amountKobo = Naira::koboFromDecimalOrFloat($validated['amount']);
        $taxKobo = Naira::koboFromDecimalOrFloat($validated['tax'] ?? 0);

        $receiptPath = $receipt?->store('expense-receipts', self::RECEIPT_DISK);

        $expense = DB::transaction(fn () => Expense::create([
            'business_id' => $user->business_id,
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'ledger_account_id' => $validated['ledger_account_id'],
            'supplier_id' => $validated['supplier_id'] ?? null,
            'expense_date' => $validated['expense_date'],
            'amount_kobo' => $amountKobo,
            'tax_kobo' => $taxKobo,
            'total_kobo' => $amountKobo + $taxKobo,
            'currency' => 'NGN',
            'payment_account_id' => $validated['payment_account_id'] ?? null,
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'receipt_path' => $receiptPath,
            'status' => Expense::STATUS_PAID,
            'created_by' => $user->id,
        ]));

        $warning = null;

        try {
            $entry = $this->posting->postExpense($expense, $user->id);

            if ($entry) {
                $expense->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            // Parity with legacy: a posting failure warns and leaves the row
            // in place (unposted, so it can still be deleted or voided)
            // instead of rolling the expense back and losing the record.
            $warning = 'Expense recorded, but ledger posting failed: '.$e->getMessage();

            Log::warning('api.management.expense_posting_failed', [
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('api.management.expense_created', [
            'user_id' => $user->id,
            'expense_id' => $expense->id,
            'total_kobo' => $expense->total_kobo,
            'posted' => $expense->journal_entry_id !== null,
        ]);

        return ['expense' => $expense, 'warning' => $warning];
    }

    /**
     * Reverse the original posting (when it was actually posted) and flip the
     * status to void in one transaction. The caller leaves the expense
     * un-voided when this throws.
     *
     * The original entry is read by the caller before this runs: a failure
     * reading it is not a failed void and must not be reported as one, exactly
     * as before this was extracted.
     */
    public function voidExpense(User $user, Expense $expense, ?JournalEntry $entry): void
    {
        // Reversal and status flip are one transition: a void row without its
        // contra entry (or vice versa) would misstate the books.
        DB::transaction(function () use ($user, $expense, $entry) {
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                $this->posting->reverseEntry($entry, 'Void expense #'.$expense->id, $user->id);
            }

            $expense->update(['status' => Expense::STATUS_VOID]);
        });
    }

    /**
     * Delete an unposted expense and its receipt file.
     *
     * Improvement on legacy: deleting an unposted expense used to leave its
     * receipt file orphaned on the disk forever.
     */
    public function deleteExpense(Expense $expense): void
    {
        $receiptPath = $expense->receipt_path;

        $expense->delete();

        if ($receiptPath) {
            Storage::disk(self::RECEIPT_DISK)->delete($receiptPath);
        }
    }
}
