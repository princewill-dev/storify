@extends('management.layout')
@section('subtitle', $order->order_number)

@section('content')
@php $pendingTx = $order->transactions->first(); $txPending = $pendingTx && $pendingTx->status->value === 'pending'; @endphp
<div x-data="{ acceptModal: false, processModal: false, dispatchModal: false, deliverModal: false, completeModal: false, cancelModal: false, returnModal: false, paymentWarningModal: false }">

<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$order->order_number" subtitle="{{ $order->store?->name ?? 'N/A' }} · {{ $order->created_at->format('d M Y H:i') }}">
    <x-slot:actions>
        <x-management.status-badge :status="$order->status" />
        @php $s = $order->status->value; @endphp
        @if($s === 'pending')
            <button @click="{{ $txPending ? 'paymentWarningModal = true' : 'acceptModal = true' }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-check text-xs"></i> Accept</button>
            <button @click="cancelModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50"><i class="fi fi-rr-cross-small text-xs"></i> Cancel</button>
        @elseif($s === 'accepted')
            <button @click="processModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-arrow-right text-xs"></i> Process</button>
            <button @click="cancelModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50"><i class="fi fi-rr-cross-small text-xs"></i> Cancel</button>
        @elseif($s === 'processing')
            <button @click="dispatchModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700"><i class="fi fi-rr-truck-side text-xs"></i> Dispatch</button>
        @elseif($s === 'dispatched')
            <button @click="deliverModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-box-check text-xs"></i> Mark Delivered</button>
        @elseif($s === 'delivered')
            <button @click="completeModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-check-circle text-xs"></i> Complete</button>
            <button @click="returnModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50"><i class="fi fi-rr-undo text-xs"></i> Return</button>
        @elseif($s === 'completed')
            <button @click="returnModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50"><i class="fi fi-rr-undo text-xs"></i> Return</button>
        @endif
    </x-slot:actions>
</x-management.page-header>

<div class="max-w-3xl">

    {{-- Key Metrics --}}
    <div class="flex items-baseline gap-6 mb-8">
        <div>
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total</span>
            <p class="text-3xl font-bold text-slate-900 mt-0.5 tracking-tight">₦{{ number_format($order->total, 2) }}</p>
        </div>
        @if((float) $order->remainingBalance() > 0)
        <div>
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Amount Paid</span>
            <p class="text-xl font-bold text-emerald-600 mt-0.5">₦{{ number_format($order->amount_paid, 2) }}</p>
            <p class="text-xs text-amber-600 mt-0.5">₦{{ number_format($order->remainingBalance(), 2) }} remaining</p>
        </div>
        @endif
        <div class="text-sm text-slate-400 space-x-3">
            <span>{{ $order->items->count() }} item(s)</span>
            <span class="text-slate-300">·</span>
            <span>{{ $order->created_at->format('d M Y') }}</span>
            <span>{{ $order->created_at->format('H:i') }}</span>
        </div>
    </div>

    {{-- Order Items --}}
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Items</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach($order->items as $item)
                <tr>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            @if($item->product?->image)
                            <img src="{{ asset('storage/'.$item->product->image) }}" alt="{{ $item->product_name }}" class="w-8 h-8 rounded-lg object-cover border border-slate-200 shrink-0">
                            @else
                            <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center shrink-0"><i class="fi fi-rr-box text-slate-400 text-xs"></i></div>
                            @endif
                            <div>
                                <p class="text-slate-700 font-medium">{{ $item->product_name }}</p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-700 w-28">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-semibold">
                    <td class="px-5 py-3 text-slate-600">Total</td>
                    <td class="px-5 py-3 text-right text-slate-900">₦{{ number_format($order->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Order Details --}}
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Order Details</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Source</td>
                    <td class="px-5 py-3">
                        <span class="uppercase">{{ str_replace('_', ' ', $order->source) }}</span>
                        @if($order->source === 'pos')<span class="inline-flex items-center ml-2 px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-50 text-purple-600">POS</span>@endif
                    </td>
                </tr>
                @if($order->staff)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Processed By</td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->staff->name }} <span class="text-slate-400 ml-1">({{ $order->staff->email }})</span></td>
                </tr>
                @endif
                @php $pm = $order->transactions->first()?->paymentMethod; $tx = $order->transactions->first(); @endphp
                @if($pm)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Payment Method</td>
                    <td class="px-5 py-3 text-slate-600">{{ $pm->code === 'cash' ? 'Cash' : ($pm->code === 'bank_transfer' ? 'Bank Transfer' : ($pm->name ?? '—')) }}</td>
                </tr>
                @endif
                <tr>
                    <td class="px-5 py-3 text-slate-400">Subtotal</td>
                    <td class="px-5 py-3 text-slate-600">₦{{ number_format($order->subtotal, 2) }}</td>
                </tr>
                @if($order->shipping_fee > 0)
                <tr><td class="px-5 py-3 text-slate-400">Shipping</td><td class="px-5 py-3 text-slate-600">₦{{ number_format($order->shipping_fee, 2) }}</td></tr>
                @endif
                @php
                    $scAmount = $order->service_charge_amount
                        ?? ($order->meta['service_charge_amount'] ?? null)
                        ?? (($order->total - $order->subtotal - $order->shipping_fee - $order->tax > 0) ? $order->total - $order->subtotal - $order->shipping_fee - $order->tax : null);
                @endphp
                @if($scAmount > 0)
                <tr><td class="px-5 py-3 text-slate-400">Service Charge{{ $order->meta['service_charge_name'] ?? '' ? ' (' . $order->meta['service_charge_name'] . ')' : '' }}</td><td class="px-5 py-3 text-slate-600">₦{{ number_format($scAmount, 2) }}</td></tr>
                @endif
                @if($order->tax > 0)
                <tr><td class="px-5 py-3 text-slate-400">Tax</td><td class="px-5 py-3 text-slate-600">₦{{ number_format($order->tax, 2) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Customer --}}
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Customer</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @if($order->customer?->email && !str_contains($order->customer->email, 'walkin@pos.local'))
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Name</td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->customer->first_name }} {{ $order->customer->last_name }}</td>
                </tr>
                <tr><td class="px-5 py-3 text-slate-400">Email</td><td class="px-5 py-3 text-slate-600">{{ $order->customer->email }}</td></tr>
                @if($order->customer->phone)
                <tr><td class="px-5 py-3 text-slate-400">Phone</td><td class="px-5 py-3 text-slate-600">{{ $order->customer->phone }}</td></tr>
                @endif
                @elseif($order->meta['customer_name'] ?? false)
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Name</td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->meta['customer_name'] }}</td>
                </tr>
                @if($order->meta['customer_phone'] ?? false)
                <tr><td class="px-5 py-3 text-slate-400">Phone</td><td class="px-5 py-3 text-slate-600">{{ $order->meta['customer_phone'] }}</td></tr>
                @endif
                @else
                <tr><td colspan="2" class="px-5 py-3 text-slate-400">Walk-in customer</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Delivery --}}
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Delivery</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @if($order->deliveryAddress)
                <tr><td class="px-5 py-3 text-slate-400 w-48">Address</td><td class="px-5 py-3 text-slate-600">{{ $order->deliveryAddress->full_address ?? $order->deliveryAddress->address }}</td></tr>
                @elseif($order->delivery_state)
                <tr><td class="px-5 py-3 text-slate-400">Area</td><td class="px-5 py-3 text-slate-600">{{ $order->delivery_area }}, {{ $order->delivery_state }}</td></tr>
                @else
                <tr><td colspan="2" class="px-5 py-3 text-slate-400">No delivery information</td></tr>
                @endif
                @if($order->deliveryRoute)
                <tr><td class="px-5 py-3 text-slate-400">Route</td><td class="px-5 py-3 text-slate-600">{{ $order->deliveryRoute->area }} — ₦{{ number_format($order->deliveryRoute->fee, 2) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Bank Account --}}
    @if($tx && $tx->storeBank)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Bank Account</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr><td class="px-5 py-3 text-slate-400 w-48">Bank</td><td class="px-5 py-3 text-slate-600">{{ $tx->storeBank->bank_name }}</td></tr>
                <tr><td class="px-5 py-3 text-slate-400">Account Number</td><td class="px-5 py-3 text-slate-600">{{ $tx->storeBank->account_number }}</td></tr>
                <tr><td class="px-5 py-3 text-slate-400">Account Name</td><td class="px-5 py-3 text-slate-600">{{ $tx->storeBank->account_name }}</td></tr>
                @if($tx->storeBank->is_verified)
                <tr><td class="px-5 py-3 text-slate-400">Status</td><td class="px-5 py-3"><span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Verified</span></td></tr>
                @endif
            </tbody>
        </table>
    </div>
    @endif

    {{-- Delivery Tracking --}}
    @php $delivery = $order->delivery; @endphp
    @if($delivery)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Delivery Tracking</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr><td class="px-5 py-3 text-slate-400 w-48">Status</td><td class="px-5 py-3"><span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">{{ ucfirst(str_replace('_', ' ', $delivery->status)) }}</span></td></tr>
                @if($delivery->tracking_number)
                <tr><td class="px-5 py-3 text-slate-400">Tracking</td><td class="px-5 py-3 font-mono text-slate-600">{{ $delivery->tracking_number }}</td></tr>
                @endif
                @if($delivery->driver_name)
                <tr><td class="px-5 py-3 text-slate-400">Driver</td><td class="px-5 py-3 text-slate-600">{{ $delivery->driver_name }} @if($delivery->driver_phone)<span class="text-slate-400">· {{ $delivery->driver_phone }}</span>@endif</td></tr>
                @endif
                @if($delivery->estimated_delivery_at)
                <tr><td class="px-5 py-3 text-slate-400">Est. Delivery</td><td class="px-5 py-3 text-slate-600">{{ \Carbon\Carbon::parse($delivery->estimated_delivery_at)->format('d M Y, H:i') }}</td></tr>
                @endif
                @if($delivery->actual_delivery_at)
                <tr><td class="px-5 py-3 text-slate-400">Actual Delivery</td><td class="px-5 py-3 text-emerald-600">{{ \Carbon\Carbon::parse($delivery->actual_delivery_at)->format('d M Y, H:i') }}</td></tr>
                @endif
            </tbody>
        </table>
        @if($delivery->delivery_notes)
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-sm text-slate-500">{{ $delivery->delivery_notes }}</div>
        @endif
        @if($delivery->return_reason)
        <div class="px-5 py-3 bg-red-50 border-t border-red-100 text-sm text-red-700"><span class="text-xs text-red-400 uppercase tracking-wider font-bold">Return Reason:</span> {{ $delivery->return_reason }}</div>
        @endif
    </div>
    @endif

    {{-- Transactions --}}
    @if($order->transactions->count() > 0)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Transactions</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach($order->transactions as $tx)
                <tr>
                    <td class="px-5 py-3">
                        <a href="{{ route('management.transactions.show', $tx) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $tx->reference }}</a>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $tx->created_at->format('d M Y, H:i') }}</p>
                    </td>
                    <td class="px-5 py-3"><x-management.status-badge :status="$tx->status" /></td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-700">₦{{ number_format($tx->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Notes --}}
    @if($order->notes)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Notes</h3>
        </div>
        <div class="px-5 py-3 text-sm text-slate-600 whitespace-pre-line">{{ $order->notes }}</div>
    </div>
    @endif

    {{-- Activity --}}
    @if($activityLogs->isNotEmpty())
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Activity</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach($activityLogs as $log)
                <tr>
                    <td class="px-5 py-3 w-8"><div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center"><span class="text-[10px] font-semibold text-slate-500">{{ strtoupper(substr($log->user?->name ?? 'S', 0, 1)) }}</span></div></td>
                    <td class="px-5 py-3">
                        <p class="text-slate-700">{{ $log->description }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $log->user?->name ?? 'System' }} · {{ $log->created_at->diffForHumans() }}</p>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- ═══ MODALS (unchanged) ═══ --}}

{{-- Payment Warning Modal --}}
@if($txPending)
<div x-show="paymentWarningModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="paymentWarningModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="paymentWarningModal = false"></div>
        <div x-show="paymentWarningModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center"><i class="fi fi-rr-exclamation text-amber-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Payment Pending</h3><p class="text-sm text-slate-500">Please confirm the payment before accepting this order.</p></div></div>
            </div>
            <div class="bg-slate-50 px-6 py-4 space-y-3">
                <div class="flex justify-between text-sm"><span class="text-slate-500">Transaction</span><span class="font-mono font-semibold text-slate-900">{{ $pendingTx->reference }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-500">Amount</span><span class="font-semibold text-slate-900">₦{{ number_format($pendingTx->amount, 2) }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-500">Status</span><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Pending</span></div>
            </div>
            <div class="px-6 py-4 flex items-center justify-between border-t border-slate-200">
                <button @click="paymentWarningModal = false" class="text-sm text-slate-500 hover:text-slate-700">Dismiss</button>
                <a href="{{ route('management.transactions.show', $pendingTx) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-arrow-right text-xs"></i> Review Payment</a>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Accept Modal --}}
<div x-show="acceptModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="acceptModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="acceptModal = false"></div>
        <div x-show="acceptModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fi fi-rr-check text-blue-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Accept Order</h3><p class="text-sm text-slate-500">Confirm that this order has been reviewed.</p></div></div>
            </div>
            <div class="bg-slate-50 px-6 py-4 space-y-3">
                <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-500">Total</span><span class="font-semibold text-slate-900">₦{{ number_format($order->total, 2) }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-500">Items</span><span class="text-slate-700">{{ $order->items->count() }} item(s)</span></div>
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                <button @click="acceptModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                <form method="POST" action="{{ route('management.orders.accept', $order) }}">@csrf<button class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-check text-xs"></i> Accept Order</button></form>
            </div>
        </div>
    </div>
</div>

{{-- Process Modal --}}
<div x-show="processModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="processModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="processModal = false"></div>
        <div x-show="processModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fi fi-rr-arrow-right text-blue-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Process Order</h3><p class="text-sm text-slate-500">Move this order into processing.</p></div></div>
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                <button @click="processModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                <form method="POST" action="{{ route('management.orders.process', $order) }}">@csrf<button class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-arrow-right text-xs"></i> Process Order</button></form>
            </div>
        </div>
    </div>
</div>

{{-- Dispatch Modal --}}
<div x-show="dispatchModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" x-data="{ agentData: null }">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="dispatchModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="dispatchModal = false"></div>
        <div x-show="dispatchModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center"><i class="fi fi-rr-truck-side text-amber-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Dispatch Order</h3><p class="text-sm text-slate-500">Stock will be reduced from inventory. Assign a delivery agent or enter driver details.</p></div></div>
            </div>
            <form method="POST" action="{{ route('management.orders.dispatch', $order) }}">@csrf
                <div class="px-6 py-4 space-y-4">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Items</span><span class="text-slate-700">{{ $order->items->count() }} item(s) — ₦{{ number_format($order->total, 2) }}</span></div>
                    @if($deliveryAgents->isNotEmpty())
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Assign Delivery Agent</label>
                        <select name="delivery_agent_id" x-on:change="agentData = JSON.parse($event.target.selectedOptions[0].dataset.agent || 'null'); if(agentData) { $refs.driverName.value = agentData.name; $refs.driverPhone.value = agentData.phone || ''; } else { $refs.driverName.value = ''; $refs.driverPhone.value = ''; }" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm">
                            <option value="">— Select a delivery agent —</option>
                            @foreach($deliveryAgents as $agent)
                            <option value="{{ $agent->id }}" data-agent="{{ json_encode(['name' => $agent->name, 'phone' => $agent->phone]) }}">{{ $agent->name }} @if($agent->phone)· {{ $agent->phone }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-xs font-medium text-slate-600 mb-1">Driver Name</label><input name="driver_name" x-ref="driverName" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="e.g., John Doe"></div>
                        <div><label class="block text-xs font-medium text-slate-600 mb-1">Driver Phone</label><input name="driver_phone" x-ref="driverPhone" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="e.g., 08012345678"></div>
                    </div>
                    <div><label class="block text-xs font-medium text-slate-600 mb-1">Delivery Notes</label><textarea name="delivery_notes" rows="2" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="Any special instructions..."></textarea></div>
                </div>
                <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button type="button" @click="dispatchModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700"><i class="fi fi-rr-truck-side text-xs"></i> Dispatch Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Deliver Modal --}}
<div x-show="deliverModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="deliverModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="deliverModal = false"></div>
        <div x-show="deliverModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fi fi-rr-box-check text-emerald-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Mark as Delivered</h3><p class="text-sm text-slate-500">Confirm that the customer has received this order.</p></div></div>
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                <button @click="deliverModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                <form method="POST" action="{{ route('management.orders.deliver', $order) }}">@csrf<button class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-box-check text-xs"></i> Mark Delivered</button></form>
            </div>
        </div>
    </div>
</div>

{{-- Complete Modal --}}
<div x-show="completeModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="completeModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="completeModal = false"></div>
        <div x-show="completeModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fi fi-rr-check-circle text-emerald-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Complete Order</h3><p class="text-sm text-slate-500">Finalize this order. No further changes.</p></div></div>
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                <button @click="completeModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                <form method="POST" action="{{ route('management.orders.complete', $order) }}">@csrf<button class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-check-circle text-xs"></i> Complete Order</button></form>
            </div>
        </div>
    </div>
</div>

{{-- Cancel Modal --}}
<div x-show="cancelModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="cancelModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="cancelModal = false"></div>
        <div x-show="cancelModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-cross-small text-red-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Cancel Order</h3><p class="text-sm text-slate-500">This action cannot be undone.</p></div></div>
            </div>
            <form method="POST" action="{{ route('management.orders.cancel', $order) }}">@csrf
                <div class="px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label><textarea name="reason" rows="2" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Customer requested cancellation..."></textarea></div>
                </div>
                <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button type="button" @click="cancelModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Back</button>
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-cross-small text-xs"></i> Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Return Modal --}}
<div x-show="returnModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="returnModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="returnModal = false"></div>
        <div x-show="returnModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-undo text-red-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Process Return</h3><p class="text-sm text-slate-500">Stock will be restored to inventory.</p></div></div>
            </div>
            <form method="POST" action="{{ route('management.orders.return', $order) }}">@csrf
                <div class="px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Items to Restore</span><span class="text-slate-700">{{ $order->items->count() }} item(s)</span></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label><textarea name="reason" rows="2" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Damaged on arrival..."></textarea></div>
                </div>
                <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button type="button" @click="returnModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-undo text-xs"></i> Process Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
</div>
@endsection
