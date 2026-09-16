<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $suppliers = Supplier::query()
            ->where('business_id', $businessId)
            ->withCount('bills')
            ->withSum(['bills as bills_total_kobo' => fn ($q) => $q->where('status', '!=', 'void')], 'total_kobo')
            ->withSum(['bills as bills_paid_kobo' => fn ($q) => $q->where('status', '!=', 'void')], 'amount_paid_kobo')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('management.accounting.suppliers.index', compact('suppliers'));
    }

    public function show(Request $request, Supplier $supplier): View
    {
        $this->authorizeSupplier($request, $supplier);

        $supplier->load(['bills' => fn ($q) => $q->orderByDesc('issue_date'), 'billPayments' => fn ($q) => $q->orderByDesc('payment_date')]);

        return view('management.accounting.suppliers.show', compact('supplier'));
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        $validated = $this->validateSupplier($request, $businessId);

        Supplier::create([
            'business_id' => $businessId,
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $validated = $this->validateSupplier($request, $supplier->business_id);

        $supplier->update($validated);

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeSupplier($request, $supplier);

        if ($supplier->bills()->exists()) {
            return back()->with('error', 'Suppliers with bills cannot be deleted.');
        }

        $supplier->delete();

        return redirect()->route('management.accounting.suppliers.index')
            ->with('success', 'Supplier deleted.');
    }

    private function validateSupplier(Request $request, int $businessId): array
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

    private function authorizeSupplier(Request $request, Supplier $supplier): void
    {
        if ($supplier->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
