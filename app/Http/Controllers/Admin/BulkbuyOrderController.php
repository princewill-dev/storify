<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

class BulkbuyOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'store', 'bulkOrder'])
            ->withCount('items')
            ->where('source', 'bulk');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
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

        $statsBase = Order::query()->where('source', 'bulk');

        $stats = [
            'total' => (clone $statsBase)->count(),
            'pending' => (clone $statsBase)->where('status', 'pending')->count(),
            'processing' => (clone $statsBase)->where('status', 'processing')->count(),
            'total_revenue' => (clone $statsBase)->where('payment_status', 'paid')->sum('total'),
        ];

        return view('admin.order_management.bulkbuy_orders', compact('orders', 'stores', 'stats'));
    }
}
