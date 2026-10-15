<?php

namespace App\Services\Storefront;

use App\Enums\ConsentType;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Services\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The customer's own data rights: take a copy, or ask to be erased.
 *
 * The two mirror each other on purpose. `export()` is the whole of what the
 * platform holds about one customer, gathered from the same relations the
 * erasure walks, so "everything you gave us" and "everything we removed" are
 * the same list rather than two that can drift.
 *
 * Nothing here deletes anything. A deletion request sets a timestamp and is
 * worked through by the business (App\Services\Management\CustomerErasureService);
 * a customer pressing the button has not been erased the moment they press it,
 * and the confirmation says so.
 */
final class CustomerDataRightsService
{
    public function __construct(private readonly ConsentService $consents) {}

    /**
     * Everything the storefront shows the customer, in one document.
     *
     * Orders carry their items and their payment rows: those are the financial
     * records, and they are the reason an erasure anonymises rather than
     * deletes. Addresses are the snapshots as well as the saved book — an order
     * was delivered somewhere, and a copy of your data that omits it would be a
     * lie of omission.
     *
     * @return array<string, mixed>
     */
    public function export(Customer $customer): array
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'transactions', 'store:id,name,slug'])
            ->latest()
            ->get();

        $addresses = DeliveryAddress::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return [
            'generated_at' => now()->toISOString(),
            'account' => [
                'account_id' => $customer->account_id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status,
                'account_state' => $customer->accountState(),
                'registered_at' => $customer->registered_at?->toISOString(),
                'email_verified_at' => $customer->email_verified_at?->toISOString(),
                'last_login' => $customer->last_login?->toISOString(),
                'created_at' => $customer->created_at?->toISOString(),
            ],
            'consents' => $this->consentTrail($customer),
            'addresses' => $addresses->map(fn (DeliveryAddress $address) => [
                'label' => $address->label,
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->recipient_phone,
                'full_address' => $address->full_address,
                'is_default' => (bool) $address->is_default,
                // Labelled rather than filtered out: an order's copy is data
                // about the customer too, and saying which is which is more
                // honest than dropping rows the customer remembers using.
                'kind' => $address->is_snapshot ? 'order_delivery' : 'saved',
                'created_at' => $address->created_at?->toISOString(),
            ])->values()->all(),
            'orders' => $orders->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'store' => $order->store?->name,
                'status' => $order->status,
                'subtotal' => (float) $order->subtotal,
                'shipping_fee' => (float) $order->shipping_fee,
                'tax' => (float) $order->tax,
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'placed_at' => $order->created_at?->toISOString(),
                'items' => $order->items->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'product_code' => $item->product_code,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ])->values()->all(),
                'payments' => $order->transactions->map(fn ($transaction) => [
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status?->value ?? $transaction->status,
                    'paid_at' => $transaction->created_at?->toISOString(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Ask to be erased. Nothing is removed here — this is the queue entry.
     *
     * Every session is ended, because the customer has just said they are
     * leaving and a token that outlives the request would keep working on an
     * account that is about to be anonymised. Idempotent: a second press
     * returns the same answer rather than moving the timestamp, so the queue
     * shows when the customer actually asked.
     */
    public function requestDeletion(Customer $customer, Request $request): Customer
    {
        return DB::transaction(function () use ($customer, $request) {
            if ($customer->deletion_requested_at === null) {
                $customer->forceFill(['deletion_requested_at' => now()])->save();

                Log::info('api.storefront.customer_deletion_requested', [
                    'customer_id' => $customer->id,
                    'business_id' => $customer->business_id,
                    'ip_address' => $request->ip(),
                ]);
            }

            return $customer->fresh();
        });
    }

    /**
     * The consent trail, oldest first, so the export reads as a history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consentTrail(Customer $customer): array
    {
        $trail = [];

        foreach (ConsentType::cases() as $type) {
            foreach ($this->consents->history($customer, $type)->sortBy('id') as $row) {
                $trail[] = [
                    'type' => $type->value,
                    'granted' => (bool) $row->granted,
                    'source' => $row->source,
                    'recorded_at' => $row->created_at?->toISOString(),
                ];
            }
        }

        return $trail;
    }
}
