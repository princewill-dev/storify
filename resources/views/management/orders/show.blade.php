@extends('management.layout')
@section('subtitle', $order->order_number)

@section('content')
@php $pendingTx = $order->transactions->first(); $txPending = $pendingTx && $pendingTx->status->value === 'pending'; @endphp
<div x-data="{ acceptModal: false, processModal: false, dispatchModal: false, deliverModal: false, completeModal: false, cancelModal: false, returnModal: false, paymentWarningModal: false }">

<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$order->order_number" subtitle="{{ $order->store?->name ?? 'N/A' }} · {{ $order->created_at->format('d M Y H:i') }}">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <x-management.status-badge :status="$order->status" />

            @php $s = $order->status->value; @endphp

            @if($s === 'pending')
                <button @click="{{ $txPending ? 'paymentWarningModal = true' : 'acceptModal = true' }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                    <i class="fi fi-rr-check text-xs"></i> Accept
                </button>
                <button @click="cancelModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-cross-small text-xs"></i> Cancel
                </button>
            @elseif($s === 'accepted')
                <button @click="processModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                    <i class="fi fi-rr-arrow-right text-xs"></i> Process
                </button>
                <button @click="cancelModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-cross-small text-xs"></i> Cancel
                </button>
            @elseif($s === 'processing')
                <button @click="dispatchModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 transition-colors">
                    <i class="fi fi-rr-truck-side text-xs"></i> Dispatch
                </button>
            @elseif($s === 'dispatched')
                <button @click="deliverModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors">
                    <i class="fi fi-rr-box-check text-xs"></i> Mark Delivered
                </button>
            @elseif($s === 'delivered')
                <button @click="completeModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors">
                    <i class="fi fi-rr-check-circle text-xs"></i> Complete
                </button>
                <button @click="returnModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-undo text-xs"></i> Return
                </button>
            @elseif($s === 'completed')
                <button @click="returnModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-undo text-xs"></i> Return
                </button>
            @endif
        </div>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Main Content --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Order Items --}}
        <x-management.card header="Order Items">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach($order->items as $item)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        @if($item->product?->image)
                        <img src="{{ asset('storage/'.$item->product->image) }}" alt="{{ $item->product_name }}" class="w-10 h-10 rounded-lg object-cover border border-slate-200">
                        @else
                        <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center">
                            <i class="fi fi-rr-box text-slate-400"></i>
                        </div>
                        @endif
                        <div>
                            <p class="text-sm font-medium text-slate-800">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-400">Qty: {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }}</p>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-slate-800">₦{{ number_format($item->subtotal, 2) }}</span>
                </div>
                @endforeach
            </div>
            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between rounded-b-xl">
                <span class="text-sm font-semibold text-slate-700">Total</span>
                <span class="text-lg font-bold text-slate-900">₦{{ number_format($order->total, 2) }}</span>
            </div>
        </x-management.card>

        {{-- Delivery Tracking --}}
        @php $delivery = $order->delivery; @endphp
        @if($delivery)
        <x-management.card header="Delivery Tracking">
            <div class="space-y-4">
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">
                            {{ ucfirst(str_replace('_', ' ', $delivery->status)) }}
                        </span>
                    </div>
                    @if($delivery->tracking_number)
                    <div class="flex items-center gap-1.5 text-sm">
                        <span class="text-slate-400">Tracking:</span>
                        <span class="font-mono font-medium text-slate-700">{{ $delivery->tracking_number }}</span>
                    </div>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @if($delivery->driver_name)
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Driver</span>
                        <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $delivery->driver_name }}</p>
                        @if($delivery->driver_phone)<p class="text-xs text-slate-400">{{ $delivery->driver_phone }}</p>@endif
                    </div>
                    @endif
                    @if($delivery->estimated_delivery_at)
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Estimated Delivery</span>
                        <p class="text-sm font-medium text-slate-800 mt-0.5">{{ \Carbon\Carbon::parse($delivery->estimated_delivery_at)->format('d M Y, H:i') }}</p>
                    </div>
                    @endif
                    @if($delivery->actual_delivery_at)
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Actual Delivery</span>
                        <p class="text-sm font-medium text-emerald-600 mt-0.5">{{ \Carbon\Carbon::parse($delivery->actual_delivery_at)->format('d M Y, H:i') }}</p>
                    </div>
                    @endif
                </div>

                @if($delivery->current_location)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Current Location</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $delivery->current_location }}</p>
                </div>
                @endif

                @if($delivery->delivery_notes)
                <div class="bg-slate-50 rounded-lg p-3">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Notes</span>
                    <p class="text-sm text-slate-600 mt-1">{{ $delivery->delivery_notes }}</p>
                </div>
                @endif

                @if($delivery->return_reason)
                <div class="bg-red-50 border border-red-100 rounded-lg p-3">
                    <span class="text-xs text-red-400 uppercase tracking-wider">Return Reason</span>
                    <p class="text-sm text-red-700 mt-1">{{ $delivery->return_reason }}</p>
                </div>
                @endif
            </div>
        </x-management.card>
        @endif

        {{-- Transactions --}}
        @if($order->transactions->count() > 0)
        <x-management.card header="Transactions">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach($order->transactions as $tx)
                <div class="flex items-center justify-between px-5 py-3">
                    <div>
                        <a href="{{ route('management.transactions.show', $tx) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $tx->reference }}</a>
                        <p class="text-xs text-slate-400">{{ $tx->created_at->format('d M Y H:i') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-management.status-badge :status="$tx->status" />
                        <span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </x-management.card>
        @endif

        {{-- Activity Log --}}
        @if($activityLogs->isNotEmpty())
        <x-management.card header="Activity">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach($activityLogs as $log)
                <div class="flex items-start gap-3 px-5 py-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center mt-0.5">
                        <span class="text-xs font-semibold text-slate-500">{{ strtoupper(substr($log->user?->name ?? 'S', 0, 1)) }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-slate-700">{{ $log->description }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $log->user?->name ?? 'System' }} · {{ $log->created_at->diffForHumans() }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </x-management.card>
        @endif
    </div>

    {{-- Sidebar --}}
    <div class="space-y-6">
        <x-management.card header="Order Details">
            <div class="space-y-3">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Source</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">
                        {{ ucfirst($order->source) }}
                        @if($order->source === 'pos')<span class="inline-flex items-center ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-50 text-purple-600">POS</span>@endif
                    </p>
                </div>
                @if($order->staff)
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Handled by</span><p class="text-sm font-medium text-slate-800 mt-0.5">{{ $order->staff->name }}</p></div>
                @endif
                @php $pm = $order->transactions->first()?->paymentMethod; @endphp
                @if($pm)
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Payment</span><p class="text-sm font-medium text-slate-800 mt-0.5">{{ $pm->code === 'cash' ? 'Cash' : ($pm->code === 'bank_transfer' ? 'Bank Transfer' : ($pm->name ?? '—')) }}</p></div>
                @endif
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Subtotal</span><p class="text-sm font-medium text-slate-800 mt-0.5">₦{{ number_format($order->subtotal, 2) }}</p></div>
                @if($order->shipping_fee > 0)<div><span class="text-xs text-slate-400 uppercase tracking-wider">Shipping</span><p class="text-sm text-slate-600 mt-0.5">₦{{ number_format($order->shipping_fee, 2) }}</p></div>@endif
                @if($order->tax > 0)<div><span class="text-xs text-slate-400 uppercase tracking-wider">Tax</span><p class="text-sm text-slate-600 mt-0.5">₦{{ number_format($order->tax, 2) }}</p></div>@endif
            </div>
        </x-management.card>

        @if($order->notes)
        <x-management.card header="Notes">
            <p class="text-sm text-slate-600 whitespace-pre-line">{{ $order->notes }}</p>
        </x-management.card>
        @endif

        <x-management.card header="Customer">
            @if($order->customer && $order->customer->email !== 'walkin@pos.local')
            <div class="flex items-center gap-3">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600">
                    {{ strtoupper(substr($order->customer->first_name, 0, 1)) }}{{ strtoupper(substr($order->customer->last_name, 0, 1)) }}
                </span>
                <div>
                    <p class="text-sm font-semibold text-slate-800">{{ $order->customer->first_name }} {{ $order->customer->last_name }}</p>
                    <p class="text-xs text-slate-400">{{ $order->customer->email }}</p>
                    @if($order->customer->phone)<p class="text-xs text-slate-400">{{ $order->customer->phone }}</p>@endif
                </div>
            </div>
            @elseif($order->meta['customer_name'] ?? false)
                <p class="text-sm font-semibold text-slate-800">{{ $order->meta['customer_name'] }}</p>
                @if($order->meta['customer_phone'] ?? false)<p class="text-xs text-slate-400">{{ $order->meta['customer_phone'] }}</p>@endif
            @else
            <p class="text-sm text-slate-400">Walk-in customer</p>
            @endif
        </x-management.card>

        <x-management.card header="Delivery">
            @if($order->deliveryAddress)
                <p class="text-sm text-slate-700">{{ $order->deliveryAddress->full_address ?? $order->deliveryAddress->address }}</p>
            @elseif($order->delivery_state)
                <p class="text-sm text-slate-700">{{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
            @else
                <p class="text-sm text-slate-400">No delivery information</p>
            @endif
            @if($order->deliveryRoute)
                <div class="mt-2 pt-2 border-t border-slate-100">
                    <span class="text-xs text-slate-400">Route: {{ $order->deliveryRoute->area }} — ₦{{ number_format($order->deliveryRoute->fee, 2) }}</span>
                </div>
            @endif
        </x-management.card>
    </div>

    {{-- ═══ MODALS ═══ --}}

    {{-- Payment Warning Modal --}}
    @if($txPending)
    <div x-show="paymentWarningModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="paymentWarningModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="paymentWarningModal = false" aria-hidden="true"></div>
            <div x-show="paymentWarningModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center"><i class="fi fi-rr-exclamation text-amber-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Payment Pending</h3><p class="text-sm text-slate-500">Please confirm the payment before accepting this order.</p></div>
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Transaction</span><span class="font-mono font-semibold text-slate-900">{{ $pendingTx->reference }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Amount</span><span class="font-semibold text-slate-900">₦{{ number_format($pendingTx->amount, 2) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Status</span><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span></div>
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-between border-t border-slate-200">
                    <button @click="paymentWarningModal = false" class="text-sm text-slate-500 hover:text-slate-700">Dismiss</button>
                    <a href="{{ route('management.transactions.show', $pendingTx) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-arrow-right text-xs"></i> Review Payment</a>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Accept Modal --}}
    <div x-show="acceptModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="acceptModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="acceptModal = false" aria-hidden="true"></div>
            <div x-show="acceptModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fi fi-rr-check text-blue-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Accept Order</h3><p class="text-sm text-slate-500">Confirm that this order has been reviewed.</p></div>
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Total</span><span class="font-semibold text-slate-900">₦{{ number_format($order->total, 2) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Items</span><span class="text-slate-700">{{ $order->items->count() }} item(s)</span></div>
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button @click="acceptModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <form method="POST" action="{{ route('management.orders.accept', $order) }}">@csrf<button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-check text-xs"></i> Accept Order</button></form>
                </div>
            </div>
        </div>
    </div>

    {{-- Process Modal --}}
    <div x-show="processModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="processModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="processModal = false" aria-hidden="true"></div>
            <div x-show="processModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fi fi-rr-arrow-right text-blue-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Process Order</h3><p class="text-sm text-slate-500">Move this order into processing.</p></div>
                    </div>
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button @click="processModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <form method="POST" action="{{ route('management.orders.process', $order) }}">@csrf<button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-arrow-right text-xs"></i> Process Order</button></form>
                </div>
            </div>
        </div>
    </div>

    {{-- Dispatch Modal --}}
    <div x-show="dispatchModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" x-data="{ agentData: null }">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="dispatchModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="dispatchModal = false" aria-hidden="true"></div>
            <div x-show="dispatchModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center"><i class="fi fi-rr-truck-side text-amber-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Dispatch Order</h3><p class="text-sm text-slate-500">Stock will be reduced from inventory. Assign a delivery agent or enter driver details.</p></div>
                    </div>
                </div>
                <form method="POST" action="{{ route('management.orders.dispatch', $order) }}">
                    @csrf
                    <div class="bg-white px-6 py-4 space-y-4">
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Items</span><span class="text-slate-700">{{ $order->items->count() }} item(s) — ₦{{ number_format($order->total, 2) }}</span></div>

                        {{-- Delivery Agent Picker --}}
                        @if($deliveryAgents->isNotEmpty())
                        <div>
                            <label for="delivery_agent" class="block text-xs font-medium text-slate-600 mb-1">Assign Delivery Agent</label>
                            <select id="delivery_agent" name="delivery_agent_id"
                                x-on:change="agentData = JSON.parse($event.target.selectedOptions[0].dataset.agent || 'null');
                                    if(agentData) { $refs.driverName.value = agentData.name; $refs.driverPhone.value = agentData.phone || ''; }
                                    else { $refs.driverName.value = ''; $refs.driverPhone.value = ''; }"
                                class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm">
                                <option value="">— Select a delivery agent —</option>
                                @foreach($deliveryAgents as $agent)
                                <option value="{{ $agent->id }}" data-agent="{{ json_encode(['name' => $agent->name, 'phone' => $agent->phone]) }}">
                                    {{ $agent->name }} @if($agent->phone)· {{ $agent->phone }}@endif
                                </option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="driver_name" class="block text-xs font-medium text-slate-600 mb-1">Driver Name</label>
                                <input id="driver_name" name="driver_name" x-ref="driverName" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="e.g., John Doe">
                            </div>
                            <div>
                                <label for="driver_phone" class="block text-xs font-medium text-slate-600 mb-1">Driver Phone</label>
                                <input id="driver_phone" name="driver_phone" x-ref="driverPhone" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="e.g., 08012345678">
                            </div>
                        </div>
                        <div>
                            <label for="delivery_notes" class="block text-xs font-medium text-slate-600 mb-1">Delivery Notes</label>
                            <textarea id="delivery_notes" name="delivery_notes" rows="2" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-sm" placeholder="Any special instructions..."></textarea>
                        </div>
                    </div>
                    <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                        <button type="button" @click="dispatchModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700"><i class="fi fi-rr-truck-side text-xs"></i> Dispatch Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Deliver Modal --}}
    <div x-show="deliverModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="deliverModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="deliverModal = false" aria-hidden="true"></div>
            <div x-show="deliverModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fi fi-rr-box-check text-emerald-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Mark as Delivered</h3><p class="text-sm text-slate-500">Confirm that the customer has received this order.</p></div>
                    </div>
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button @click="deliverModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <form method="POST" action="{{ route('management.orders.deliver', $order) }}">@csrf<button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-box-check text-xs"></i> Mark Delivered</button></form>
                </div>
            </div>
        </div>
    </div>

    {{-- Complete Modal --}}
    <div x-show="completeModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="completeModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="completeModal = false" aria-hidden="true"></div>
            <div x-show="completeModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fi fi-rr-check-circle text-emerald-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Complete Order</h3><p class="text-sm text-slate-500">Finalize this order. No further changes.</p></div>
                    </div>
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button @click="completeModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <form method="POST" action="{{ route('management.orders.complete', $order) }}">@csrf<button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-check-circle text-xs"></i> Complete Order</button></form>
                </div>
            </div>
        </div>
    </div>

    {{-- Cancel Modal --}}
    <div x-show="cancelModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="cancelModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="cancelModal = false" aria-hidden="true"></div>
            <div x-show="cancelModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-cross-small text-red-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Cancel Order</h3><p class="text-sm text-slate-500">This action cannot be undone.</p></div>
                    </div>
                </div>
                <form method="POST" action="{{ route('management.orders.cancel', $order) }}">
                    @csrf
                    <div class="bg-white px-6 py-4 space-y-3">
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                        <div>
                            <label for="cancel-reason" class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label>
                            <textarea id="cancel-reason" name="reason" rows="2" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Customer requested cancellation..."></textarea>
                        </div>
                    </div>
                    <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                        <button type="button" @click="cancelModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Back</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-cross-small text-xs"></i> Cancel Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Return Modal --}}
    <div x-show="returnModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="returnModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="returnModal = false" aria-hidden="true"></div>
            <div x-show="returnModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-undo text-red-600 text-lg"></i></div>
                        <div><h3 class="text-lg font-semibold text-slate-900">Process Return</h3><p class="text-sm text-slate-500">Stock will be restored to inventory.</p></div>
                    </div>
                </div>
                <form method="POST" action="{{ route('management.orders.return', $order) }}">
                    @csrf
                    <div class="bg-white px-6 py-4 space-y-3">
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="font-semibold text-slate-900">{{ $order->order_number }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-slate-500">Items to Restore</span><span class="text-slate-700">{{ $order->items->count() }} item(s)</span></div>
                        <div>
                            <label for="return-reason" class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label>
                            <textarea id="return-reason" name="reason" rows="2" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Damaged on arrival..."></textarea>
                        </div>
                    </div>
                    <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                        <button type="button" @click="returnModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-undo text-xs"></i> Process Return</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
</div>
@endsection
