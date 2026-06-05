<div class="space-y-4">
    <p class="text-sm text-slate-500">{{ $customers->total() }} customer(s) who purchased from this store</p>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Phone</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Orders</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Since</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($customers as $customer)
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-4 py-3">
                        <a href="{{ route('management.customers.show', $customer) }}" class="flex items-center gap-3 group">
                            <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-bold shrink-0">
                                {{ strtoupper(substr($customer->first_name, 0, 1)) }}{{ strtoupper(substr($customer->last_name, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 group-hover:text-blue-600">{{ $customer->full_name }}</p>
                                <p class="text-xs text-slate-400">{{ $customer->email }}</p>
                            </div>
                        </a>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="text-sm text-slate-600">{{ $customer->phone ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-sm font-semibold text-slate-800">{{ $customer->orders_count }}</span>
                    </td>
                    <td class="px-4 py-3 text-center hidden sm:table-cell">
                        <x-management.status-badge :status="$customer->status" />
                    </td>
                    <td class="px-4 py-3 text-right hidden sm:table-cell">
                        <span class="text-xs text-slate-400">{{ $customer->created_at->format('d M, Y') }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-400">No customers found for this store.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($customers->hasPages())
        <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
            <span class="text-xs text-slate-400">Showing {{ $customers->firstItem() }}–{{ $customers->lastItem() }} of {{ $customers->total() }}</span>
            <div class="flex items-center gap-1">
                @if($customers->onFirstPage())
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Previous</span>
                @else
                    <a href="{{ $customers->previousPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Previous</a>
                @endif
                @if($customers->hasMorePages())
                    <a href="{{ $customers->nextPageUrl() }}" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-50">Next</a>
                @else
                    <span class="px-3 py-1.5 text-xs text-slate-300 bg-slate-100 rounded-md cursor-not-allowed">Next</span>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
