<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\OrderDelivery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DispatchesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = OrderDelivery::with(['order.store', 'order.customer', 'deliveryRoute', 'createdBy']);
        $this->forBusiness($query, $user);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->whereHas('order', fn($q) => $q->where('store_id', $request->store_id));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('driver_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn($q) => $q->where('order_number', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $dispatches = $query->latest()->paginate(20)->withQueryString();

        $stats = [
            'total' => OrderDelivery::where('business_id', $user->business_id)->count(),
            'pending' => OrderDelivery::where('business_id', $user->business_id)->whereIn('status', ['pending', 'assigned'])->count(),
            'in_transit' => OrderDelivery::where('business_id', $user->business_id)->whereIn('status', ['picked_up', 'in_transit', 'out_for_delivery'])->count(),
            'delivered_today' => OrderDelivery::where('business_id', $user->business_id)->where('status', 'delivered')->whereDate('actual_delivery_at', today())->count(),
            'failed' => OrderDelivery::where('business_id', $user->business_id)->whereIn('status', ['failed', 'returned'])->count(),
        ];

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $activeFilters = $request->only(['status', 'store_id', 'search', 'date_from', 'date_to']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Dispatches'],
        ];

        return view('management.dispatches.index', compact('dispatches', 'stats', 'stores', 'activeFilters', 'breadcrumbs'));
    }
}
