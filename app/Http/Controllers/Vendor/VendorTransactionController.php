<?php

namespace App\Http\Controllers\Vendor;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorTransactionController extends Controller
{
    private function resolveVendor(Request $request, Vendor $routeVendor): ?Vendor
    {
        $vendor = $request->user('vendor');
        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return null;
        }

        return $vendor;
    }

    public function index(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $query = Transaction::with(['order.customer', 'paymentMethod'])
            ->whereHas('order', fn ($q) => $q->where('vendor_id', $vendor->id));

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->reference . '%');
        }

        if ($request->filled('status') && in_array($request->status, TransactionStatus::values(), true)) {
            $query->where('status', $request->status);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return view('vendors.transactions.index', [
            'vendor' => $vendor,
            'transactions' => $transactions,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }

    public function show(Request $request, Vendor $routeVendor, Transaction $transaction): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$transaction->order || $transaction->order->vendor_id !== $vendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        $transaction->load(['order.customer', 'order.store', 'paymentMethod']);

        return view('vendors.transactions.show', [
            'vendor' => $vendor,
            'transaction' => $transaction,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }
}
