@extends('management.layout')
@section('subtitle', $transaction->reference)

@section('content')
<div x-data="{ confirmModal: false, rejectModal: false, refundModal: false, slipModal: false }">
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$transaction->reference" subtitle="{{ $transaction->created_at->format('d M Y H:i') }}">
    <x-slot:actions>
        <x-management.status-badge :status="$transaction->status" />
        @if($transaction->status->value === 'pending')
            <button @click="confirmModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-check text-xs"></i> Confirm</button>
            <button @click="rejectModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-cross-small text-xs"></i> Reject</button>
            @if($transaction->payment_slip)
            <button @click="slipModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"><i class="fi fi-rr-document text-xs"></i> View Slip</button>
            @endif
        @elseif($transaction->status->value === 'confirmed')
            @if(!in_array(optional($transaction->order)->status?->value, ['delivered', 'completed']))
            <button @click="refundModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50"><i class="fi fi-rr-undo text-xs"></i> Refund</button>
            @endif
        @endif
    </x-slot:actions>
</x-management.page-header>

<div class="max-w-3xl">
    {{-- Key Metrics --}}
    <div class="flex items-baseline gap-6 mb-8">
        <div>
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Amount</span>
            <p class="text-3xl font-bold text-slate-900 mt-0.5 tracking-tight">₦{{ number_format($transaction->amount, 2) }}</p>
        </div>
        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest space-x-3">
            <span class="text-slate-300">·</span>
            <span>{{ $transaction->created_at->format('d M Y') }}</span>
            <span>{{ $transaction->created_at->format('H:i') }}</span>
        </div>
        @if($transaction->paid_at)
        <div class="text-xs text-slate-400 flex items-center gap-1.5">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
            Paid {{ \Carbon\Carbon::parse($transaction->paid_at)->format('d M Y, H:i') }}
        </div>
        @endif
    </div>

    {{-- Transaction Record --}}
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Transaction Record</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Reference</td>
                    <td class="px-5 py-3 font-mono text-slate-700">{{ $transaction->reference }}</td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">Status</td>
                    <td class="px-5 py-3"><x-management.status-badge :status="$transaction->status" /></td>
                </tr>
                @if($transaction->gateway_reference)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Gateway Reference</td>
                    <td class="px-5 py-3 font-mono text-slate-600">{{ $transaction->gateway_reference }}</td>
                </tr>
                @endif
                <tr>
                    <td class="px-5 py-3 text-slate-400">Payment Method</td>
                    <td class="px-5 py-3">
                        @php $pm = $transaction->paymentMethod; @endphp
                        @if($pm && $pm->code === 'cash') Cash
                        @elseif($pm && $pm->code === 'bank_transfer') Bank Transfer
                        @elseif($pm && $pm->code === 'paystack') Paystack (Card)
                        @else {{ $pm?->name ?? '—' }}
                        @endif
                    </td>
                </tr>
                @if($transaction->storeBank)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Bank Account</td>
                    <td class="px-5 py-3">
                        <span class="font-medium text-slate-700">{{ $transaction->storeBank->bank_name }}</span>
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="text-slate-500">{{ $transaction->storeBank->account_number }}</span>
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="text-slate-500">{{ $transaction->storeBank->account_name }}</span>
                        @if($transaction->storeBank->is_verified)
                        <span class="ml-2 inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Verified</span>
                        @endif
                    </td>
                </tr>
                @endif
                @if($transaction->currency)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Currency</td>
                    <td class="px-5 py-3">{{ $transaction->currency }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Order Record --}}
    @if($transaction->order)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Associated Order</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Order Number</td>
                    <td class="px-5 py-3">
                        <a href="{{ route('management.orders.show', $transaction->order) }}" class="font-medium text-blue-600 hover:text-blue-700">
                            {{ $transaction->order->order_number }}
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">Status</td>
                    <td class="px-5 py-3"><x-management.status-badge :status="$transaction->order->status" /></td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">Items</td>
                    <td class="px-5 py-3 text-slate-600">{{ $transaction->order->items->count() }} item(s)</td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">Subtotal</td>
                    <td class="px-5 py-3 text-slate-600">₦{{ number_format($transaction->order->subtotal, 2) }}</td>
                </tr>
                @php
                    $scAmount = $transaction->order->service_charge_amount
                        ?? ($transaction->order->meta['service_charge_amount'] ?? null)
                        ?? (($transaction->order->total - $transaction->order->subtotal - $transaction->order->shipping_fee - $transaction->order->tax > 0) ? $transaction->order->total - $transaction->order->subtotal - $transaction->order->shipping_fee - $transaction->order->tax : null);
                @endphp
                @if($scAmount > 0)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Service Charge{{ $transaction->order->meta['service_charge_name'] ?? '' ? ' (' . $transaction->order->meta['service_charge_name'] . ')' : '' }}</td>
                    <td class="px-5 py-3 text-slate-600">₦{{ number_format($scAmount, 2) }}</td>
                </tr>
                @endif
                <tr class="font-semibold">
                    <td class="px-5 py-3 text-slate-600">Total</td>
                    <td class="px-5 py-3 text-slate-900">₦{{ number_format($transaction->order->total, 2) }}</td>
                </tr>
                @if($transaction->order->source)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Source</td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 uppercase">{{ str_replace('_', ' ', $transaction->order->source) }}</span>
                    </td>
                </tr>
                @endif
                @if($transaction->order?->staff)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Processed By</td>
                    <td class="px-5 py-3 text-slate-600">{{ $transaction->order->staff->name }} <span class="text-slate-400 ml-1">({{ $transaction->order->staff->email }})</span></td>
                </tr>
                @endif
                @if($transaction->order?->customer && !str_contains($transaction->order->customer->email ?? '', 'walkin'))
                <tr>
                    <td class="px-5 py-3 text-slate-400">Customer</td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $transaction->order->customer->first_name }} {{ $transaction->order->customer->last_name }}
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="text-slate-400">{{ $transaction->order->customer->email ?? '—' }}</span>
                        @if($transaction->order->customer->phone)
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="text-slate-400">{{ $transaction->order->customer->phone }}</span>
                        @endif
                    </td>
                </tr>
                @elseif($transaction->order?->meta['customer_name'] ?? false)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Customer</td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $transaction->order->meta['customer_name'] }}
                        @if($transaction->order->meta['customer_phone'] ?? false)
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="text-slate-400">{{ $transaction->order->meta['customer_phone'] }}</span>
                        @endif
                    </td>
                </tr>
                @endif
                @if($transaction->order?->store)
                <tr>
                    <td class="px-5 py-3 text-slate-400">Store</td>
                    <td class="px-5 py-3">
                        <a href="{{ route('management.stores.show', $transaction->order->store) }}" class="text-blue-600 hover:text-blue-700">
                            {{ $transaction->order->store->name }}
                        </a>
                        <span class="text-xs text-slate-400 ml-1.5">{{ $transaction->order->store->store_id }}</span>
                    </td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>
    @endif

    {{-- Balance Impact --}}
    @if($transaction->store_balance_before !== null)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Store Balance Impact</h3>
        </div>
        @php $change = $transaction->store_balance_after !== null ? $transaction->store_balance_after - $transaction->store_balance_before : 0; @endphp
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-5 py-3 text-slate-400 w-48">Before</td>
                    <td class="px-5 py-3 text-slate-600">₦{{ number_format($transaction->store_balance_before / 100, 2) }}</td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">Change</td>
                    <td class="px-5 py-3 font-semibold {{ $change >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $change >= 0 ? '+' : '' }}₦{{ number_format(abs($change) / 100, 2) }}
                    </td>
                </tr>
                <tr>
                    <td class="px-5 py-3 text-slate-400">After</td>
                    <td class="px-5 py-3 font-semibold text-slate-800">₦{{ number_format($transaction->store_balance_after / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif

    {{-- Payment Proof --}}
    @if($transaction->payment_slip)
    <div class="border border-slate-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Payment Proof</h3>
            <button @click="slipModal = true" class="text-xs text-blue-600 hover:text-blue-700 font-medium">View Full Size →</button>
        </div>
        <div class="p-4 flex items-center gap-4">
            <div class="w-24 h-16 bg-slate-100 rounded-lg overflow-hidden border border-slate-200 cursor-pointer shrink-0" @click="slipModal = true">
                @if(in_array(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                    <img src="{{ Storage::disk('public')->url($transaction->payment_slip) }}" alt="Slip" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center"><i class="fi fi-rr-file-pdf text-xl text-slate-400"></i></div>
                @endif
            </div>
            <p class="text-xs text-slate-400">Uploaded {{ $transaction->paid_at ? \Carbon\Carbon::parse($transaction->paid_at)->diffForHumans() : '—' }}</p>
        </div>
    </div>
    @endif

    {{-- Rejection / Refund Reason --}}
    @php $meta = $transaction->metadata ?? []; @endphp
    @if(!empty($meta['rejection_reason']) || !empty($meta['refund_reason']))
    <div class="border border-red-200 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 bg-red-50 border-b border-red-100">
            <h3 class="text-xs font-bold text-red-500 uppercase tracking-widest">{{ !empty($meta['rejection_reason']) ? 'Rejection' : 'Refund' }} Reason</h3>
        </div>
        <div class="p-4">
            <p class="text-sm text-red-800">{{ $meta['rejection_reason'] ?? $meta['refund_reason'] }}</p>
            @if(!empty($meta['rejected_at']))
            <p class="text-xs text-red-400 mt-2">Rejected {{ \Carbon\Carbon::parse($meta['rejected_at'])->format('d M Y, H:i') }}</p>
            @endif
            @if(!empty($meta['refunded_at']))
            <p class="text-xs text-red-400 mt-2">Refunded {{ \Carbon\Carbon::parse($meta['refunded_at'])->format('d M Y, H:i') }}</p>
            @endif
        </div>
    </div>
    @endif
</div>

{{-- Modals --}}
{{-- Confirm --}}
<div x-show="confirmModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="confirmModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="confirmModal = false"></div>
        <div x-show="confirmModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fi fi-rr-check text-emerald-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Confirm Payment</h3><p class="text-sm text-slate-500">Verify that the payment has been received.</p></div></div>
            </div>
            <div class="bg-slate-50 px-6 py-4 space-y-3">
                <div class="flex justify-between text-sm"><span class="text-slate-500">Amount</span><span class="font-semibold text-slate-900">₦{{ number_format($transaction->amount, 2) }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-500">Reference</span><span class="font-mono text-slate-700">{{ $transaction->reference }}</span></div>
                @if($transaction->storeBank)
                <div class="flex justify-between text-sm"><span class="text-slate-500">Bank</span><span class="text-slate-700">{{ $transaction->storeBank->bank_name }} — {{ $transaction->storeBank->account_number }}</span></div>
                @endif
                @if($transaction->order)
                <div class="flex justify-between text-sm"><span class="text-slate-500">Order</span><span class="text-slate-700">{{ $transaction->order->order_number }}</span></div>
                @endif
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                <button @click="confirmModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                <form method="POST" action="{{ route('management.transactions.confirm', $transaction) }}">@csrf<button class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"><i class="fi fi-rr-check text-xs"></i> Confirm</button></form>
            </div>
        </div>
    </div>
</div>

{{-- Reject --}}
<div x-show="rejectModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="rejectModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="rejectModal = false"></div>
        <div x-show="rejectModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-cross-small text-red-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Reject Payment</h3><p class="text-sm text-slate-500">Let the customer know why.</p></div></div>
            </div>
            <form method="POST" action="{{ route('management.transactions.reject', $transaction) }}">@csrf
                <div class="px-6 py-4 space-y-4">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Amount</span><span class="font-semibold text-slate-900">₦{{ number_format($transaction->amount, 2) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Reference</span><span class="font-mono text-slate-700">{{ $transaction->reference }}</span></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label><textarea name="reason" rows="3" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Payment amount does not match order total..."></textarea></div>
                </div>
                <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button type="button" @click="rejectModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-cross-small text-xs"></i> Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Refund --}}
<div x-show="refundModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="refundModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="refundModal = false"></div>
        <div x-show="refundModal" x-transition class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
            <div class="px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fi fi-rr-undo text-red-600"></i></div><div><h3 class="text-lg font-semibold text-slate-900">Process Refund</h3><p class="text-sm text-slate-500">This will debit ₦{{ number_format($transaction->amount, 2) }} from the store balance.</p></div></div>
            </div>
            <form method="POST" action="{{ route('management.transactions.refund', $transaction) }}">@csrf
                <div class="px-6 py-4 space-y-4">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Amount to Refund</span><span class="font-semibold text-red-600">₦{{ number_format($transaction->amount, 2) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Current Store Balance</span><span class="font-semibold text-slate-900">₦{{ number_format($transaction->order?->store?->getBalanceInNaira() ?? 0, 2) }}</span></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-red-500">*</span></label><textarea name="reason" rows="3" maxlength="500" required class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="Explain why..."></textarea></div>
                </div>
                <div class="px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button type="button" @click="refundModal = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"><i class="fi fi-rr-undo text-xs"></i> Process Refund</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Slip --}}
@if($transaction->payment_slip)
<div x-show="slipModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="slipModal" x-transition class="fixed inset-0 bg-slate-900/70" @click="slipModal = false"></div>
        <div x-show="slipModal" x-transition class="relative inline-block transform overflow-hidden rounded-xl bg-white shadow-xl transition-all sm:max-w-3xl sm:w-full">
            <div class="absolute top-4 right-4 z-10"><button @click="slipModal = false" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-white/80 backdrop-blur-sm shadow-sm hover:bg-white"><i class="fi fi-rr-cross-small text-slate-600"></i></button></div>
            <div class="p-2">
                @if(in_array(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                    <img src="{{ Storage::disk('public')->url($transaction->payment_slip) }}" alt="Payment slip" class="w-full h-auto max-h-[80vh] object-contain rounded-lg">
                @else
                    <div class="flex flex-col items-center justify-center py-20 px-10"><i class="fi fi-rr-file-pdf text-6xl text-slate-300 mb-4"></i><p class="text-sm text-slate-600 mb-4">PDF document</p><a href="{{ Storage::disk('public')->url($transaction->payment_slip) }}" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-download text-xs"></i> Open Document</a></div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

</div>
@endsection
