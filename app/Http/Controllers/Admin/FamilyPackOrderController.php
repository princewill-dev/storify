<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

class FamilyPackOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'store'])
            ->withCount('items')
            ->where('source', 'family_pack');

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

        $statsBase = Order::query()->where('source', 'family_pack');

        $stats = [
            'total' => (clone $statsBase)->count(),
            'pending' => (clone $statsBase)->where('status', 'pending')->count(),
            'processing' => (clone $statsBase)->where('status', 'processing')->count(),
            'total_revenue' => (clone $statsBase)->where('payment_status', 'paid')->sum('total'),
        ];

        return view('admin.order_management.family_pack', compact('orders', 'stores', 'stats'));
    }
}
