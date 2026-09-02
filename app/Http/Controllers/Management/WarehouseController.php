<?php

namespace App\Http\Controllers\Management;

use App\Data\Nigeria;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('management.auth.login');
        }

        $warehouses = ($user->isStaff() ? $user->assignedWarehouses() : $user->warehouses())
            ->with(['stockLocations.product', 'sections', 'assignedStaff'])->latest()->paginate(20)->withQueryString();
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Warehouses']];

        return view('management.warehouses.index', compact('user', 'warehouses', 'breadcrumbs'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('management.auth.login');
        }

        $nigerianStates = Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'warehouses view'))
            ->get();
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Warehouses', 'url' => route('management.warehouses.index')], ['label' => 'Create']];

        return view('management.warehouses.create', compact('user', 'nigerianStates', 'activeStaff', 'breadcrumbs'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('management.auth.login');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'staff_ids' => 'nullable|array',
        ]);

        $validated['user_id'] = $user->id;
        $validated['business_id'] = $user->business_id;
        $validated['status'] = $request->boolean('is_active') ? 'active' : 'inactive';
        unset($validated['is_active']);
        $warehouse = Warehouse::create($validated);

        if ($request->filled('staff_ids')) {
            $staffIds = array_filter($request->staff_ids);
            if (! empty($staffIds)) {
                $warehouse->assignedStaff()->sync($staffIds);
            }
        }

        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse created.');
    }

    public function show(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $warehouse->load(['stockLocations.product', 'sections', 'assignedStaff']);
        $lowStockCount = $warehouse->stockLocations->filter->isLowStock()->count();

        $productCount = $warehouse->stockLocations->where('quantity', '>', 0)->count();
        $totalStock = $warehouse->stockLocations->sum('quantity');

        // Products shown: those with positive StockLocation quantity, plus synced products
        $stockLocationProductIds = $warehouse->stockLocations
            ->where('quantity', '>', 0)
            ->pluck('product_id')
            ->toArray();

        $products = Product::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with(['section', 'images'])
            ->orderBy('name')
            ->get();

        $recentMovements = StockMovement::whereIn('stock_location_id', $warehouse->stockLocations->pluck('id'))
            ->with(['product', 'performedBy'])
            ->latest()
            ->take(20)
            ->get();

        $stores = ($user->isStaff() ? $user->assignedStores() : $user->stores())
            ->where('status', '!=', 'deleted')
            ->orderBy('name')->get();

        $warehousesList = Warehouse::where('business_id', $warehouse->business_id)
            ->orderBy('name')
            ->get();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Warehouses', 'url' => route('management.warehouses.index')],
            ['label' => $warehouse->name],
        ];

        return view('management.warehouses.show', compact(
            'user', 'warehouse', 'lowStockCount', 'productCount', 'totalStock',
            'products', 'recentMovements', 'stores', 'warehousesList', 'breadcrumbs'
        ));
    }

    public function moveProducts(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $canAutoComplete = $user->can('transfers create') && $user->can('transfers approve')
            && $user->can('transfers dispatch') && $user->can('transfers receive');

        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'destination_type' => 'required|in:store,warehouse',
            'destination_id' => 'required|integer',
            'notes' => 'nullable|string|max:1000',
            'complete_immediately' => 'nullable|boolean',
        ]);

        $destination = $request->input('destination_type') === 'store'
            ? ($user->isStaff() ? $user->assignedStores() : $user->stores())->where('status', '!=', 'deleted')->findOrFail($validated['destination_id'])
            : Warehouse::where('business_id', $warehouse->business_id)->findOrFail($validated['destination_id']);

        $productIds = $validated['product_ids'];
        $products = Product::whereIn('id', $productIds)
            ->where('warehouse_id', $warehouse->id)
            ->get();

        if ($products->isEmpty()) {
            return back()->with('error', 'No valid products selected for this warehouse.');
        }

        $destinationType = $request->input('destination_type') === 'store'
            ? Store::class
            : Warehouse::class;

        $shouldAutoComplete = $canAutoComplete && $request->boolean('complete_immediately');

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'business_id' => $warehouse->business_id,
                'from_location_type' => Warehouse::class,
                'from_location_id' => $warehouse->id,
                'to_location_type' => $destinationType,
                'to_location_id' => $destination->id,
                'requested_by' => $user->id,
                'status' => TransferStatus::PENDING,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($products as $product) {
                $qty = max(1, (int) $product->quantity);
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'approved_quantity' => $shouldAutoComplete ? $qty : null,
                ]);
            }

            if ($shouldAutoComplete) {
                $transfer->update([
                    'status' => TransferStatus::APPROVED,
                    'approved_by' => $user->id,
                ]);

                $ledger = app(StockLedgerService::class);

                foreach ($transfer->items as $item) {
                    $sourceStock = StockLocation::where('product_id', $item->product_id)
                        ->where('locationable_type', Warehouse::class)
                        ->where('locationable_id', $warehouse->id)
                        ->first();

                    if ($sourceStock) {
                        $ledger->recordRemoval($sourceStock, $item->quantity, $transfer, $user,
                            'Transfer dispatched to '.$destination->name);
                    }

                    $destStock = StockLocation::firstOrCreate([
                        'product_id' => $item->product_id,
                        'locationable_type' => $destinationType,
                        'locationable_id' => $destination->id,
                    ], ['quantity' => 0, 'min_quantity' => 0, 'business_id' => $warehouse->business_id]);

                    $ledger->recordAddition($destStock, $item->quantity, $transfer, $user,
                        'Transfer received from '.$warehouse->name);

                    if ($request->input('destination_type') === 'store') {
                        Product::where('id', $item->product_id)
                            ->update(['store_id' => $destination->id]);
                    }
                }

                $transfer->update([
                    'status' => TransferStatus::RECEIVED,
                    'dispatched_by' => $user->id,
                    'received_by' => $user->id,
                ]);

                DB::commit();

                return redirect()->route('management.transfers.show', $transfer)
                    ->with('success', count($products).' product(s) moved to '.$destination->name.'. Transfer completed.');
            }

            DB::commit();

            return redirect()->route('management.transfers.show', $transfer)
                ->with('success', 'Transfer created. Awaiting approval for '.count($products).' product(s) to '.$destination->name.'.');

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('warehouse.move_products_failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Failed to create transfer: '.$e->getMessage());
        }
    }

    public function edit(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $nigerianStates = Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'warehouses view'))
            ->get();
        $warehouse->load('assignedStaff');
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Warehouses', 'url' => route('management.warehouses.index')], ['label' => $warehouse->name, 'url' => route('management.warehouses.show', $warehouse)], ['label' => 'Edit']];

        return view('management.warehouses.edit', compact('user', 'warehouse', 'nigerianStates', 'activeStaff', 'breadcrumbs'));
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'staff_ids' => 'nullable|array',
        ]);

        $validated['status'] = $request->boolean('is_active') ? 'active' : 'inactive';
        unset($validated['is_active']);
        $warehouse->update($validated);

        if ($request->has('staff_ids')) {
            $staffIds = array_filter($request->staff_ids ?? []);
            $warehouse->assignedStaff()->sync($staffIds);
        }

        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $warehouse->update(['status' => 'deleted']);

        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse deleted.');
    }

    /**
     * AJAX endpoint: load tab content for the warehouse detail page.
     */
    public function loadTab(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        if ($user->isStaff()) {
            if (! $user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                abort(403);
            }
        } elseif ($warehouse->user_id !== $user->id) {
            abort(403);
        }

        $tab = $request->route('tab');

        return match ($tab) {
            'settings' => $this->tabSettings($warehouse, $user),
            default => abort(404, 'Unknown tab'),
        };
    }

    private function tabSettings(Warehouse $warehouse, User $user): View
    {
        $nigerianStates = Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'warehouses view'))
            ->get();
        $warehouse->load('assignedStaff');

        return view('management.warehouses.tabs.settings', compact('user', 'warehouse', 'nigerianStates', 'activeStaff'));
    }
}
