@extends('admin.layout')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">
        <span class="text-slate-400 font-normal">Early Access /</span> {{ $earlyPass->code }}
    </h2>
    <a href="{{ route('admin.early-access.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
        <i class="fi fi-rr-arrow-left"></i> Back to List
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
    <div class="p-6">
        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <div>
                <span class="block text-xs text-slate-400 mb-1">Status</span>
                @if($earlyPass->is_active)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium">Active</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-red-50 text-red-700 px-2.5 py-0.5 text-xs font-medium">Inactive</span>
                @endif
            </div>
            <div>
                <span class="block text-xs text-slate-400 mb-1">Usage Count</span>
                <span class="text-lg font-bold text-slate-900">{{ $earlyPass->usages->count() }}</span>
            </div>
            <div>
                <span class="block text-xs text-slate-400 mb-1">Max Uses</span>
                <span class="text-lg font-bold text-slate-900">{{ $earlyPass->max_uses ?? '∞' }}</span>
            </div>
            <div>
                <span class="block text-xs text-slate-400 mb-1">Created At</span>
                <span class="font-medium text-slate-700">{{ $earlyPass->created_at?->format('d M Y') ?? 'N/A' }}</span>
            </div>
            <div>
                <span class="block text-xs text-slate-400 mb-1">Description</span>
                <span class="text-slate-700">{{ $earlyPass->description ?? 'No description' }}</span>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-base font-semibold text-slate-900">Usage History</h3>
    </div>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Vendor</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Store Used On</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Used At</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($earlyPass->usages as $usage)
            <tr>
                <td class="py-3 px-4">
                    @if($usage->vendor)
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-8 h-8 rounded-full bg-slate-100 text-slate-600 text-xs font-bold">
                            {{ substr($usage->vendor->name, 0, 1) }}
                        </div>
                        <div>
                            <a href="{{ route('admin.vendors.show', $usage->vendor) }}" class="text-sm font-medium text-slate-900 hover:text-slate-700">{{ $usage->vendor->name }}</a>
                            <div class="text-xs text-slate-400">{{ $usage->vendor->email }}</div>
                        </div>
                    </div>
                    @else
                        <span class="text-slate-400">Unknown Vendor</span>
                    @endif
                </td>
                <td class="py-3 px-4">
                    @if($usage->store)
                        <a href="{{ route('admin.stores.show', $usage->store) }}" class="font-medium text-slate-900 hover:text-slate-700">{{ $usage->store->name }}</a>
                        <div class="text-xs text-slate-400 select-all">{{ $usage->store->store_id }}</div>
                    @else
                        <span class="text-slate-400">-</span>
                    @endif
                </td>
                <td class="py-3 px-4 text-slate-700">
                    {{ $usage->used_at->format('d M Y, H:i') }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="3" class="py-12 text-center">
                    <i class="fi fi-rr-time-past block text-2xl text-slate-300 mb-2"></i>
                    <span class="text-slate-400">No usages recorded yet.</span>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
