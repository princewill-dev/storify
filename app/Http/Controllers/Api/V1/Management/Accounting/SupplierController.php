<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-22 — Accounting: Suppliers.
 *
 * The legacy module rebuilt on the API: paginated search with the Outstanding
 * column, create/edit/delete with the "suppliers with bills cannot be
 * deleted" guard, and a detail screen carrying the contact card plus the
 * supplier's bills and payment history.
 *
 * `GET accounting/suppliers` re-registers the URI the shared AccountingController
 * already serves; feature modules load after the shared route file, so this
 * registration wins and the legacy search/outstanding payload lands on the
 * endpoint the SPA already calls. Every other route is new.
 */
class SupplierController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $suppliers = Supplier::query()
            ->where('business_id', $this->user($request)->business_id)
            // Legacy definition of Outstanding: non-void bill totals minus
            // what has already been paid on them, floored at zero.
            ->withCount('bills')
            ->withSum(['bills as bills_total_kobo' => fn ($q) => $q->where('status', '!=', Bill::STATUS_VOID)], 'total_kobo')
            ->withSum(['bills as bills_paid_kobo' => fn ($q) => $q->where('status', '!=', Bill::STATUS_VOID)], 'amount_paid_kobo')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            ['suppliers' => $suppliers->getCollection()->map(fn (Supplier $supplier) => $this->summary($supplier))->all()],
            null,
            200,
            $this->paginationMeta($suppliers),
        );
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $supplier->load([
            'bills' => fn ($q) => $q->orderByDesc('issue_date')->orderByDesc('id'),
            'billPayments' => fn ($q) => $q->orderByDesc('payment_date')->orderByDesc('id'),
        ]);

        $bills = $supplier->bills->where('status', '!=', Bill::STATUS_VOID);

        return $this->ok(['supplier' => [
            ...$this->summary($supplier),
            'bills' => $supplier->bills->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'issue_date' => $bill->issue_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'total_kobo' => (int) $bill->total_kobo,
                'balance_kobo' => $bill->remainingBalanceKobo(),
                'status' => $bill->status,
            ])->values()->all(),
            'payments' => $supplier->billPayments->map(fn (BillPayment $payment) => [
                'id' => $payment->id,
                'bill_id' => $payment->bill_id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_kobo' => (int) $payment->amount_kobo,
                'method' => $payment->method,
                'method_label' => $this->methodLabel($payment->method),
                'reference' => $payment->reference,
            ])->values()->all(),
            // The detail header needs these without re-summing the tables
            // client-side; void bills are excluded exactly like Outstanding.
            'totals' => [
                'bills_count' => $supplier->bills->count(),
                'total_billed_kobo' => (int) $bills->sum('total_kobo'),
                'total_paid_kobo' => (int) $bills->sum('amount_paid_kobo'),
                'outstanding_kobo' => max(0, (int) $bills->sum('total_kobo') - (int) $bills->sum('amount_paid_kobo')),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $this->validated($request);

        $supplier = DB::transaction(fn () => Supplier::create([
            'business_id' => $user->business_id,
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        Log::info('api.management.supplier_created', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
        ]);

        return $this->ok(['supplier' => $this->summary($supplier)], 'Supplier added.', 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $validated = $this->validated($request);

        DB::transaction(fn () => $supplier->update($validated));

        Log::info('api.management.supplier_updated', [
            'user_id' => $this->user($request)->id,
            'supplier_id' => $supplier->id,
        ]);

        return $this->ok(['supplier' => $this->summary($supplier->fresh())], 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        // Guard parity with legacy: bills are the audit trail for money owed,
        // so a supplier that has any (void ones included) cannot be removed.
        if ($supplier->bills()->exists()) {
            return $this->error('Suppliers with bills cannot be deleted.');
        }

        $supplierId = $supplier->id;

        DB::transaction(fn () => $supplier->delete());

        Log::info('api.management.supplier_deleted', [
            'user_id' => $this->user($request)->id,
            'supplier_id' => $supplierId,
        ]);

        return $this->ok([], 'Supplier deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Supplier $supplier): array
    {
        $total = (int) ($supplier->bills_total_kobo ?? 0);
        $paid = (int) ($supplier->bills_paid_kobo ?? 0);

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'notes' => $supplier->notes,
            'is_active' => (bool) $supplier->is_active,
            'bills_count' => (int) ($supplier->bills_count ?? $supplier->bills->count()),
            'bills_total_kobo' => $total,
            'bills_paid_kobo' => $paid,
            'outstanding_kobo' => max(0, $total - $paid),
        ];
    }

    private function methodLabel(?string $method): ?string
    {
        if ($method === null) {
            return null;
        }

        return ucfirst(str_replace('_', ' ', $method));
    }

    private function authorizeSupplier(Request $request, Supplier $supplier): void
    {
        if ((int) $supplier->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this supplier.');
        }
    }
}
