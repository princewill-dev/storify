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
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $stockLocations = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with('product')
            ->get();

        return view('management.warehouses.send', compact('user', 'warehouse', 'warehouses', 'stockLocations'));
    }

    public function receiveForm(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $warehouses = Warehouse::where('business_id', $warehouse->business_id)
            ->where('id', '!=', $warehouse->id)
            ->where('is_active', true)
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
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $toWarehouse = Warehouse::where('id', $validated['to_warehouse_id'])
            ->where('business_id', $warehouse->business_id)
            ->where('id', '!=', $warehouse->id)
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'business_id' => $warehouse->business_id,
                'from_location_type' => Warehouse::class,
                'from_location_id' => $warehouse->id,
                'to_location_type' => Warehouse::class,
                'to_location_id' => $toWarehouse->id,
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
                'to_warehouse' => $toWarehouse->warehouse_code,
                'initiated_by' => $user->id,
            ]);

            return redirect()->route('management.transfers.show', $transfer)
                ->with('success', 'Transfer initiated from ' . $warehouse->name . ' to ' . $toWarehouse->name . '.');

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

            return redirect()->route('management.transfers.show', $transfer)
                ->with('success', 'Transfer initiated from ' . $fromWarehouse->name . ' to ' . $warehouse->name . '.');

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

        $locations = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with('product')
            ->get()
            ->map(fn($loc) => [
                'product_id' => $loc->product_id,
                'name' => $loc->product?->name ?? 'Unknown',
                'available' => (int) $loc->quantity,
            ]);

        return response()->json(['products' => $locations]);
    }
}
