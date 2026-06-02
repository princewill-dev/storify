<?php

namespace App\Http\Controllers\Management;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status');

        $transfers = StockTransfer::with(['fromLocation', 'toLocation', 'requester', 'items.product'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest()
            ->get();

        $statuses = TransferStatus::cases();

        return view('management.transfers.index', compact('user', 'transfers', 'statuses', 'status'));
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        if ($user->isStaff()) {
            $warehouseIds = \Illuminate\Support\Facades\DB::table('staff_assignments')
                ->where('user_id', $user->id)
                ->where('assignmentable_type', \App\Models\Warehouse::class)
                ->pluck('assignmentable_id');
            $warehouses = \App\Models\Warehouse::whereIn('id', $warehouseIds)->where('is_active', true)->orderBy('name')->get();
        } else {
            $warehouses = \App\Models\Warehouse::where('user_id', $user->id)->where('is_active', true)->orderBy('name')->get();
        }
        $stores = ($user->isStaff() ? $user->assignedStores() : $user->stores())->orderBy('name')->get();

        $products = \App\Models\Product::whereIn('store_id', $stores->pluck('id'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'product_code', 'name', 'store_id']);

        $preSelectedWarehouse = null;
        if ($request->filled('from_warehouse')) {
            $preSelectedWarehouse = $warehouses->where('warehouse_code', $request->from_warehouse)->first();
        }

        return view('management.transfers.create', compact('user', 'warehouses', 'stores', 'products', 'preSelectedWarehouse'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_store_id' => 'required|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'submitted' => 'nullable|boolean',
        ]);

        $submitted = $request->boolean('submitted', false);

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'business_id' => $user->business_id,
                'from_location_type' => \App\Models\Warehouse::class,
                'from_location_id' => $validated['from_warehouse_id'],
                'to_location_type' => \App\Models\Store::class,
                'to_location_id' => $validated['to_store_id'],
                'requested_by' => $user->id,
                'status' => $submitted ? TransferStatus::PENDING : TransferStatus::DRAFT,
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

            Log::info('transfer.created', [
                'user_id' => $user->id,
                'transfer_id' => $transfer->id,
                'submitted' => $submitted,
            ]);

            $message = $submitted ? 'Transfer request submitted for approval.' : 'Transfer saved as draft.';

            return redirect()->route('management.transfers.show', $transfer)
                ->with('success', $message);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('transfer.create_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to create transfer.')->withInput();
        }
    }

    public function show(Request $request, StockTransfer $transfer): View
    {
        $user = $request->user();
        $transfer->load(['fromLocation', 'toLocation', 'requester', 'approver', 'dispatcher', 'receiver', 'items.product', 'items.variant']);

        return view('management.transfers.show', compact('user', 'transfer'));
    }

    public function submit(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeSubmitted()) {
            return back()->with('error', 'This transfer cannot be submitted.');
        }

        $transfer->update(['status' => TransferStatus::PENDING]);

        Log::info('transfer.submitted', ['transfer_id' => $transfer->id]);

        return back()->with('success', 'Transfer submitted for approval.');
    }

    public function approve(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeApproved()) {
            return back()->with('error', 'This transfer cannot be approved.');
        }

        $validated = $request->validate([
            'approved_quantities' => 'required|array',
            'approved_quantities.*' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $approved = $validated['approved_quantities'][$item->id] ?? $item->quantity;
                $item->update(['approved_quantity' => min($approved, $item->quantity)]);
            }

            $anyAdjusted = $transfer->items()->whereColumn('approved_quantity', '<', 'quantity')->exists();
            $newStatus = $transfer->isPending() && $anyAdjusted
                ? TransferStatus::AWAITING_ACKNOWLEDGMENT
                : TransferStatus::APPROVED;

            $transfer->update([
                'status' => $newStatus,
                'approved_by' => $request->user()->id,
            ]);

            DB::commit();

            Log::info('transfer.approved', [
                'transfer_id' => $transfer->id,
                'approved_by' => $request->user()->id,
                'status' => $newStatus->value,
                'adjusted' => $anyAdjusted,
            ]);

            $message = $anyAdjusted
                ? 'Quantities adjusted and sent for acknowledgement.'
                : 'Transfer approved.';

            return back()->with('success', $message);

        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to approve transfer.');
        }
    }

    public function acknowledge(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeAcknowledged()) {
            return back()->with('error', 'This transfer is not awaiting acknowledgement.');
        }

        $transfer->update(['status' => TransferStatus::APPROVED]);

        Log::info('transfer.acknowledged', [
            'transfer_id' => $transfer->id,
            'acknowledged_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Quantities acknowledged. Transfer is now approved.');
    }

    public function dispatch(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeDispatched()) {
            return back()->with('error', 'This transfer cannot be dispatched.');
        }

        $user = $request->user();

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $qty = $item->approved_quantity ?? $item->quantity;

                $sourceStock = StockLocation::where('product_id', $item->product_id)
                    ->where('locationable_type', $transfer->from_location_type)
                    ->where('locationable_id', $transfer->from_location_id)
                    ->when($item->product_variant_id, fn($q) => $q->where('product_variant_id', $item->product_variant_id))
                    ->first();

                if ($sourceStock) {
                    $ledger = app(\App\Services\StockLedgerService::class);
                    $ledger->recordRemoval(
                        $sourceStock, $qty, $transfer, $user,
                        'Transfer dispatched to ' . ($transfer->toLocation?->name ?? 'destination')
                    );
                }
            }

            $transfer->update([
                'status' => TransferStatus::DISPATCHED,
                'dispatched_by' => $user->id,
            ]);

            DB::commit();

            Log::info('transfer.dispatched', [
                'transfer_id' => $transfer->id,
                'dispatched_by' => $user->id,
            ]);

            return back()->with('success', 'Transfer dispatched. Stock moved from warehouse.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('transfer.dispatch_failed', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Failed to dispatch transfer.');
        }
    }

    public function receive(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeReceived()) {
            return back()->with('error', 'This transfer cannot be received yet.');
        }

        $user = $request->user();

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $qty = $item->approved_quantity ?? $item->quantity;

                $destStock = StockLocation::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'locationable_type' => $transfer->to_location_type,
                        'locationable_id' => $transfer->to_location_id,
                        'product_variant_id' => $item->product_variant_id,
                    ],
                    ['quantity' => 0, 'min_quantity' => 0]
                );

                $ledger = app(\App\Services\StockLedgerService::class);
                $ledger->recordAddition(
                    $destStock, $qty, $transfer, $user,
                    'Transfer received from ' . ($transfer->fromLocation?->name ?? 'source')
                );
            }

            $transfer->update([
                'status' => TransferStatus::RECEIVED,
                'received_by' => $user->id,
            ]);

            DB::commit();

            Log::info('transfer.received', [
                'transfer_id' => $transfer->id,
                'received_by' => $user->id,
            ]);

            return back()->with('success', 'Transfer received. Stock added to store.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('transfer.receive_failed', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Failed to receive transfer.');
        }
    }

    public function reject(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeRejected()) {
            return back()->with('error', 'This transfer cannot be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $transfer->update([
            'status' => TransferStatus::REJECTED,
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by' => $request->user()->id,
        ]);

        Log::info('transfer.rejected', [
            'transfer_id' => $transfer->id,
            'rejected_by' => $request->user()->id,
            'reason' => $validated['rejection_reason'],
        ]);

        return back()->with('success', 'Transfer rejected.');
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!$transfer->canBeCancelled()) {
            return back()->with('error', 'This transfer cannot be cancelled.');
        }

        $transfer->update(['status' => TransferStatus::CANCELLED]);

        Log::info('transfer.cancelled', ['transfer_id' => $transfer->id]);

        return back()->with('success', 'Transfer cancelled.');
    }
}
