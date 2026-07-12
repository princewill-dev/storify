@extends('management.layout')
@section('subtitle', $transaction->reference)

@section('content')
<div x-data="{ confirmModal: false, rejectModal: false, refundModal: false, slipModal: false }">
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$transaction->reference" subtitle="{{ $transaction->created_at->format('d M Y H:i') }}">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <x-management.status-badge :status="$transaction->status" />

            {{-- Action Buttons --}}
            @if($transaction->status->value === 'pending')
                <button @click="confirmModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors">
                    <i class="fi fi-rr-check text-xs"></i> Confirm
                </button>
                <button @click="rejectModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 transition-colors">
                    <i class="fi fi-rr-cross-small text-xs"></i> Reject
                </button>
                @if($transaction->payment_slip)
                <button @click="slipModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                    <i class="fi fi-rr-document text-xs"></i> View Slip
                </button>
                @endif
            @elseif($transaction->status->value === 'confirmed')
                @if(!in_array(optional($transaction->order)->status?->value, ['delivered', 'completed']))
                <button @click="refundModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-undo text-xs"></i> Refund
                </button>
                @endif
            @endif
        </div>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Main Content --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Transaction Details --}}
        <x-management.card header="Transaction Details">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Amount</span>
                    <p class="text-lg font-bold text-slate-900 mt-0.5">₦{{ number_format($transaction->amount, 2) }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Status</span>
                    <div class="mt-0.5"><x-management.status-badge :status="$transaction->status" /></div>
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Date</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->created_at->format('d M Y, H:i') }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Reference</span>
                    <p class="text-sm font-mono text-slate-700 mt-0.5 truncate">{{ $transaction->reference }}</p>
                </div>
            </div>

            @if($transaction->paid_at)
            <div class="mt-4 pt-4 border-t border-slate-100">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Paid At</span>
                        <p class="text-sm font-medium text-slate-800 mt-0.5">{{ \Carbon\Carbon::parse($transaction->paid_at)->format('d M Y, H:i') }}</p>
                    </div>
                    @if($transaction->balance_updated_at)
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Balance Updated</span>
                        <p class="text-sm font-medium text-slate-800 mt-0.5">{{ \Carbon\Carbon::parse($transaction->balance_updated_at)->format('d M Y, H:i') }}</p>
                    </div>
                    @endif
                </div>
            </div>
            @endif
        </x-management.card>

        {{-- Balance Audit Trail --}}
        @if($transaction->store_balance_before !== null)
        <x-management.card header="Balance Audit Trail">
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-slate-50 rounded-lg p-4 text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Before</span>
                    <p class="text-lg font-bold text-slate-700 mt-1">₦{{ number_format($transaction->store_balance_before / 100, 2) }}</p>
                </div>
                <div class="bg-slate-50 rounded-lg p-4 text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Change</span>
                    @php
                        $change = $transaction->store_balance_after !== null ? $transaction->store_balance_after - $transaction->store_balance_before : 0;
                    @endphp
                    <p class="text-lg font-bold mt-1 {{ $change >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $change >= 0 ? '+' : '' }}₦{{ number_format(abs($change) / 100, 2) }}
                    </p>
                </div>
                <div class="bg-slate-50 rounded-lg p-4 text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">After</span>
                    <p class="text-lg font-bold text-slate-900 mt-1">₦{{ number_format($transaction->store_balance_after / 100, 2) }}</p>
                </div>
            </div>
        </x-management.card>
        @endif

        {{-- Payment Slip --}}
        @if($transaction->payment_slip)
        <x-management.card header="Payment Proof">
            <div class="flex items-center gap-4">
                <div class="flex-shrink-0 w-32 h-24 bg-slate-100 rounded-lg overflow-hidden border border-slate-200 cursor-pointer" @click="slipModal = true">
                    @if(in_array(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                        <img src="{{ Storage::disk('public')->url($transaction->payment_slip) }}" alt="Payment slip" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center">
                            <i class="fi fi-rr-file-pdf text-3xl text-slate-400"></i>
                        </div>
                    @endif
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-800">Payment proof uploaded</p>
                    <p class="text-xs text-slate-400 mt-1">
                        @if($transaction->paid_at)
                            Uploaded {{ \Carbon\Carbon::parse($transaction->paid_at)->diffForHumans() }}
                        @endif
                    </p>
                    <button @click="slipModal = true" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700">
                        <i class="fi fi-rr-zoom-in text-xs"></i> View full size
                    </button>
                </div>
            </div>
        </x-management.card>
        @endif

        {{-- Order Details --}}
        @if($transaction->order)
        <x-management.card header="Order">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <a href="{{ route('management.orders.show', $transaction->order) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">
                        {{ $transaction->order->order_number }}
                    </a>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $transaction->order->items->count() }} item(s) · ₦{{ number_format($transaction->order->total, 2) }}
                        @if($transaction->order->source)
                            · <span class="uppercase">{{ str_replace('_', ' ', $transaction->order->source) }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-management.status-badge :status="$transaction->order->status" />
                    <a href="{{ route('management.orders.show', $transaction->order) }}" class="text-sm text-blue-600 hover:text-blue-700">
                        View Order <i class="fi fi-rr-arrow-right text-xs ml-1"></i>
                    </a>
                </div>
            </div>
        </x-management.card>
        @endif

        {{-- Rejection / Refund Reason --}}
        @php $meta = $transaction->metadata ?? []; @endphp
        @if(!empty($meta['rejection_reason']) || !empty($meta['refund_reason']))
        <x-management.card header="{{ !empty($meta['rejection_reason']) ? 'Rejection' : 'Refund' }} Reason">
            <div class="bg-red-50 border border-red-100 rounded-lg p-4">
                <p class="text-sm text-red-800">{{ $meta['rejection_reason'] ?? $meta['refund_reason'] }}</p>
                @if(!empty($meta['rejected_at']))
                    <p class="text-xs text-red-400 mt-2">Rejected {{ \Carbon\Carbon::parse($meta['rejected_at'])->format('d M Y, H:i') }}</p>
                @endif
                @if(!empty($meta['refunded_at']))
                    <p class="text-xs text-red-400 mt-2">Refunded {{ \Carbon\Carbon::parse($meta['refunded_at'])->format('d M Y, H:i') }}</p>
                @endif
            </div>
        </x-management.card>
        @endif
    </div>

    {{-- Sidebar --}}
    <div class="space-y-6">

        {{-- Payment Info --}}
        <x-management.card header="Payment">
            <div class="space-y-3">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Method</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">
                        @php $pm = $transaction->paymentMethod; @endphp
                        @if($pm)
                            @if($pm->code === 'cash') <span class="inline-flex items-center gap-1"><i class="fi fi-rr-money-bill-wave text-slate-500"></i> Cash</span>
                            @elseif($pm->code === 'bank_transfer') <span class="inline-flex items-center gap-1"><i class="fi fi-rr-bank text-slate-500"></i> Bank Transfer</span>
                            @elseif($pm->code === 'paystack') <span class="inline-flex items-center gap-1"><i class="fi fi-rr-credit-card text-slate-500"></i> Paystack (Card)</span>
                            @else {{ $pm->name }}
                            @endif
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </p>
                </div>

                @if($transaction->gateway_reference)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Gateway Reference</span>
                    <p class="text-sm font-mono font-medium text-slate-700 mt-0.5">{{ $transaction->gateway_reference }}</p>
                </div>
                @endif

                @if($transaction->currency)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Currency</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->currency }}</p>
                </div>
                @endif
            </div>
        </x-management.card>

        {{-- Bank Account --}}
        @if($transaction->storeBank)
        <x-management.card header="Bank Account">
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                        <i class="fi fi-rr-bank text-blue-600"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ $transaction->storeBank->bank_name }}</p>
                        <p class="text-xs text-slate-400">{{ $transaction->storeBank->account_number }}</p>
                    </div>
                    @if($transaction->storeBank->is_verified)
                        <span class="ml-auto inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Verified</span>
                    @endif
                </div>
                <div class="pt-2 border-t border-slate-100">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Account Name</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->storeBank->account_name }}</p>
                </div>
            </div>
        </x-management.card>
        @endif

        {{-- Customer --}}
        @if($transaction->order?->customer || $transaction->order?->meta)
        <x-management.card header="Customer">
            @if($transaction->order->customer && $transaction->order->customer->email !== 'walkin@pos.local')
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center">
                        <span class="text-sm font-semibold text-slate-500">{{ strtoupper(substr($transaction->order->customer->first_name, 0, 1)) }}{{ strtoupper(substr($transaction->order->customer->last_name, 0, 1)) }}</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ $transaction->order->customer->first_name }} {{ $transaction->order->customer->last_name }}</p>
                        <p class="text-xs text-slate-400">{{ $transaction->order->customer->email }}</p>
                        @if($transaction->order->customer->phone)
                            <p class="text-xs text-slate-400">{{ $transaction->order->customer->phone }}</p>
                        @endif
                    </div>
                </div>
            @elseif($transaction->order->meta['customer_name'] ?? false)
                <p class="text-sm font-semibold text-slate-800">{{ $transaction->order->meta['customer_name'] }}</p>
                @if($transaction->order->meta['customer_phone'] ?? false)
                <p class="text-xs text-slate-400">{{ $transaction->order->meta['customer_phone'] }}</p>
                @endif
            @else
                <p class="text-sm text-slate-400">Walk-in customer</p>
            @endif
        </x-management.card>
        @endif

        {{-- Store --}}
        @if($transaction->order?->store)
        <x-management.card header="Store">
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                    <i class="fi fi-rr-shop text-purple-600"></i>
                </div>
                <div>
                    <a href="{{ route('management.stores.show', $transaction->order->store) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">
                        {{ $transaction->order->store->name }}
                    </a>
                    <p class="text-xs text-slate-400">{{ $transaction->order->store->store_id }}</p>
                </div>
            </div>
        </x-management.card>
        @endif
    </div>

    {{-- ═══ MODALS ═══ --}}

    {{-- Confirm Payment Modal --}}
    <div x-show="confirmModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="confirm-modal" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="confirmModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="confirmModal = false" aria-hidden="true"></div>

            <div x-show="confirmModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fi fi-rr-check text-emerald-600 text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Confirm Payment</h3>
                            <p class="text-sm text-slate-500">Verify that the payment has been received.</p>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Amount</span>
                        <span class="font-semibold text-slate-900">₦{{ number_format($transaction->amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Reference</span>
                        <span class="font-mono text-slate-700">{{ $transaction->reference }}</span>
                    </div>
                    @if($transaction->storeBank)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Bank</span>
                        <span class="text-slate-700">{{ $transaction->storeBank->bank_name }} — {{ $transaction->storeBank->account_number }}</span>
                    </div>
                    @endif
                    @if($transaction->order)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Order</span>
                        <span class="text-slate-700">{{ $transaction->order->order_number }}</span>
                    </div>
                    @endif
                </div>
                <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                    <button @click="confirmModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('management.transactions.confirm', $transaction) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors">
                            <i class="fi fi-rr-check text-xs"></i> Confirm Payment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Reject Payment Modal --}}
    <div x-show="rejectModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="reject-modal" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="rejectModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="rejectModal = false" aria-hidden="true"></div>

            <div x-show="rejectModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fi fi-rr-cross-small text-red-600 text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Reject Payment</h3>
                            <p class="text-sm text-slate-500">Let the customer know why their payment was rejected.</p>
                        </div>
                    </div>
                </div>
                <form method="POST" action="{{ route('management.transactions.reject', $transaction) }}">
                    @csrf
                    <div class="bg-white px-6 py-4 space-y-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Amount</span>
                            <span class="font-semibold text-slate-900">₦{{ number_format($transaction->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Reference</span>
                            <span class="font-mono text-slate-700">{{ $transaction->reference }}</span>
                        </div>
                        <div>
                            <label for="reject-reason" class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-slate-400 font-normal">(optional)</span></label>
                            <textarea id="reject-reason" name="reason" rows="3" maxlength="500" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="e.g., Payment amount does not match order total..."></textarea>
                            <p class="text-xs text-slate-400 mt-1">This reason will be included in the rejection email to the customer.</p>
                        </div>
                    </div>
                    <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                        <button type="button" @click="rejectModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 transition-colors">
                            <i class="fi fi-rr-cross-small text-xs"></i> Reject Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Refund Payment Modal --}}
    <div x-show="refundModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="refund-modal" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="refundModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="refundModal = false" aria-hidden="true"></div>

            <div x-show="refundModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <div class="bg-white px-6 py-5 border-b border-slate-200">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fi fi-rr-undo text-red-600 text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Process Refund</h3>
                            <p class="text-sm text-slate-500">This will debit ₦{{ number_format($transaction->amount, 2) }} from the store balance.</p>
                        </div>
                    </div>
                </div>
                <form method="POST" action="{{ route('management.transactions.refund', $transaction) }}">
                    @csrf
                    <div class="bg-white px-6 py-4 space-y-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Amount to Refund</span>
                            <span class="font-semibold text-red-600">₦{{ number_format($transaction->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Current Store Balance</span>
                            <span class="font-semibold text-slate-900">₦{{ number_format($transaction->order?->store?->getBalanceInNaira() ?? 0, 2) }}</span>
                        </div>
                        <div>
                            <label for="refund-reason" class="block text-sm font-medium text-slate-700 mb-1.5">Reason <span class="text-red-500">*</span></label>
                            <textarea id="refund-reason" name="reason" rows="3" maxlength="500" required class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="Explain why this payment is being refunded..."></textarea>
                        </div>
                    </div>
                    <div class="bg-white px-6 py-4 flex items-center justify-end gap-3 border-t border-slate-200">
                        <button type="button" @click="refundModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 transition-colors">
                            <i class="fi fi-rr-undo text-xs"></i> Process Refund
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- View Payment Slip Modal --}}
    @if($transaction->payment_slip)
    <div x-show="slipModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="slip-modal" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
            <div x-show="slipModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/70 transition-opacity" @click="slipModal = false" aria-hidden="true"></div>

            <div x-show="slipModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="relative inline-block transform overflow-hidden rounded-xl bg-white shadow-xl transition-all sm:max-w-3xl sm:w-full">
                <div class="absolute top-4 right-4 z-10">
                    <button @click="slipModal = false" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-white/80 backdrop-blur-sm shadow-sm hover:bg-white transition-colors">
                        <i class="fi fi-rr-cross-small text-slate-600"></i>
                    </button>
                </div>
                <div class="p-2">
                    @if(in_array(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                        <img src="{{ Storage::disk('public')->url($transaction->payment_slip) }}" alt="Payment slip" class="w-full h-auto max-h-[80vh] object-contain rounded-lg">
                    @else
                        <div class="flex flex-col items-center justify-center py-20 px-10">
                            <i class="fi fi-rr-file-pdf text-6xl text-slate-300 mb-4"></i>
                            <p class="text-sm text-slate-600 mb-4">PDF document</p>
                            <a href="{{ Storage::disk('public')->url($transaction->payment_slip) }}" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                                <i class="fi fi-rr-download text-xs"></i> Open Document
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
</div>
@endsection
