<div class="space-y-4">
    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1 min-w-0" x-on:submit.prevent="fetchTab('transactions', new URLSearchParams(new FormData($el)).toString())">
            <select name="status" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" x-on:change="fetchTab('transactions', new URLSearchParams(new FormData($el.form)).toString())">
                <option value="">All Status</option>
                <option value="confirmed" @selected(request('status') === 'confirmed')>Confirmed</option>
                <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                <option value="refunded" @selected(request('status') === 'refunded')>Refunded</option>
                <option value="refund_pending" @selected(request('status') === 'refund_pending')>Refund Pending</option>
            </select>
            <button type="submit" class="px-3 py-2 text-sm font-medium text-white bg-slate-900 rounded-lg hover:bg-slate-800">Filter</button>
        </form>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Method</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($transactions as $tx)
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-4 py-3">
                        <a href="{{ route('management.transactions.show', $tx) }}" class="text-sm font-mono font-medium text-slate-800 hover:text-blue-600">{{ $tx->reference }}</a>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="text-sm text-slate-600">{{ $tx->order?->customer?->first_name ?? 'Walk-in' }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="text-sm text-slate-600">{{ $tx->paymentMethod?->name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <x-management.status-badge :status="$tx->status instanceof \App\Enums\TransactionStatus ? $tx->status->value : $tx->status" />
                    </td>
                    <td class="px-4 py-3 text-right hidden sm:table-cell">
                        <span class="text-xs text-slate-400">{{ $tx->created_at->format('d M, Y') }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400">No transactions found for this store.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
            <span class="text-xs text-slate-400">Showing {{ $transactions->firstItem() }}–{{ $transactions->lastItem() }} of {{ $transactions->total() }}</span>
            <div class="flex items-center gap-1">
                @if($transactions->onFirstPage())
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Previous</span>
                @else
                    <a href="{{ $transactions->previousPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Previous</a>
                @endif
                @if($transactions->hasMorePages())
                    <a href="{{ $transactions->nextPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Next</a>
                @else
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Next</span>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
