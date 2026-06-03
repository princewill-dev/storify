<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Enums\TransferStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WarehouseTransferController extends Controller
{
    public function sendForm(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $warehouses = Warehouse::where('business_id', $warehouse->business_id)
            ->where('id', '!=', $warehouse->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get();

        $stores = ($user->isStaff() ? $user->assignedStores() : $user->stores())
            ->where('status', '!=', 'deleted')
            ->orderBy('name')->get();

        $stockLocations = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with('product.images')
            ->get();

        $existingProductIds = $stockLocations->pluck('product_id')->toArray();

        $products = Product::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with(['images', 'section'])
            ->get();

        foreach ($products as $product) {
            if (!in_array($product->id, $existingProductIds, true)) {
                $stockLocations->push($product);
            }
        }

        return view('management.warehouses.send', compact(
            'user', 'warehouse', 'warehouses', 'stores', 'stockLocations', 'products'
        ));
    }

    public function receiveForm(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $warehouses = Warehouse::where('business_id', $warehouse->business_id)
            ->where('id', '!=', $warehouse->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get();

        return view('management.warehouses.receive', compact('user', 'warehouse', 'warehouses'));
    }

    public function initSend(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $validated = $request->validate([
            'to_location_type' => 'required|in:store,warehouse',
            'to_location_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $toLocationType = $request->input('to_location_type') === 'store'
            ? \App\Models\Store::class
            : Warehouse::class;

        if ($toLocationType === \App\Models\Store::class) {
            $toLocation = ($user->isStaff() ? $user->assignedStores() : $user->stores())
                ->where('status', '!=', 'deleted')
                ->findOrFail($validated['to_location_id']);
        } else {
            $toLocation = Warehouse::where('id', $validated['to_location_id'])
                ->where('business_id', $warehouse->business_id)
                ->where('id', '!=', $warehouse->id)
                ->where('status', '!=', 'deleted')
                ->firstOrFail();
        }

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'business_id' => $warehouse->business_id,
                'from_location_type' => Warehouse::class,
                'from_location_id' => $warehouse->id,
                'to_location_type' => $toLocationType,
                'to_location_id' => $toLocation->id,
                'requested_by' => $user->id,
                'status' => TransferStatus::PENDING,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            Log::info('warehouse_transfer.initiated', [
                'transfer_id' => $transfer->id,
                'from_warehouse' => $warehouse->warehouse_code,
                'to_type' => $toLocationType,
                'to_id' => $toLocation->id,
                'initiated_by' => $user->id,
            ]);

            return redirect()->route('management.transfers.show', $transfer)
                ->with('success', 'Transfer initiated from ' . $warehouse->name . ' to ' . $toLocation->name . '.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('warehouse_transfer.failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to create transfer.')->withInput();
        }
    }

    public function initReceive(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $fromWarehouse = Warehouse::where('id', $validated['from_warehouse_id'])
            ->where('business_id', $warehouse->business_id)
            ->where('id', '!=', $warehouse->id)
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'business_id' => $warehouse->business_id,
                'from_location_type' => Warehouse::class,
                'from_location_id' => $fromWarehouse->id,
                'to_location_type' => Warehouse::class,
                'to_location_id' => $warehouse->id,
                'requested_by' => $user->id,
                'status' => TransferStatus::PENDING,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            Log::info('warehouse_transfer.receive_initiated', [
                'transfer_id' => $transfer->id,
                'from_warehouse' => $fromWarehouse->warehouse_code,
                'to_warehouse' => $warehouse->warehouse_code,
                'initiated_by' => $user->id,
            ]);

            return redirect()->route('management.warehouses.receive', $warehouse)
                ->with('success', 'Transfer request sent from ' . $fromWarehouse->name . ' to ' . $warehouse->name . '.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('warehouse_transfer.failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to create transfer.')->withInput();
        }
    }

    public function productsJson(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $result = collect();

        $locations = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with('product')
            ->get();

        $existingIds = [];
        foreach ($locations as $loc) {
            $result->push([
                'product_id' => $loc->product_id,
                'product_code' => $loc->product?->product_code ?? '',
                'name' => $loc->product?->name ?? 'Unknown',
                'available' => (int) $loc->quantity,
            ]);
            $existingIds[] = $loc->product_id;
        }

        $products = Product::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->whereNotIn('id', $existingIds)
            ->get();

        foreach ($products as $p) {
            $result->push([
                'product_id' => $p->id,
                'product_code' => $p->product_code,
                'name' => $p->name,
                'available' => (int) $p->quantity,
            ]);
        }

        return response()->json(['products' => $result->values()]);
    }
}
