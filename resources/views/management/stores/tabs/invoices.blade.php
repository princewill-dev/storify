<div class="space-y-4">
    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1 min-w-0" x-on:submit.prevent="fetchTab('invoices', new URLSearchParams(new FormData($el)).toString())">
            <select name="status" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" x-on:change="fetchTab('invoices', new URLSearchParams(new FormData($el.form)).toString())">
                <option value="">All Status</option>
                @foreach(\App\Enums\InvoiceStatus::cases() as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
            <input type="text" name="q" value="{{ request('q', '') }}" placeholder="Search invoices..." class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500 max-w-[180px]">
            <button type="submit" class="px-3 py-2 text-sm font-medium text-white bg-slate-900 rounded-lg hover:bg-slate-800">Filter</button>
            @if(request('status') || request('q'))
            <button type="button" @click="fetchTab('invoices', '')" class="px-3 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Clear</button>
            @endif
        </form>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Invoice</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase">Amount</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden md:table-cell">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($invoices as $invoice)
                <tr class="hover:bg-slate-50/50 transition-colors group">
                    <td class="px-4 py-3">
                        <a href="{{ route('management.invoices.show', $invoice) }}" class="text-sm font-semibold text-slate-800 hover:text-blue-600">
                            {{ $invoice->invoice_number }}
                        </a>
                        <p class="text-[11px] text-slate-400 mt-0.5">{{ $invoice->issue_date->format('M d, Y') }}</p>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="text-sm text-slate-600">{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? '—' }}</span>
                        @if($invoice->recipient_email)
                        <p class="text-[11px] text-slate-400">{{ $invoice->recipient_email }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <span class="text-sm font-semibold text-slate-800">₦{{ number_format($invoice->total, 2) }}</span>
                        @if($invoice->amount_paid > 0 && $invoice->status->value !== 'paid')
                        <p class="text-[11px] text-amber-600">₦{{ number_format($invoice->amount_paid, 2) }} paid</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $invoice->status->badgeClass() }}">
                            @if($invoice->status->value === 'overdue')<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>@endif
                            @if($invoice->status->value === 'sent')<span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>@endif
                            @if($invoice->status->value === 'paid')<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>@endif
                            @if($invoice->status->value === 'partial')<span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>@endif
                            @if($invoice->status->value === 'draft')<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>@endif
                            {{ $invoice->status->label() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-sm {{ $invoice->status->value === 'overdue' ? 'text-red-600 font-semibold' : 'text-slate-600' }}">
                            {{ $invoice->due_date->format('M d, Y') }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-400">No invoices found for this store.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($invoices->hasPages())
        <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
            <span class="text-xs text-slate-400">Showing {{ $invoices->firstItem() }}–{{ $invoices->lastItem() }} of {{ $invoices->total() }}</span>
            <div class="flex items-center gap-1">
                @if($invoices->onFirstPage())
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Previous</span>
                @else
                    <a href="{{ $invoices->previousPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Previous</a>
                @endif
                @if($invoices->hasMorePages())
                    <a href="{{ $invoices->nextPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Next</a>
                @else
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Next</span>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
