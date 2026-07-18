<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

class Shop4meOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'store'])
            ->withCount('items')
            ->where('source', 'shop4me');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $status = $request->payment_status;
            if ($status === 'unpaid') {
                $query->whereDoesntHave('transactions');
            } elseif ($status === 'paid') {
                $query->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CONFIRMED->value));
            } elseif ($status === 'refunded') {
                $query->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::REFUNDED->value));
            } elseif ($status === 'failed') {
                $query->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CANCELED->value));
            }
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $orders = (clone $query)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $stores = Store::active()->get();

        $statsBase = Order::query()->where('source', 'shop4me');

        $stats = [
            'total' => (clone $statsBase)->count(),
            'pending' => (clone $statsBase)->where('status', 'pending')->count(),
            'accepted' => (clone $statsBase)->where('status', 'accepted')->count(),
            'processing' => (clone $statsBase)->where('status', 'processing')->count(),
            'dispatched' => (clone $statsBase)->where('status', 'dispatched')->count(),
            'delivered' => (clone $statsBase)->where('status', 'delivered')->count(),
            'completed' => (clone $statsBase)->where('status', 'completed')->count(),
            'cancelled' => (clone $statsBase)->where('status', 'cancelled')->count(),
            'paid' => (clone $statsBase)->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CONFIRMED->value))->count(),
            'total_revenue' => (clone $statsBase)->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CONFIRMED->value))->sum('total'),
        ];

        return view('admin.order_management.shop4me_orders', compact('orders', 'stores', 'stats'));
    }
}
