@extends('management.layout')
@section('subtitle', 'Invoice ' . $invoice->invoice_number)

@section('content')
{{-- Header --}}
<div class="flex items-start justify-between mb-6">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <h2 class="text-lg font-bold text-slate-900">{{ $invoice->invoice_number }}</h2>
            @php $badge = $invoice->status->badgeData()[$invoice->status->value] ?? []; @endphp
            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge['class'] ?? 'bg-slate-100 text-slate-700' }}">
                @if($invoice->status->value === 'overdue')<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>@endif
                @if($invoice->status->value === 'sent')<span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>@endif
                @if($invoice->status->value === 'paid')<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>@endif
                {{ $invoice->status->label() }}
            </span>
        </div>
        <p class="text-sm text-slate-500">Created {{ $invoice->created_at->format('M d, Y') }}</p>
    </div>
    <div class="flex items-center gap-2">
        @if($invoice->isDraft())
        <a href="{{ route('management.invoices.edit', $invoice) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">Edit</a>
        <form method="POST" action="{{ route('management.invoices.send', $invoice) }}" class="inline">@csrf
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">Send Invoice</button>
        </form>
        @endif
        @if(in_array($invoice->status->value, ['sent', 'overdue', 'partial']))
        <button onclick="openRecordPaymentModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-amber-600 text-white text-sm font-semibold rounded-xl hover:bg-amber-700 transition-colors shadow-sm">Record Payment</button>
        <form method="POST" action="{{ route('management.invoices.mark-paid', $invoice) }}" class="inline">@csrf
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-xl hover:bg-emerald-700 transition-colors shadow-sm">Mark Fully Paid</button>
        </form>
        <form method="POST" action="{{ route('management.invoices.send', $invoice) }}" class="inline">@csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">Remind</button>
        </form>
        <form method="POST" action="{{ route('management.invoices.void', $invoice) }}" class="inline" x-data @submit.prevent="Alpine.confirm('Void this invoice?', $event)">@csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-red-200 text-red-600 hover:bg-red-50 transition-colors">Void</button>
        </form>
        @endif
        <a href="{{ route('management.invoices.pdf', $invoice) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> PDF
        </a>
        @if($invoice->payment_token)
        <button onclick="copyInvoiceLink(this)" data-link="{{ route('invoice.pay.show', ['token' => $invoice->payment_token]) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Copy Link
        </button>
        @endif
        <a href="{{ route('management.invoices.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">← Back</a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
    {{-- Invoice Document --}}
    <div class="lg:col-span-3">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            {{-- Top Bar --}}
            <div class="bg-slate-900 px-8 py-10 text-white">
                <div class="flex justify-between items-start">
                    <div>
                        @if($invoice->store?->logo_path)
                        <img src="{{ asset('storage/' . $invoice->store->logo_path) }}" class="h-10 mb-4 brightness-0 invert">
                        @endif
                        <h2 class="text-lg font-bold opacity-90">{{ $invoice->store?->name ?? config('app.name') }}</h2>
                        <p class="text-sm opacity-50 mt-1">{{ $invoice->store?->address ?? '' }}</p>
                    </div>
                    <div class="text-right">
                        <h1 class="text-3xl font-bold tracking-tight">INVOICE</h1>
                        <p class="text-sm opacity-50 mt-2 font-mono">{{ $invoice->invoice_number }}</p>
                    </div>
                </div>
            </div>

            {{-- Bill To & Dates --}}
            <div class="px-8 py-6 border-b border-slate-100">
                <div class="grid grid-cols-2 gap-8">
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Bill To</p>
                        <p class="text-sm font-semibold text-slate-800">{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? '—' }}</p>
                        @if($invoice->recipient_email)<p class="text-sm text-slate-500">{{ $invoice->recipient_email }}</p>@endif
                        @if($invoice->recipient_phone)<p class="text-sm text-slate-500">{{ $invoice->recipient_phone }}</p>@endif
                        @if($invoice->recipient_address)<p class="text-sm text-slate-500 mt-1">{{ $invoice->recipient_address }}</p>@endif
                    </div>
                    <div class="text-right space-y-3">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Issue Date</p>
                            <p class="text-sm font-medium text-slate-700">{{ $invoice->issue_date->format('M d, Y') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Due Date</p>
                            <p class="text-sm font-semibold {{ $invoice->status->value === 'overdue' ? 'text-red-600' : 'text-slate-800' }}">{{ $invoice->due_date->format('M d, Y') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Items Table --}}
            <div class="px-8 py-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="pb-3 text-left text-[10px] font-bold text-slate-400 uppercase tracking-widest">Description</th>
                            <th class="pb-3 text-center text-[10px] font-bold text-slate-400 uppercase tracking-widest w-16">Qty</th>
                            <th class="pb-3 text-right text-[10px] font-bold text-slate-400 uppercase tracking-widest w-28">Rate</th>
                            <th class="pb-3 text-right text-[10px] font-bold text-slate-400 uppercase tracking-widest w-28">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->items as $item)
                        <tr class="border-b border-slate-50">
                            <td class="py-3 text-slate-700">{{ $item->description }}</td>
                            <td class="py-3 text-center text-slate-600">{{ $item->quantity }}</td>
                            <td class="py-3 text-right text-slate-600">₦{{ number_format($item->unit_price, 2) }}</td>
                            <td class="py-3 text-right font-semibold text-slate-800">₦{{ number_format($item->amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Totals --}}
                <div class="flex justify-end mt-4">
                    <div class="w-56 space-y-1.5">
                        <div class="flex justify-between text-sm"><span class="text-slate-400">Subtotal</span><span class="text-slate-600">₦{{ number_format($invoice->subtotal, 2) }}</span></div>
                        @if($invoice->tax_rate > 0)
                        <div class="flex justify-between text-sm"><span class="text-slate-400">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</span><span class="text-slate-600">₦{{ number_format($invoice->tax_amount, 2) }}</span></div>
                        @endif
                        @if($invoice->discount_value > 0)
                        <div class="flex justify-between text-sm"><span class="text-slate-400">Discount</span><span class="text-red-500">−₦{{ number_format($invoice->discount_value, 2) }}</span></div>
                        @endif
                        <div class="flex justify-between text-base font-bold border-t-2 border-slate-200 pt-2 mt-2"><span class="text-slate-900">Total</span><span class="text-slate-900">₦{{ number_format($invoice->total, 2) }}</span></div>
                    </div>
                </div>
            </div>

            {{-- Terms --}}
            @if($invoice->terms)
            <div class="px-8 py-4 bg-slate-50 border-t border-slate-100">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Terms & Conditions</p>
                <p class="text-xs text-slate-500 leading-relaxed">{{ $invoice->terms }}</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="lg:col-span-2 space-y-4">
        {{-- Status Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Invoice Details</p>
            <div class="space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-400">Status</span>
                    <span class="font-semibold {{ $invoice->status->value === 'paid' ? 'text-emerald-600' : ($invoice->status->value === 'overdue' ? 'text-red-600' : 'text-slate-700') }}">{{ $invoice->status->label() }}</span>
                </div>
                <div class="flex justify-between text-sm"><span class="text-slate-400">Number</span><span class="font-mono text-slate-700">{{ $invoice->invoice_number }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-400">Issued</span><span class="text-slate-700">{{ $invoice->issue_date->format('M d, Y') }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-slate-400">Due</span><span class="font-semibold text-slate-700">{{ $invoice->due_date->format('M d, Y') }}</span></div>
                @if($invoice->store)
                <div class="flex justify-between text-sm"><span class="text-slate-400">Store</span><span class="text-slate-700">{{ $invoice->store->name }}</span></div>
                @endif
                @if($invoice->paid_at)
                <div class="flex justify-between text-sm pt-2 border-t border-slate-100"><span class="text-slate-400">Paid</span><span class="font-semibold text-emerald-600">{{ $invoice->paid_at->format('M d, Y') }}</span></div>
                @endif
                @if($invoice->sent_at)
                <div class="flex justify-between text-sm"><span class="text-slate-400">Sent</span><span class="text-slate-500">{{ $invoice->sent_at->format('M d, Y H:i') }}</span></div>
                @endif
            </div>
        </div>

        {{-- Actions Card --}}
        @if($invoice->notes)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Notes</p>
            <p class="text-sm text-slate-600 leading-relaxed">{{ $invoice->notes }}</p>
        </div>
        @endif

        {{-- Customer Info --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Customer</p>
            <p class="text-sm font-semibold text-slate-800">{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? 'No recipient' }}</p>
            @if($invoice->recipient_email)<p class="text-sm text-slate-500 mt-1">{{ $invoice->recipient_email }}</p>@endif
            @if($invoice->recipient_phone)<p class="text-sm text-slate-500">{{ $invoice->recipient_phone }}</p>@endif
        </div>

        {{-- Payment History --}}
        @if($invoice->transactions->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Payments</p>
            <div class="space-y-2.5">
                @foreach($invoice->transactions->where('status', '!=', 'pending') as $tx)
                <div class="flex items-center justify-between text-sm">
                    <div class="min-w-0">
                        <p class="text-xs font-mono text-slate-500 truncate">{{ \Illuminate\Support\Str::limit($tx->reference, 14) }}</p>
                        <p class="text-[10px] text-slate-400">{{ $tx->created_at->format('M d, Y') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold {{ $tx->status->value === 'confirmed' ? 'text-emerald-600' : 'text-red-500' }}">
                            {{ $tx->status->value === 'confirmed' ? '+' : '' }}₦{{ number_format($tx->amount, 2) }}
                        </p>
                        <p class="text-[10px] font-medium {{ $tx->status->value === 'confirmed' ? 'text-emerald-500' : 'text-red-400' }}">
                            {{ $tx->status->value === 'confirmed' ? 'Paid' : $tx->status->label() }}
                        </p>
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex justify-between text-xs font-semibold">
                <span class="text-slate-400">Total Paid</span>
                <span class="text-slate-800">₦{{ number_format($invoice->amount_paid, 2) }} of ₦{{ number_format($invoice->total, 2) }}</span>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection

@push('modals')
{{-- Record Payment Modal --}}
@if(in_array($invoice->status->value, ['sent', 'overdue', 'partial']))
<div id="recordPaymentModal" class="hidden fixed inset-0 z-50 overflow-y-auto" x-data="{ submitting: false }">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeRecordPaymentModal()"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-900">Record Payment</h3>
                <button onclick="closeRecordPaymentModal()" class="text-slate-400 hover:text-slate-600">
                    <i class="fi fi-rr-cross-small text-lg"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('management.invoices.record-payment', $invoice) }}" @submit="submitting = true" class="space-y-4">
                @csrf

                <div class="p-3 bg-slate-50 rounded-xl">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-400">Invoice Total</span>
                        <span class="font-semibold text-slate-800">₦{{ number_format($invoice->total, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mt-1">
                        <span class="text-slate-400">Already Paid</span>
                        <span class="font-semibold text-emerald-600">₦{{ number_format($invoice->amount_paid, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mt-1 pt-1 border-t border-slate-200">
                        <span class="text-slate-600 font-medium">Remaining</span>
                        <span class="font-bold text-amber-600">₦{{ number_format($invoice->remainingBalance(), 2) }}</span>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount Received <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 text-sm font-semibold">₦</span>
                        <input type="number" name="amount" id="rpAmount" value="{{ number_format($invoice->remainingBalance(), 2, '.', '') }}" step="0.01" min="0.01" max="{{ $invoice->remainingBalance() }}" required
                            class="block w-full rounded-xl border-slate-300 pl-7 pr-4 py-3 text-lg font-bold shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Max: ₦{{ number_format($invoice->remainingBalance(), 2) }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Payment Method <span class="text-red-500">*</span></label>
                    <select name="payment_method" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                        <option value="" disabled>Select method...</option>
                        <option value="gateway">Payment Gateway</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="check">Cheque</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Note (optional)</label>
                    <input type="text" name="note" class="block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="e.g. Customer paid via USSD">
                </div>

                <hr class="border-slate-100">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Your Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required autocomplete="current-password"
                        class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="Enter your password to confirm">
                    <p class="text-xs text-slate-400 mt-1">Required for security verification.</p>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeRecordPaymentModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" :disabled="submitting" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors disabled:opacity-60" x-text="submitting ? 'Recording...' : 'Record Payment'"></button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endpush

@push('scripts')
<script>
function openRecordPaymentModal() {
    document.getElementById('recordPaymentModal').classList.remove('hidden');
}
function closeRecordPaymentModal() {
    document.getElementById('recordPaymentModal').classList.add('hidden');
}
function copyInvoiceLink(btn) {
    const link = btn.dataset.link;
    navigator.clipboard.writeText(link).then(() => {
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Copied';
        btn.classList.add('text-emerald-600', 'border-emerald-200');
        setTimeout(() => {
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Copy Link';
            btn.classList.remove('text-emerald-600', 'border-emerald-200');
        }, 2000);
    });
}
</script>
@endpush
