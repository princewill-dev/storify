<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\IndexBillRequest;
use App\Http\Requests\Management\Accounting\StoreBillPaymentRequest;
use App\Http\Requests\Management\Accounting\StoreBillRequest;
use App\Http\Resources\Management\Accounting\BillDetailResource;
use App\Http\Resources\Management\Accounting\BillSummaryResource;
use App\Models\Bill;
use App\Repositories\Accounting\BillRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
 * bill numbers are a clean 422 instead of a database error (StoreBillRequest),
 * 3-dp quantities are rounded (not truncated) when they drive whole-unit cost
 * averages (BillService), and payments are written under a row lock so two
 * concurrent payments cannot together exceed the remaining balance
 * (BillService + BillRepository::findBillForUpdate()).
 *
 * Layering: HTTP shape (statuses, messages, envelope, pagination meta) stays
 * here; field validation lives in App\Http\Requests\Management\Accounting,
 * queries/locks/persists in App\Repositories\Accounting\BillRepository, the
 * create/pay/void workflows in App\Services\Accounting\BillService, and the
 * payloads in App\Http\Resources\Management\Accounting.
 */
class BillController extends ApiController
{
    use ResolvesManagementContext;

    public function index(IndexBillRequest $request, BillRepository $repository): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $bills = $repository->paginateForBusiness($businessId, $request->validated());

        return $this->ok([
            // `data` keeps the flat row array the shared endpoint already
            // returned so existing consumers keep working; the legacy stats
            // block and the supplier filter options ride alongside it (same
            // envelope the WS-16 expenses list established).
            'data' => BillSummaryResource::collection($bills->getCollection())->resolve(),
            'stats' => $repository->statsForBusiness($businessId),
            'suppliers' => $repository->supplierOptions($businessId),
            'meta' => $this->paginationMeta($bills),
        ]);
    }

    /**
     * Everything the New Bill form needs in one round trip: active suppliers,
     * expense accounts, cash/bank accounts and — unlike the legacy Blade, which
     * kept the product picker backend-only — the product list that drives
     * weighted-average costing.
     */
    public function options(Request $request, BillRepository $repository): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        return $this->ok([
            'suppliers' => $repository->supplierOptions($businessId, activeOnly: true),
            'expense_accounts' => $repository->expenseAccountOptions($businessId),
            'payment_accounts' => $repository->paymentAccountOptions($businessId),
            'products' => $repository->productOptions($businessId),
            // The picker mirrors exactly what StoreBillPaymentRequest accepts.
            'payment_methods' => collect(StoreBillPaymentRequest::PAYMENT_METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreBillRequest $request, BillService $service, BillRepository $repository): JsonResponse
    {
        $result = $service->recordBill($this->user($request), $request->validated());

        $payload = ['bill' => $this->detailPayload($result['bill']->fresh(), $repository)];

        if ($result['warning'] !== null) {
            $payload['posting_warning'] = $result['warning'];
        }

        return $this->ok($payload, 'Bill recorded.', 201);
    }

    public function show(Request $request, Bill $bill, BillRepository $repository): JsonResponse
    {
        $this->authorizeBill($request, $bill);

        return $this->ok(['bill' => $this->detailPayload($bill, $repository)]);
    }

    public function storePayment(StoreBillPaymentRequest $request, Bill $bill, BillService $service, BillRepository $repository): JsonResponse
    {
        // The request has already run the tenant guard (403) and, while the
        // bill is still payable, the field rules; these two state guards keep
        // their pre-refactor place ahead of anything reading the payload.
        if (in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true)) {
            return $this->error('This bill cannot accept payments.');
        }

        if ($bill->remainingBalanceKobo() <= 0) {
            return $this->error('This bill has no outstanding balance.');
        }

        $result = $service->recordPayment($this->user($request), $bill, $request->validated());

        $payload = ['bill' => $this->detailPayload($result['bill']->fresh(), $repository)];

        if ($result['warning'] !== null) {
            $payload['posting_warning'] = $result['warning'];
        }

        return $this->ok($payload, 'Payment recorded.', 201);
    }

    public function void(Request $request, Bill $bill, BillService $service, BillRepository $repository): JsonResponse
    {
        $this->authorizeBill($request, $bill);

        if ($bill->status === Bill::STATUS_VOID) {
            return $this->error('This bill is already void.');
        }

        if ($repository->hasPayments($bill)) {
            return $this->error('Bills with payments cannot be voided.');
        }

        // Read the original entry before the guarded transition, at the same
        // point in the sequence the controller read it before the refactor: a
        // failure reading it is not a failed void and must not be reported as
        // one (and the log after the guard must not be mistaken for one).
        $entry = $repository->journalEntryFor($bill);

        try {
            $service->voidBill($this->user($request), $bill, $entry);
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

        return $this->ok(['bill' => $this->detailPayload($bill->fresh(), $repository)], 'Bill voided.');
    }

    /**
     * Load and shape the detail payload. The reversal entry and the payment
     * pickers come from the repository; the resource only renders them.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(Bill $bill, BillRepository $repository): array
    {
        $repository->loadForDetail($bill);

        $entry = $bill->journalEntry;

        // The legal trail after a void: which entry reversed this bill's
        // original posting (legacy had no link in this direction).
        $reversal = $entry
            ? $repository->reversalEntryFor($bill, $entry)
            : null;

        return (new BillDetailResource(
            $bill,
            $reversal,
            $repository->paymentAccountOptions($bill->business_id),
        ))->resolve();
    }

    private function authorizeBill(Request $request, Bill $bill): void
    {
        // The same guard runs from StoreBillPaymentRequest::authorize() for
        // the payment route, which carries validation and must 403 first.
        app(TenantGuard::class)->authorizeBusiness($bill, $this->user($request), 'You do not have access to this bill.');
    }
}
