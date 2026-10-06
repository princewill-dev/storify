<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Accounting\InventoryCostingService;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-22 — Accounting: Bills (payables).
 *
 * Legacy capability rebuilt end to end: the list with Outstanding / Paid /
 * Overdue stats and q + supplier + status filters (the legacy Blade never
 * rendered date inputs — see mgmt-accounting.verify.md — so none are exposed
 * here either), the line-item form with weighted-average inventory costing
 * and AP journal posting, the detail screen, record-payment with the
 * open → partial → paid transition, and void with reversal.
 *
 * `GET accounting/bills` re-registers the URI the shared AccountingController
 * already serves; feature modules load after the shared route file, so this
 * registration wins and the SPA keeps calling the same endpoint. Every other
 * route is new.
 *
 * Improvements on legacy, called out where they live in the code: duplicate
 * bill numbers are a clean 422 instead of a database error, 3-dp quantities
 * are rounded (not truncated) when they drive whole-unit cost averages, and
 * payments are written under a row lock so two concurrent payments cannot
 * together exceed the remaining balance.
 */
class BillController extends ApiController
{
    use ResolvesManagementContext;

    /** @var array<string, string> */
    private const PAYMENT_METHODS = [
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'card' => 'Card',
        'other' => 'Other',
    ];

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                Bill::STATUS_DRAFT,
                Bill::STATUS_OPEN,
                Bill::STATUS_PARTIAL,
                Bill::STATUS_PAID,
                Bill::STATUS_VOID,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = Bill::query()
            ->where('business_id', $businessId)
            ->with('supplier:id,name,email,phone')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('bill_number', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $term)));
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok([
            // `data` keeps the flat row array the shared endpoint already
            // returned so existing consumers keep working; the legacy stats
            // block and the supplier filter options ride alongside it (same
            // envelope the WS-16 expenses list established).
            'data' => $bills->getCollection()->map(fn (Bill $bill) => $this->summary($bill))->all(),
            'stats' => $this->stats($businessId),
            'suppliers' => $this->supplierOptions($businessId),
            'meta' => $this->paginationMeta($bills),
        ]);
    }

    /**
     * Everything the New Bill form needs in one round trip: active suppliers,
     * expense accounts, cash/bank accounts and — unlike the legacy Blade, which
     * kept the product picker backend-only — the product list that drives
     * weighted-average costing.
     */
    public function options(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        return $this->ok([
            'suppliers' => $this->supplierOptions($businessId, activeOnly: true),
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
            'payment_accounts' => $this->paymentAccounts($businessId),
            'products' => Product::query()
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'product_code', 'average_cost_kobo'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'average_cost_kobo' => (int) $product->average_cost_kobo,
                ])->all(),
            'payment_methods' => collect(self::PAYMENT_METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, LedgerPostingService $posting): JsonResponse
    {
        $user = $this->user($request);
        $validated = $this->validated($request, $user->business_id);

        [$items, $subtotalKobo] = $this->lineItems($validated['items']);

        $taxKobo = $this->toKobo($validated['tax'] ?? 0);
        $totalKobo = $subtotalKobo + $taxKobo;

        $billNumber = trim((string) ($validated['bill_number'] ?? '')) ?: 'BILL-'.strtoupper(Str::random(8));

        $costing = app(InventoryCostingService::class);

        $bill = DB::transaction(function () use ($user, $validated, $items, $subtotalKobo, $taxKobo, $totalKobo, $billNumber, $costing) {
            $bill = Bill::create([
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

            foreach ($items as $item) {
                $bill->items()->create($item);
            }

            $this->applyInventoryCosting($bill, $items, $costing);

            return $bill;
        });

        $warning = null;

        try {
            $entry = $posting->postBill($bill, $user->id);

            if ($entry) {
                $bill->update(['journal_entry_id' => $entry->id]);
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

        $payload = ['bill' => $this->detail($bill->fresh())];

        if ($warning !== null) {
            $payload['posting_warning'] = $warning;
        }

        return $this->ok($payload, 'Bill recorded.', 201);
    }

    public function show(Request $request, Bill $bill): JsonResponse
    {
        $this->authorizeBill($request, $bill);

        return $this->ok(['bill' => $this->detail($bill)]);
    }

    public function storePayment(Request $request, Bill $bill, LedgerPostingService $posting): JsonResponse
    {
        $this->authorizeBill($request, $bill);

        if (in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true)) {
            return $this->error('This bill cannot accept payments.');
        }

        $remaining = $bill->remainingBalanceKobo();

        if ($remaining <= 0) {
            return $this->error('This bill has no outstanding balance.');
        }

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            // Bound expressed as an exact decimal string built from kobo —
            // no float value ever represents money here.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$this->koboToDecimal($remaining)],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')
                ->where('business_id', $bill->business_id)
                ->where('is_active', true)],
            'method' => ['required', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $amountKobo = $this->toKobo($validated['amount']);

        $payment = DB::transaction(function () use ($request, $validated, $bill, $amountKobo) {
            // Re-read under a row lock: two concurrent payments must not both
            // pass the "no more than the remaining balance" check (the legacy
            // controller validated against a stale in-memory copy).
            $locked = Bill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($amountKobo <= 0 || (int) $locked->amount_paid_kobo + $amountKobo > (int) $locked->total_kobo) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment is more than the remaining balance.',
                ]);
            }

            $payment = BillPayment::create([
                'business_id' => $locked->business_id,
                'bill_id' => $locked->id,
                'supplier_id' => $locked->supplier_id,
                'payment_date' => $validated['payment_date'],
                'amount_kobo' => $amountKobo,
                'payment_account_id' => $validated['payment_account_id'] ?? null,
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $locked->amount_paid_kobo = (int) $locked->amount_paid_kobo + $amountKobo;
            $locked->status = $locked->isFullyPaid() ? Bill::STATUS_PAID : Bill::STATUS_PARTIAL;
            $locked->save();

            return $payment;
        });

        $warning = null;

        try {
            $entry = $posting->postBillPayment($payment, $request->user()->id);

            if ($entry) {
                $payment->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            // Payment kept, ledger warned — legacy did the same so a transient
            // posting failure could never lose a recorded payment.
            $warning = 'Payment saved, but ledger posting failed: '.$e->getMessage();

            Log::warning('api.management.bill_payment_posting_failed', [
                'user_id' => $request->user()->id,
                'bill_id' => $bill->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('api.management.bill_payment_recorded', [
            'user_id' => $request->user()->id,
            'bill_id' => $bill->id,
            'payment_id' => $payment->id,
            'amount_kobo' => $amountKobo,
            'status' => $bill->fresh()->status,
        ]);

        $payload = ['bill' => $this->detail($bill->fresh())];

        if ($warning !== null) {
            $payload['posting_warning'] = $warning;
        }

        return $this->ok($payload, 'Payment recorded.', 201);
    }

    public function void(Request $request, Bill $bill, LedgerPostingService $posting): JsonResponse
    {
        $this->authorizeBill($request, $bill);

        if ($bill->status === Bill::STATUS_VOID) {
            return $this->error('This bill is already void.');
        }

        if ($bill->payments()->exists()) {
            return $this->error('Bills with payments cannot be voided.');
        }

        $entry = $bill->journal_entry_id
            ? JournalEntry::query()
                ->where('business_id', $bill->business_id)
                ->whereKey($bill->journal_entry_id)
                ->first()
            : null;

        try {
            // Reversal and status flip are one transition: a void row without
            // its contra entry (or vice versa) would misstate the books.
            DB::transaction(function () use ($request, $bill, $posting, $entry) {
                if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                    $posting->reverseEntry($entry, 'Void bill '.$bill->bill_number, $this->user($request)->id);
                }

                $bill->update(['status' => Bill::STATUS_VOID]);
            });
        } catch (\Throwable $e) {
            // The reversing entry could not be written, so the bill is
            // deliberately left un-voided (legacy did the same).
            return $this->error('Bill could not be voided: '.$e->getMessage());
        }

        Log::info('api.management.bill_voided', [
            'user_id' => $this->user($request)->id,
            'bill_id' => $bill->id,
            'journal_entry_id' => $entry?->id,
        ]);

        return $this->ok(['bill' => $this->detail($bill->fresh())], 'Bill voided.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $businessId): array
    {
        return $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            // Improvement on legacy: a duplicate bill number used to reach the
            // unique index and surface as a 500; it is a field error now.
            'bill_number' => ['nullable', 'string', 'max:40',
                Rule::unique('bills', 'bill_number')->where('business_id', $businessId)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            // Deactivated accounts vanish from every picker but a crafted
            // request could still post to one (legacy only checked existence).
            'items.*.expense_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')
                ->where('business_id', $businessId)
                ->where('is_active', true)],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $businessId)],
        ]);
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
            $unitCostKobo = $this->toKobo($item['unit_cost']);
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
    private function applyInventoryCosting(Bill $bill, array $items, InventoryCostingService $costing): void
    {
        foreach ($items as $item) {
            if (empty($item['product_id']) || (int) $item['unit_cost_kobo'] <= 0) {
                continue;
            }

            // Re-fetch scoped to the bill's business: the product was validated
            // earlier, but never trust an id off the request without it.
            $product = Product::query()
                ->where('business_id', $bill->business_id)
                ->whereKey($item['product_id'])
                ->first();

            if ($product) {
                $costing->recordReceipt($product, (int) round((float) $item['quantity']), (int) $item['unit_cost_kobo']);
            }
        }
    }

    /**
     * @return array{open_kobo: int, paid_kobo: int, overdue: int}
     */
    private function stats(int $businessId): array
    {
        return [
            // Outstanding, exactly as the legacy cards defined it: unpaid
            // balances across open + partial bills (overpayments would net
            // out here rather than be floored per bill).
            'open_kobo' => (int) Bill::query()
                ->where('business_id', $businessId)
                ->whereIn('status', [Bill::STATUS_OPEN, Bill::STATUS_PARTIAL])
                ->sum(DB::raw('total_kobo - amount_paid_kobo')),
            'paid_kobo' => (int) Bill::query()
                ->where('business_id', $businessId)
                ->where('status', Bill::STATUS_PAID)
                ->sum('total_kobo'),
            'overdue' => Bill::query()
                ->where('business_id', $businessId)
                ->whereIn('status', [Bill::STATUS_OPEN, Bill::STATUS_PARTIAL])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function supplierOptions(int $businessId, bool $activeOnly = false): array
    {
        return Supplier::query()
            ->where('business_id', $businessId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
            ->all();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function paymentAccounts(int $businessId): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Bill $bill): array
    {
        $balanceKobo = $bill->remainingBalanceKobo();

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'supplier_id' => $bill->supplier_id ? (int) $bill->supplier_id : null,
            'supplier' => $bill->supplier?->name,
            'issue_date' => $bill->issue_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'subtotal_kobo' => (int) $bill->subtotal_kobo,
            'tax_kobo' => (int) $bill->tax_kobo,
            'total_kobo' => (int) $bill->total_kobo,
            'paid_kobo' => (int) $bill->amount_paid_kobo,
            'balance_kobo' => $balanceKobo,
            // The legacy Blade computed this inline to paint the due date red;
            // doing it server-side keeps the SPA from re-deriving the rule.
            'is_overdue' => $bill->due_date !== null
                && $balanceKobo > 0
                && ! in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true)
                && $bill->due_date->lt(today()),
            'status' => $bill->status,
            'items_count' => $bill->items_count !== null
                ? (int) $bill->items_count
                : ($bill->relationLoaded('items') ? $bill->items->count() : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Bill $bill): array
    {
        $bill->loadMissing([
            'supplier:id,name,email,phone,address',
            'items.expenseAccount:id,code,name',
            'items.product:id,name,product_code',
            'payments.paymentAccount:id,code,name',
            'journalEntry.lines.account:id,code,name',
        ]);

        $entry = $bill->journalEntry;

        // The legal trail after a void: which entry reversed this bill's
        // original posting (legacy had no link in this direction).
        $reversal = $entry
            ? JournalEntry::query()
                ->where('business_id', $bill->business_id)
                ->where('reversal_of_id', $entry->id)
                ->first(['id', 'entry_number', 'entry_date', 'status'])
            : null;

        return [
            ...$this->summary($bill),
            'notes' => $bill->notes,
            'supplier_detail' => $bill->supplier ? [
                'id' => $bill->supplier->id,
                'name' => $bill->supplier->name,
                'email' => $bill->supplier->email,
                'phone' => $bill->supplier->phone,
                'address' => $bill->supplier->address,
            ] : null,
            'items' => $bill->items->map(fn (BillItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_cost_kobo' => (int) $item->unit_cost_kobo,
                'amount_kobo' => (int) $item->amount_kobo,
                'expense_account' => $item->expenseAccount ? [
                    'id' => $item->expenseAccount->id,
                    'code' => $item->expenseAccount->code,
                    'name' => $item->expenseAccount->name,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'product_code' => $item->product->product_code,
                ] : null,
            ])->values()->all(),
            'payments' => $bill->payments->map(fn (BillPayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_kobo' => (int) $payment->amount_kobo,
                'method' => $payment->method,
                'method_label' => $payment->method
                    ? (self::PAYMENT_METHODS[$payment->method] ?? ucfirst(str_replace('_', ' ', $payment->method)))
                    : null,
                'reference' => $payment->reference,
                'payment_account' => $payment->paymentAccount ? [
                    'id' => $payment->paymentAccount->id,
                    'code' => $payment->paymentAccount->code,
                    'name' => $payment->paymentAccount->name,
                ] : null,
                'journal_entry_id' => $payment->journal_entry_id ? (int) $payment->journal_entry_id : null,
            ])->values()->all(),
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
            // The legacy detail screen passed the payment pickers to its
            // Record Payment modal; the SPA gets them here in the same trip.
            'payment_accounts' => $this->paymentAccounts($bill->business_id),
            'created_by' => $bill->created_by ? (int) $bill->created_by : null,
            'created_at' => $bill->created_at?->toISOString(),
        ];
    }

    /**
     * Naira as typed by the user → integer kobo, without doing money maths in
     * floats. Plain decimals take the exact string path; anything exotic
     * (scientific notation) falls back to a rounded cast.
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

    /** Integer kobo → exact decimal naira string ("12345" → "123.45"). */
    private function koboToDecimal(int $kobo): string
    {
        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Integer milli-units → exact decimal quantity string ("1005" → "1.005"). */
    private function milliToDecimal(int $milli): string
    {
        return intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function authorizeBill(Request $request, Bill $bill): void
    {
        if ((int) $bill->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this bill.');
        }
    }
}
