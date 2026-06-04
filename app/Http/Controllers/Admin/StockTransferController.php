<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Enums\TransferStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    public function index(Request $request): View
    {
        Log::info('admin_transfers_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $query = StockTransfer::query()
            ->with(['fromLocation', 'toLocation', 'requester', 'items'])
            ->withCount('items');

        if ($status && in_array($status, array_map(fn($s) => $s->value, TransferStatus::cases()), true)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->where('transfer_code', 'like', "%$q%")
                  ->orWhereHas('fromLocation', fn($l) => $l->where('name', 'like', "%$q%"))
                  ->orWhereHas('toLocation', fn($l) => $l->where('name', 'like', "%$q%"));
            });
        }

        $transfers = $query->latest()->paginate(15)->withQueryString();
        $statuses = TransferStatus::cases();

        return view('admin.transfers.index', compact('transfers', 'statuses', 'status', 'q'));
    }

    public function show(StockTransfer $transfer): View
    {
        Log::info('admin_transfer_show_viewed', ['user_id' => auth()->id(), 'transfer_id' => $transfer->id]);

        $transfer->load([
            'fromLocation', 'toLocation', 'requester', 'approver', 'dispatcher', 'receiver',
            'items.product', 'items.variant', 'business',
        ]);

        return view('admin.transfers.show', compact('transfer'));
    }

    public function approve(Request $request, StockTransfer $transfer)
    {
        return $this->delegateToManagement('approve', $request, $transfer);
    }

    public function reject(Request $request, StockTransfer $transfer)
    {
        return $this->delegateToManagement('reject', $request, $transfer);
    }

    public function dispatch(Request $request, StockTransfer $transfer)
    {
        return $this->delegateToManagement('dispatch', $request, $transfer);
    }

    public function receive(Request $request, StockTransfer $transfer)
    {
        return $this->delegateToManagement('receive', $request, $transfer);
    }

    private function delegateToManagement(string $action, Request $request, StockTransfer $transfer)
    {
        $controller = app(\App\Http\Controllers\Management\StockTransferController::class);
        return $controller->{$action}($request, $transfer);
    }
}
