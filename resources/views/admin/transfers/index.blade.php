@extends('admin.layout')
@section('subtitle', 'Stock Transfers')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h2 class="text-lg font-bold text-slate-900">Stock Transfers</h2>
        <a href="{{ route('admin.transfers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reset</a>
    </div>
    <div class="p-6">
        <form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
            <select name="status" class="w-full sm:w-auto rounded-lg border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($status ?? '') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
            <input type="text" name="q" value="{{ $q ?? '' }}" class="flex-1 min-w-[200px] rounded-lg border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Code, source, or destination name">
            <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Filter</button>
        </form>

        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">From</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">To</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Items</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Requested By</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($transfers as $t)
                    <tr class="hover:bg-slate-50/50">
                        <td class="py-3 px-4">
                            <a href="{{ route('admin.transfers.show', $t) }}" class="font-mono font-semibold text-slate-700 hover:text-slate-900">{{ $t->transfer_code }}</a>
                        </td>
                        <td class="py-3 px-4 text-slate-600">{{ $t->fromLocation?->name ?? '—' }}</td>
                        <td class="py-3 px-4 text-slate-600">{{ $t->toLocation?->name ?? '—' }}</td>
                        <td class="py-3 px-4"><span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $t->items_count }}</span></td>
                        <td class="py-3 px-4 text-slate-600">{{ $t->requester?->name ?? '—' }}</td>
                        <td class="py-3 px-4">
                            @php
                                $color = match($t->status->value) {
                                    'draft' => 'bg-slate-100 text-slate-600',
                                    'pending' => 'bg-amber-50 text-amber-700',
                                    'approved' => 'bg-sky-50 text-sky-700',
                                    'awaiting_acknowledgment' => 'bg-amber-50 text-amber-700',
                                    'dispatched' => 'bg-blue-50 text-blue-700',
                                    'received' => 'bg-emerald-50 text-emerald-700',
                                    'rejected', 'cancelled' => 'bg-red-50 text-red-700',
                                    default => 'bg-slate-100 text-slate-600',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full {{ $color }} px-2.5 py-0.5 text-xs font-medium">{{ $t->status->label() }}</span>
                        </td>
                        <td class="py-3 px-4 text-xs text-slate-500">{{ $t->created_at->format('d M H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-12 text-center text-slate-400">No transfers found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $transfers->links() }}</div>
    </div>
</div>
@endsection
