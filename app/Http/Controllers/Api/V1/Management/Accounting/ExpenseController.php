<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\IndexExpenseRequest;
use App\Http\Requests\Management\Accounting\StoreExpenseRequest;
use App\Http\Resources\Management\Accounting\ExpenseDetailResource;
use App\Http\Resources\Management\Accounting\ExpenseSummaryResource;
use App\Models\Expense;
use App\Repositories\Accounting\ExpenseRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
 *
 * Layering: HTTP shape (statuses, messages, envelope, pagination meta) stays
 * here; field validation lives in App\Http\Requests\Management\Accounting,
 * queries/aggregates/pickers in App\Repositories\Accounting\ExpenseRepository,
 * the record/void/delete workflows and receipt files in
 * App\Services\Accounting\ExpenseService, and the payloads in
 * App\Http\Resources\Management\Accounting.
 */
class ExpenseController extends ApiController
{
    use ResolvesManagementContext;

    public function index(IndexExpenseRequest $request, ExpenseRepository $repository): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $expenses = $repository->paginateForBusiness($businessId, $request->validated());

        return $this->ok(
            // `data` stays the flat row array the shared endpoint already
            // returned, so existing consumers of the endpoint keep working;
            // the stats block and filter categories ride in `meta` beside the
            // pagination keys, the same shape WS-23's re-registered journal
            // endpoint uses.
            ExpenseSummaryResource::collection($expenses->getCollection())->resolve(),
            null,
            200,
            $this->paginationMeta($expenses) + [
                'stats' => $repository->statsForBusiness($businessId) + [
                    // Only the Records card respects the list filters; the
                    // month/year cards ignore them (see the repository).
                    'records' => $expenses->total(),
                ],
                'categories' => $repository->categoryOptions($businessId),
            ],
        );
    }

    public function store(StoreExpenseRequest $request, ExpenseService $service, ExpenseRepository $repository): JsonResponse
    {
        $result = $service->recordExpense(
            $this->user($request),
            $request->validated(),
            $request->file('receipt'),
        );

        $payload = ['expense' => $this->detailPayload($result['expense']->fresh(), $repository)];

        if ($result['warning'] !== null) {
            $payload['posting_warning'] = $result['warning'];
        }

        return $this->ok($payload, 'Expense recorded.', 201);
    }

    public function show(Request $request, Expense $expense, ExpenseRepository $repository): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        return $this->ok(['expense' => $this->detailPayload($expense, $repository)]);
    }

    public function void(Request $request, Expense $expense, ExpenseService $service, ExpenseRepository $repository): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->status === Expense::STATUS_VOID) {
            return $this->error('This expense is already void.');
        }

        // Read the original entry before the guarded transition, at the same
        // point in the sequence the controller read it before the refactor: a
        // failure reading it is not a failed void and must not be reported as
        // one (and the log after the guard must not be mistaken for one).
        $entry = $repository->journalEntryFor($expense);

        try {
            $service->voidExpense($this->user($request), $expense, $entry);
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

        return $this->ok(['expense' => $this->detailPayload($expense->fresh(), $repository)], 'Expense voided.');
    }

    public function destroy(Request $request, Expense $expense, ExpenseService $service): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->journal_entry_id) {
            return $this->error('Posted expenses cannot be deleted. Void it instead.');
        }

        $service->deleteExpense($expense);

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
    public function options(Request $request, ExpenseRepository $repository): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        return $this->ok([
            'categories' => $repository->categoryOptions($businessId),
            'expense_accounts' => $repository->expenseAccountOptions($businessId),
            'payment_accounts' => $repository->paymentAccountOptions($businessId),
            'suppliers' => $repository->supplierOptions($businessId),
            'payment_methods' => collect(StoreExpenseRequest::PAYMENT_METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Load and shape the detail payload. The journal lookups come from the
     * repository; the resource only renders them.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(Expense $expense, ExpenseRepository $repository): array
    {
        $repository->loadForDetail($expense);

        $entry = $expense->journalEntry;

        // The legal trail after a void: which entry reversed this expense's
        // original posting (legacy had no link in this direction).
        $reversal = $entry
            ? $repository->reversalEntryFor($expense, $entry)
            : null;

        return (new ExpenseDetailResource($expense, $reversal))->resolve();
    }

    private function authorizeExpense(Request $request, Expense $expense): void
    {
        app(TenantGuard::class)->authorizeBusiness($expense, $this->user($request), 'You do not have access to this expense.');
    }
}
