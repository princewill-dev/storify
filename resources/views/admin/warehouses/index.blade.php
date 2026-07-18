@extends('admin.layout')
@section('subtitle', 'Warehouses')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Warehouses</h2>
        <p class="text-sm text-slate-500 mt-0.5">All platform warehouses</p>
    </div>
</div>

<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs shadow-sm">
        <option value="">All Statuses</option>
        <option value="active" @selected(($status ?? '') === 'active')>Active</option>
        <option value="inactive" @selected(($status ?? '') === 'inactive')>Inactive</option>
        <option value="deleted" @selected(($status ?? '') === 'deleted')>Deleted</option>
    </select>
    <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Name, code, or business" class="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 flex-1 min-w-[200px]">
    <button type="submit" class="px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800">Filter</button>
    @if($status || ($q ?? ''))
    <a href="{{ route('admin.warehouses.index') }}" class="px-3 py-1.5 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Name</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Code</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Business</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Owner</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">Stock</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">Sections</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($warehouses as $wh)
            <tr class="hover:bg-slate-50/50">
                <td class="px-5 py-3">
                    <a href="{{ route('admin.warehouses.show', $wh) }}" class="text-sm font-medium text-slate-800 hover:text-indigo-600">{{ $wh->name }}</a>
                    @if($wh->address)<p class="text-[11px] text-slate-400 mt-0.5">{{ $wh->address }}</p>@endif
                </td>
                <td class="px-5 py-3"><code class="text-xs text-slate-500">{{ $wh->warehouse_code }}</code></td>
                <td class="px-5 py-3">
                    @if($wh->business)
                    <a href="{{ route('admin.vendors.show', $wh->user) }}" class="text-xs text-indigo-600 hover:underline">{{ $wh->business->name }}</a>
                    @else
                    <span class="text-xs text-slate-400">—</span>
                    @endif
                </td>
                <td class="px-5 py-3 text-xs text-slate-600">{{ $wh->user?->name ?? '—' }}</td>
                <td class="px-5 py-3 text-center text-xs text-slate-600">{{ number_format($wh->stock_locations_count) }}</td>
                <td class="px-5 py-3 text-center text-xs text-slate-600">{{ number_format($wh->sections_count) }}</td>
                <td class="px-5 py-3 text-center">
                    @php $statusEnum = $wh->status instanceof \App\Enums\WarehouseStatus ? $wh->status->value : $wh->status; @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                        {{ $statusEnum === 'active' ? 'bg-emerald-50 text-emerald-700' : ($statusEnum === 'inactive' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                        {{ $statusEnum }}
                    </span>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-400">No warehouses found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-5 py-3 border-t border-slate-100">
        {{ $warehouses->links() }}
    </div>
</div>
@endsection
