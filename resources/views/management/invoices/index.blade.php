@extends('management.layout')
@section('subtitle', 'Invoices')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Invoices</h2>
        <p class="text-sm text-slate-500 mt-0.5">Create and manage your invoices</p>
    </div>
    <a href="{{ route('management.invoices.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors shadow-sm">
        <i class="fi fi-rr-plus text-xs"></i> New Invoice
    </a>
</div>

{{-- Stats Row --}}
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
    @php
        $counts = \App\Models\Invoice::where('business_id', $user->business_id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
        $total = $counts->sum();
        $overdue = $counts['overdue'] ?? 0;
        $sent = $counts['sent'] ?? 0;
        $paid = $counts['paid'] ?? 0;
        $draft = $counts['draft'] ?? 0;
        $revenue = \App\Models\Invoice::where('business_id', $user->business_id)
            ->where('status', 'paid')->sum('total');
    @endphp
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">All Invoices</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($total) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Draft</p>
        <p class="text-xl font-bold text-slate-500 mt-1">{{ number_format($draft) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Sent</p>
        <p class="text-xl font-bold text-blue-600 mt-1">{{ number_format($sent) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Overdue</p>
        <p class="text-xl font-bold text-red-600 mt-1">{{ number_format($overdue) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Revenue</p>
        <p class="text-xl font-bold text-emerald-600 mt-1">₦{{ number_format($revenue, 0) }}</p>
    </div>
</div>

{{-- Status Tabs --}}
<div class="flex items-center gap-1 mb-4 p-1 bg-slate-100 rounded-xl w-fit">
    <a href="{{ route('management.invoices.index') }}" class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors {{ !$status ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
        All
    </a>
    @foreach(\App\Enums\InvoiceStatus::cases() as $s)
    <a href="{{ route('management.invoices.index', ['status' => $s->value]) }}" class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors {{ ($status ?? '') === $s->value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
        @if($s->value === 'overdue')<span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500 mr-1.5 align-middle"></span>@endif
        {{ $s->label() }}
    </a>
    @endforeach
</div>

{{-- Search --}}
<form method="GET" class="flex items-center gap-2 mb-4">
    @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search invoices..." autocomplete="off"
        class="flex-1 max-w-sm rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
    @if($q)
    <a href="{{ route('management.invoices.index', $status ? ['status' => $status] : []) }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
    @endif
</form>

{{-- Table --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-100 bg-slate-50/50">
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Invoice</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Customer</th>
                <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Amount</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Due</th>
                <th class="px-5 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider w-20"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($invoices as $invoice)
            <tr class="hover:bg-slate-50/50 transition-colors group">
                <td class="px-5 py-3">
                    <a href="{{ route('management.invoices.show', $invoice) }}" class="text-sm font-semibold text-slate-800 hover:text-blue-600">
                        {{ $invoice->invoice_number }}
                    </a>
                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $invoice->issue_date->format('M d, Y') }}</p>
                </td>
                <td class="px-5 py-3">
                    <span class="text-sm font-medium text-slate-700">{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? '—' }}</span>
                    @if($invoice->recipient_email)<p class="text-[11px] text-slate-400">{{ $invoice->recipient_email }}</p>@endif
                </td>
                <td class="px-5 py-3 text-right">
                    <span class="text-sm font-semibold text-slate-800">₦{{ number_format($invoice->total, 2) }}</span>
                </td>
                <td class="px-5 py-3 text-center">
                    @php $badge = $invoice->status->badgeData()[$invoice->status->value] ?? []; @endphp
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge['class'] ?? 'bg-slate-100 text-slate-700' }}">
                        @if($invoice->status->value === 'overdue')<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>@endif
                        @if($invoice->status->value === 'sent')<span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>@endif
                        @if($invoice->status->value === 'paid')<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>@endif
                        @if($invoice->status->value === 'draft')<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>@endif
                        {{ $invoice->status->label() }}
                    </span>
                </td>
                <td class="px-5 py-3 hidden md:table-cell">
                    <span class="text-sm {{ $invoice->status->value === 'overdue' ? 'text-red-600 font-semibold' : 'text-slate-600' }}">
                        {{ $invoice->due_date->format('M d, Y') }}
                    </span>
                </td>
                <td class="px-5 py-3 text-right">
                    <a href="{{ route('management.invoices.show', $invoice) }}" class="opacity-0 group-hover:opacity-100 inline-flex items-center px-2 py-1 text-xs font-medium text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-all">View</a>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-16">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                        <i class="fi fi-rr-file-invoice-dollar text-2xl text-slate-400"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-slate-800 mb-1">No invoices yet</h3>
                    <p class="text-sm text-slate-400 mb-4">Create your first invoice to start billing customers.</p>
                    <a href="{{ route('management.invoices.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Create Invoice</a>
                </div>
            </td></tr>
            @endforelse
        </tbody>
    </table>
    @if($invoices->hasPages())
    <div class="px-5 py-3 border-t border-slate-100 bg-slate-50/30">
        {{ $invoices->links() }}
    </div>
    @endif
</div>
@endsection
