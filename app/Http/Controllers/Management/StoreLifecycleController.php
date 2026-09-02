<?php

namespace App\Http\Controllers\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\StoreAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class StoreLifecycleController extends Controller
{
    public function __construct(private readonly StoreAccessService $access) {}

    public function suspend(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $reason = $data['reason'] ?? 'Suspended by store owner';
        $store->update(['status' => Store::STATUS_SUSPENDED]);
        $this->sendStatusMail($request, new StoreSuspended($store, $reason));

        return back()->with('success', 'Store suspended successfully.');
    }

    public function activate(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $kyc = $request->user()->kycApplication;

        if (! $kyc || $kyc->status !== KycApplication::STATUS_APPROVED) {
            return back()->with('error', 'Complete KYC verification before activating this store.');
        }

        $reason = $data['reason'] ?? 'Reactivated by store owner';
        $store->update(['status' => Store::STATUS_ACTIVE]);
        $this->sendStatusMail($request, new StoreReactivated($store, $reason));

        return back()->with('success', 'Store activated successfully.');
    }

    public function destroy(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);

        if (Order::where('store_id', $store->id)->where('status', '!=', OrderStatus::COMPLETED)->exists()) {
            return back()->with('error', 'Cannot delete: store has incomplete orders.');
        }

        if (Transaction::whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', '!=', TransactionStatus::CONFIRMED)->exists()) {
            return back()->with('error', 'Cannot delete: store has incomplete transactions.');
        }

        $store->update(['status' => Store::STATUS_DELETED]);
        Log::info('store.deleted', ['user_id' => $request->user()->id, 'store_id' => $store->id]);

        return redirect()->route('management.stores.index')->with('success', "Store '{$store->name}' has been deleted.");
    }

    private function sendStatusMail(Request $request, $mail): void
    {
        try {
            if ($request->user()->email) {
                Mail::to($request->user()->email)->queue($mail);
            }
        } catch (\Throwable $e) {
            Log::error('store.status_mail_failed', ['store_id' => $mail->store->id, 'error' => $e->getMessage()]);
        }
    }
}
