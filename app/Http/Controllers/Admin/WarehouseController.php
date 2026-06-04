<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        Log::info('admin_warehouses_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $query = Warehouse::query()
            ->with(['business', 'user'])
            ->withCount(['stockLocations', 'sections']);

        if (in_array(strtolower((string) $status), ['active', 'inactive', 'deleted'], true)) {
            $query->where('status', strtolower($status));
        } else {
            $query->where('status', '!=', 'deleted');
        }

        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('warehouse_code', 'like', "%$q%")
                  ->orWhereHas('business', fn($b) => $b->where('name', 'like', "%$q%"));
            });
        }

        $warehouses = $query->latest()->paginate(15)->withQueryString();

        return view('admin.warehouses.index', compact('warehouses', 'status', 'q'));
    }

    public function show(Warehouse $warehouse): View
    {
        Log::info('admin_warehouse_show_viewed', ['user_id' => auth()->id(), 'warehouse_id' => $warehouse->id]);

        $warehouse->load([
            'business', 'user',
            'sections' => fn($q) => $q->withCount('stockLocations'),
            'stockLocations' => fn($q) => $q->with('product')->take(20),
        ]);
        $warehouse->loadCount(['stockLocations', 'sections']);

        $totalStock = $warehouse->stockLocations->sum('quantity');
        $lowStockCount = $warehouse->stockLocations->filter(fn($l) => $l->quantity <= 10 && $l->quantity > 0)->count();

        // Recent stock movements for this warehouse
        $recentMovements = \App\Models\StockMovement::where(function ($q) use ($warehouse) {
                $q->where(function ($sq) use ($warehouse) {
                    $sq->where('from_location_type', \App\Models\Warehouse::class)
                       ->where('from_location_id', $warehouse->id);
                })->orWhere(function ($sq) use ($warehouse) {
                    $sq->where('to_location_type', \App\Models\Warehouse::class)
                       ->where('to_location_id', $warehouse->id);
                });
            })
            ->with(['product', 'performedBy'])
            ->latest()
            ->take(15)
            ->get();

        return view('admin.warehouses.show', compact('warehouse', 'totalStock', 'lowStockCount', 'recentMovements'));
    }
}
