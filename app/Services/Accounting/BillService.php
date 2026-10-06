<?php

namespace App\Services\Accounting;

use App\Models\Bill;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Accounting\BillRepository;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WS-22 — the bill workflows: create-and-post, record-payment and void.
 *
 * The transaction boundaries, the row lock and the non-fatal ledger posting
 * all live here, unchanged from the controller this was lifted out of:
 *
 *  - a bill is created with its items and weighted-average inventory costing
 *    in one transaction, then posted; a posting failure warns and leaves the
 *    bill in place (unposted) instead of rolling the record back;
 *  - a payment re-reads the bill under lockForUpdate and re-validates against
 *    the locked balance before writing, so two concurrent payments cannot
 *    together exceed what is owed;
 *  - a void reverses the original entry and flips the status in one
 *    transaction — a void row without its contra entry (or vice versa) would
 *    misstate the books.
 *
 * Ledger posting is delegated to LedgerPostingService; this class only owns
 * the money arithmetic, the ordering and the non-fatal failure handling.
 */
class BillService
{
    public function __construct(
        private readonly BillRepository $repository,
        private readonly LedgerPostingService $posting,
        private readonly InventoryCostingService $costing,
    ) {}

    /**
     * Create the bill with its line items, apply inventory costing, then post
     * to the ledger (non-fatally).
     *
     * @param  array<string, mixed>  $validated
     * @return array{bill: Bill, warning: ?string}
     */
    public function recordBill(User $user, array $validated): array
    {
        [$items, $subtotalKobo] = $this->lineItems($validated['items']);

        $taxKobo = Naira::koboFromDecimalOrFloat($validated['tax'] ?? 0);
        $totalKobo = $subtotalKobo + $taxKobo;

        $billNumber = trim((string) ($validated['bill_number'] ?? '')) ?: 'BILL-'.strtoupper(Str::random(8));

        $bill = DB::transaction(function () use ($user, $validated, $items, $subtotalKobo, $taxKobo, $totalKobo, $billNumber) {
            $bill = $this->repository->createBill([
                'business_id' => $user->business_id,
                'supplier_id' => $validated['supplier_id'],
                'bill_number' => $billNumber,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'subtotal_kobo' => $subtotalKobo,
                'tax_kobo' => $taxKobo,
                'total_kobo' => $totalKobo,
                'amount_paid_kobo' => 0,
                'status' => Bill::STATUS_OPEN,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->repository->createBillItems($bill, $items);

            $this->applyInventoryCosting($bill, $items);

            return $bill;
        });

        $warning = null;

        try {
            $entry = $this->posting->postBill($bill, $user->id);

            if ($entry) {
                $this->repository->setBillJournalEntry($bill, $entry);
            }
        } catch (\Throwable $e) {
            // Parity with legacy: a posting failure warns and leaves the bill
            // in place (unposted) instead of rolling back the record.
            $warning = 'Bill saved, but ledger posting failed: '.$e->getMessage();

            Log::warning('api.management.bill_posting_failed', [
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('api.management.bill_created', [
            'user_id' => $user->id,
            'bill_id' => $bill->id,
            'total_kobo' => $bill->total_kobo,
            'posted' => $bill->journal_entry_id !== null,
        ]);

        return ['bill' => $bill, 'warning' => $warning];
    }

    /**
     * Record a payment against the bill: lock the row, re-check the balance,
     * write the payment and advance open → partial → paid, then post to the
     * ledger (non-fatally).
     *
     * @param  array<string, mixed>  $validated
     * @return array{bill: Bill, warning: ?string}
     */
    public function recordPayment(User $user, Bill $bill, array $validated): array
    {
        $amountKobo = Naira::koboFromDecimalOrFloat($validated['amount']);

        $payment = DB::transaction(function () use ($user, $validated, $bill, $amountKobo) {
            $locked = $this->repository->findBillForUpdate($bill->id);

            if ($amountKobo <= 0 || (int) $locked->amount_paid_kobo + $amountKobo > (int) $locked->total_kobo) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment is more than the remaining balance.',
                ]);
            }

            $payment = $this->repository->createPayment([
                'business_id' => $locked->business_id,
                'bill_id' => $locked->id,
                'supplier_id' => $locked->supplier_id,
                'payment_date' => $validated['payment_date'],
                'amount_kobo' => $amountKobo,
                'payment_account_id' => $validated['payment_account_id'] ?? null,
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'created_by' => $user->id,
            ]);

            $locked->amount_paid_kobo = (int) $locked->amount_paid_kobo + $amountKobo;
            $locked->status = $locked->isFullyPaid() ? Bill::STATUS_PAID : Bill::STATUS_PARTIAL;
            $this->repository->saveBill($locked);

            return $payment;
        });

        $warning = null;

        try {
            $entry = $this->posting->postBillPayment($payment, $user->id);

            if ($entry) {
                $this->repository->setPaymentJournalEntry($payment, $entry);
            }
        } catch (\Throwable $e) {
            // Payment kept, ledger warned — legacy did the same so a transient
            // posting failure could never lose a recorded payment.
            $warning = 'Payment saved, but ledger posting failed: '.$e->getMessage();

            Log::warning('api.management.bill_payment_posting_failed', [
                'user_id' => $user->id,
                'bill_id' => $bill->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('api.management.bill_payment_recorded', [
            'user_id' => $user->id,
            'bill_id' => $bill->id,
            'payment_id' => $payment->id,
            'amount_kobo' => $amountKobo,
            'status' => $bill->fresh()->status,
        ]);

        return ['bill' => $bill, 'warning' => $warning];
    }

    /**
     * Reverse the original posting (when it was actually posted) and flip the
     * status to void in one transaction. The caller leaves the bill un-voided
     * when this throws.
     *
     * The original entry and the post-commit log stay with the caller: the
     * controller's reverse-failure guard must wrap the transaction (and only
     * the transaction), exactly as it did before this was extracted.
     */
    public function voidBill(User $user, Bill $bill, ?JournalEntry $entry): void
    {
        // Reversal and status flip are one transition: a void row without
        // its contra entry (or vice versa) would misstate the books.
        DB::transaction(function () use ($user, $bill, $entry) {
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                $this->posting->reverseEntry($entry, 'Void bill '.$bill->bill_number, $user->id);
            }

            $this->repository->markBillVoid($bill);
        });
    }

    /**
     * Turn validated input into kobo-exact item rows and a subtotal.
     *
     * Quantities keep their 3-dp precision as integer milli-units and unit
     * costs are integer kobo, so `quantity × unit cost` is integer arithmetic
     * with a single half-up rounding step — no float ever touches money.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function lineItems(array $input): array
    {
        $items = [];
        $subtotalKobo = 0;

        foreach ($input as $item) {
            $quantityMilli = $this->quantityToMilli($item['quantity']);
            $unitCostKobo = Naira::koboFromDecimalOrFloat($item['unit_cost']);
            $amountKobo = intdiv($quantityMilli * $unitCostKobo + 500, 1000);
            $subtotalKobo += $amountKobo;

            $items[] = [
                'description' => $item['description'],
                'quantity' => $this->milliToDecimal($quantityMilli),
                'unit_cost_kobo' => $unitCostKobo,
                'amount_kobo' => $amountKobo,
                'expense_account_id' => $item['expense_account_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
            ];
        }

        return [$items, $subtotalKobo];
    }

    /**
     * Weighted-average inventory costing for product-linked lines.
     *
     * Stock is tracked in whole units (StockLocation.quantity is an integer),
     * so a 3-dp bill quantity is rounded to the nearest unit before the
     * average is recalculated. Legacy cast to int — truncating, so a 2.9-unit
     * receipt was costed as 2.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function applyInventoryCosting(Bill $bill, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['product_id']) || (int) $item['unit_cost_kobo'] <= 0) {
                continue;
            }

            $product = $this->repository->findProduct($bill->business_id, (int) $item['product_id']);

            if ($product) {
                $this->costing->recordReceipt($product, (int) round((float) $item['quantity']), (int) $item['unit_cost_kobo']);
            }
        }
    }

    /**
     * Quantity as typed → integer milli-units, keeping the legacy 3-dp
     * precision exactly (e.g. "2.5" → 2500).
     */
    private function quantityToMilli(mixed $quantity): int
    {
        $value = trim((string) $quantity);

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

            return ((int) $whole) * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');
        }

        return (int) round(((float) $value) * 1000);
    }

    /** Integer milli-units → exact decimal quantity string ("1005" → "1.005"). */
    private function milliToDecimal(int $milli): string
    {
        return intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}
