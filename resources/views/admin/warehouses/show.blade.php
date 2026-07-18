@extends('admin.layout')
@section('subtitle', $warehouse->name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">{{ $warehouse->name }}</h2>
        <div class="flex items-center gap-2 mt-1">
            <code class="text-xs text-slate-400 font-mono">{{ $warehouse->warehouse_code }}</code>
            @php $statusVal = $warehouse->status instanceof \App\Enums\WarehouseStatus ? $warehouse->status->value : $warehouse->status; @endphp
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium
                {{ $statusVal === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                {{ $statusVal }}
            </span>
            @if($warehouse->address)<span class="text-xs text-slate-400">· {{ $warehouse->address }}</span>@endif
        </div>
    </div>
    <a href="{{ route('admin.warehouses.index') }}" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-50">← Back</a>
</div>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-center">
        <p class="text-lg font-bold text-slate-900">{{ number_format($totalStock) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase">Total Stock</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-center">
        <p class="text-lg font-bold text-amber-600">{{ number_format($lowStockCount) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase">Low Stock (≤10)</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-center">
        <p class="text-lg font-bold text-slate-900">{{ number_format($warehouse->stock_locations_count) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase">Stock Items</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-center">
        <p class="text-lg font-bold text-slate-900">{{ number_format($warehouse->sections_count) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase">Sections</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Business & Owner</h3></div>
        <div class="px-5 py-4">
            @if($warehouse->business)
            <div class="space-y-2 text-sm">
                <div class="flex gap-3">
                    <span class="text-slate-400 w-20 shrink-0">Business</span>
                    <a href="{{ route('admin.vendors.show', $warehouse->user) }}" class="text-indigo-600 hover:underline font-medium">{{ $warehouse->business->name }}</a>
                    <span class="text-xs text-slate-400 font-mono">{{ $warehouse->business->business_code }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="text-slate-400 w-20 shrink-0">Owner</span>
                    <span class="text-slate-700">{{ $warehouse->user?->name ?? '—' }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="text-slate-400 w-20 shrink-0">Email</span>
                    <span class="text-slate-700">{{ $warehouse->user?->email ?? '—' }}</span>
                </div>
            </div>
            @else
            <p class="text-sm text-slate-400">No business assigned</p>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Details</h3></div>
        <div class="px-5 py-4">
            <div class="space-y-2 text-sm">
                <div class="flex gap-3">
                    <span class="text-slate-400 w-24 shrink-0">City</span>
                    <span class="text-slate-700">{{ $warehouse->city ?? '—' }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="text-slate-400 w-24 shrink-0">State</span>
                    <span class="text-slate-700">{{ $warehouse->state ?? '—' }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="text-slate-400 w-24 shrink-0">Contact</span>
                    <span class="text-slate-700">{{ $warehouse->contact_person ?? '—' }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="text-slate-400 w-24 shrink-0">Phone</span>
                    <span class="text-slate-700">{{ $warehouse->contact_phone ?? '—' }}</span>
                </div>
                @if($warehouse->description)
                <div class="flex gap-3">
                    <span class="text-slate-400 w-24 shrink-0">Description</span>
                    <span class="text-slate-700">{{ $warehouse->description }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Sections --}}
@if($warehouse->sections->isNotEmpty())
<div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
    <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Sections</h3></div>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-50">
            <tr>
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Name</th>
                <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Stock Items</th>
                <th class="px-5 py-2.5 text-center text-[11px] font-semibold text-slate-400 uppercase">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @foreach($warehouse->sections as $section)
            <tr class="hover:bg-slate-50/50">
                <td class="px-5 py-3 text-xs font-medium text-slate-800">{{ $section->name }}</td>
                <td class="px-5 py-3 text-right text-xs text-slate-600">{{ number_format($section->stock_locations_count) }}</td>
                <td class="px-5 py-3 text-center">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium
                        {{ $section->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $section->status ?? 'active' }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Recent Stock Movements --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Recent Stock Movements</h3></div>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-50">
            <tr>
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Product</th>
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Type</th>
                <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Qty</th>
                <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Date</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($recentMovements as $movement)
            <tr class="hover:bg-slate-50/50">
                <td class="px-5 py-3 text-xs text-slate-700">{{ $movement->product?->name ?? '—' }}</td>
                <td class="px-5 py-3">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium
                        {{ $movement->type === 'added' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                        {{ ucfirst($movement->type) }}
                    </span>
                </td>
                <td class="px-5 py-3 text-right text-xs font-semibold {{ $movement->type === 'added' ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $movement->type === 'added' ? '+' : '−' }}{{ number_format($movement->quantity) }}
                </td>
                <td class="px-5 py-3 text-right text-[11px] text-slate-400">{{ $movement->created_at->format('M d, H:i') }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-slate-400">No recent movements</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
</div>
@endsection
