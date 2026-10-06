<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-16 — Accounting: Expenses.
 *
 * The legacy module's daily-use heart: list with stats/filters, record (which
 * posts to the ledger immediately), detail with the journal trace, void
 * (reversal entry) and delete (unposted only).
 *
 * The list re-registers `accounting/expenses`, the one URI the shared
 * AccountingController already serves — feature modules load after the shared
 * route file, so this registration wins and the legacy filters/stats land on
 * the same endpoint the SPA already calls. All other routes are new.
 */
class ExpenseController extends ApiController
{
    use ResolvesManagementContext;

    /** Receipts are public files, stored exactly where the legacy form put them. */
    private const RECEIPT_DISK = 'public';

    /** @var array<string, string> */
    private const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ];

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                Expense::STATUS_DRAFT,
                Expense::STATUS_APPROVED,
                Expense::STATUS_PAID,
                Expense::STATUS_VOID,
            ])],
            'category' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $categoryId = $filters['category_id'] ?? $filters['category'] ?? null;

        $expenses = Expense::query()
            ->where('business_id', $businessId)
            ->with(['category:id,name', 'supplier:id,name', 'ledgerAccount:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($categoryId, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            // `data` stays the flat row array the shared endpoint already
            // returned, so existing consumers of the endpoint keep working;
            // the stats block and filter categories ride in `meta` beside the
            // pagination keys, the same shape WS-23's re-registered journal
            // endpoint uses.
            $expenses->getCollection()->map(fn (Expense $expense) => $this->summary($expense))->all(),
            null,
            200,
            $this->paginationMeta($expenses) + [
                'stats' => [
                    // Legacy's month/year cards ignore the filters and only
                    // exclude void rows; only the Records card is filtered.
                    'month_kobo' => (int) Expense::query()
                        ->where('business_id', $businessId)
                        ->where('status', '!=', Expense::STATUS_VOID)
                        ->whereMonth('expense_date', now()->month)
                        ->whereYear('expense_date', now()->year)
                        ->sum('total_kobo'),
                    'year_kobo' => (int) Expense::query()
                        ->where('business_id', $businessId)
                        ->where('status', '!=', Expense::STATUS_VOID)
                        ->whereYear('expense_date', now()->year)
                        ->sum('total_kobo'),
                    'records' => $expenses->total(),
                ],
                'categories' => $this->categories($businessId),
            ],
        );
    }

    public function store(Request $request, LedgerPostingService $posting): JsonResponse
    {
        $user = $this->user($request);
        $validated = $this->validated($request, $user->business_id);

        $amountKobo = $this->toKobo($validated['amount']);
        $taxKobo = $this->toKobo($validated['tax'] ?? 0);

        $receiptPath = $request->hasFile('receipt')
            ? $request->file('receipt')->store('expense-receipts', self::RECEIPT_DISK)
            : null;

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
            $entry = $posting->postExpense($expense, $user->id);

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

        $payload = ['expense' => $this->detail($expense->fresh())];

        if ($warning !== null) {
            $payload['posting_warning'] = $warning;
        }

        return $this->ok($payload, 'Expense recorded.', 201);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        return $this->ok(['expense' => $this->detail($expense)]);
    }

    public function void(Request $request, Expense $expense, LedgerPostingService $posting): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->status === Expense::STATUS_VOID) {
            return $this->error('This expense is already void.');
        }

        $entry = $expense->journal_entry_id
            ? JournalEntry::find($expense->journal_entry_id)
            : null;

        try {
            // Reversal and status flip are one transition: a void row without
            // its contra entry (or vice versa) would misstate the books.
            DB::transaction(function () use ($request, $expense, $posting, $entry) {
                if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                    $posting->reverseEntry($entry, 'Void expense #'.$expense->id, $this->user($request)->id);
                }

                $expense->update(['status' => Expense::STATUS_VOID]);
            });
        } catch (\Throwable $e) {
            // The reversing entry could not be written, so the expense is
            // deliberately left un-voided (legacy did the same).
            return $this->error('Expense could not be voided: '.$e->getMessage());
        }

        Log::info('api.management.expense_voided', [
            'user_id' => $this->user($request)->id,
            'expense_id' => $expense->id,
            'journal_entry_id' => $entry?->id,
        ]);

        return $this->ok(['expense' => $this->detail($expense->fresh())], 'Expense voided.');
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->journal_entry_id) {
            return $this->error('Posted expenses cannot be deleted. Void it instead.');
        }

        $receiptPath = $expense->receipt_path;

        $expense->delete();

        // Improvement on legacy: deleting an unposted expense used to leave
        // its receipt file orphaned on the disk forever.
        if ($receiptPath) {
            Storage::disk(self::RECEIPT_DISK)->delete($receiptPath);
        }

        Log::info('api.management.expense_deleted', [
            'user_id' => $this->user($request)->id,
            'expense_id' => $expense->id,
        ]);

        return $this->ok([], 'Expense deleted.');
    }

    /**
     * Form pickers in one round trip: active categories, active expense
     * accounts, active cash/bank/gateway accounts and active suppliers.
     *
     * The roadmap pointed the form at `accounting/accounts?active=1&type=`;
     * that URI belongs to the shared AccountingController and re-registering
     * it from a feature module would shadow it fleet-wide, so the pickers
     * (which are only ever "active" rows) come from here instead.
     */
    public function options(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        return $this->ok([
            'categories' => $this->categories($businessId),
            'expense_accounts' => LedgerAccount::query()
                ->where('business_id', $businessId)
                ->ofType(LedgerAccount::TYPE_EXPENSE)
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (LedgerAccount $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                ])->all(),
            'payment_accounts' => LedgerAccount::query()
                ->where('business_id', $businessId)
                ->active()
                ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'subtype'])
                ->map(fn (LedgerAccount $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'subtype' => $account->subtype,
                ])->all(),
            'suppliers' => Supplier::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
                ->all(),
            'payment_methods' => collect(self::PAYMENT_METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $businessId): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('business_id', $businessId)],
            // Legacy validated existence only; deactivated accounts vanished
            // from every picker but a crafted request could still post to one.
            'ledger_account_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)->where('is_active', true)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:5120'],
        ]);
    }

    /**
     * Naira as typed by the user → integer kobo, without doing money maths in
     * floats. Validation allows numeric strings (including scientific
     * notation); plain decimals take the exact string path, anything exotic
     * falls back to a rounded cast.
     */
    private function toKobo(mixed $naira): int
    {
        $value = trim((string) $naira);

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

            return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function categories(int $businessId): array
    {
        return ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ExpenseCategory $category) => ['id' => $category->id, 'name' => $category->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'description' => $expense->description,
            'reference' => $expense->reference,
            'category' => $expense->category?->name,
            // Legacy fell back to the ledger account when no category was set.
            'category_label' => $expense->category?->name ?? $expense->ledgerAccount?->name,
            'supplier' => $expense->supplier?->name,
            'ledger_account' => $expense->ledgerAccount ? [
                'id' => $expense->ledgerAccount->id,
                'code' => $expense->ledgerAccount->code,
                'name' => $expense->ledgerAccount->name,
            ] : null,
            'amount_kobo' => (int) $expense->amount_kobo,
            'tax_kobo' => (int) $expense->tax_kobo,
            'total_kobo' => (int) $expense->total_kobo,
            'payment_method' => $expense->payment_method,
            'payment_method_label' => $expense->payment_method
                ? (self::PAYMENT_METHODS[$expense->payment_method] ?? ucfirst(str_replace('_', ' ', $expense->payment_method)))
                : null,
            'status' => $expense->status,
            'journal_entry_id' => $expense->journal_entry_id ? (int) $expense->journal_entry_id : null,
            'has_receipt' => (bool) $expense->receipt_path,
            'receipt_url' => $expense->receipt_path
                ? asset('storage/'.$expense->receipt_path)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Expense $expense): array
    {
        $expense->loadMissing(['category:id,name', 'supplier:id,name', 'ledgerAccount:id,code,name', 'paymentAccount:id,code,name', 'journalEntry.lines.account']);

        $entry = $expense->journalEntry;

        // Legacy could show the reversal banner on a reversal entry but had
        // no link the other way; surfacing it here answers "why is this
        // expense's entry void?" from the expense itself.
        $reversal = $entry
            ? JournalEntry::query()
                ->where('reversal_of_id', $entry->id)
                ->first(['id', 'entry_number', 'entry_date', 'status'])
            : null;

        return [
            ...$this->summary($expense),
            'payment_account' => $expense->paymentAccount ? [
                'id' => $expense->paymentAccount->id,
                'code' => $expense->paymentAccount->code,
                'name' => $expense->paymentAccount->name,
            ] : null,
            'receipt' => $expense->receipt_path ? [
                'url' => asset('storage/'.$expense->receipt_path),
                'is_pdf' => str_ends_with(strtolower($expense->receipt_path), '.pdf'),
            ] : null,
            'created_by' => $expense->created_by ? (int) $expense->created_by : null,
            'created_at' => $expense->created_at?->toISOString(),
            'journal_entry' => $entry ? [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'status' => $entry->status,
                'memo' => $entry->memo,
                'lines' => $entry->lines->map(fn (JournalLine $line) => [
                    'id' => $line->id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'description' => $line->description,
                    'debit_kobo' => (int) $line->debit_kobo,
                    'credit_kobo' => (int) $line->credit_kobo,
                ])->all(),
            ] : null,
            'reversal_entry' => $reversal ? [
                'id' => $reversal->id,
                'entry_number' => $reversal->entry_number,
                'entry_date' => $reversal->entry_date?->toDateString(),
                'status' => $reversal->status,
            ] : null,
        ];
    }

    private function authorizeExpense(Request $request, Expense $expense): void
    {
        if ((int) $expense->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this expense.');
        }
    }
}
