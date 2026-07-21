<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTransactionStatusRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $query = Transaction::with(['order.customer', 'invoice.store', 'paymentMethod']);

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->reference . '%');
        }

        if ($request->filled('status') && in_array($request->status, TransactionStatus::values(), true)) {
            $query->where('status', $request->status);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }

    public function show(Transaction $transaction): View
    {
        $transaction->load(['order.customer', 'order.store', 'invoice.store', 'paymentMethod']);

        return view('admin.transactions.show', [
            'transaction' => $transaction,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }

    public function updateStatus(UpdateTransactionStatusRequest $request, Transaction $transaction): RedirectResponse
    {
        $transaction->update(['status' => $request->status]);

        return redirect()
            ->route('admin.transactions.show', $transaction)
            ->with('success', 'Transaction status updated.');
    }
}
