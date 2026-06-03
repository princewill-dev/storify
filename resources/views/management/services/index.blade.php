@extends('management.layout')
@section('subtitle', 'Services')

@section('content')
<x-management.page-header title="Services" subtitle="Manage service offerings tied to your stores">
    <x-slot:actions>
        @can('products create')
        <a href="{{ route('management.services.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Service
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

@if(session('warning'))
<div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 mb-4 flex items-start gap-3">
    <i class="fi fi-rr-info mt-0.5"></i>
    <div>
        <p class="font-medium">{{ session('warning') }}</p>
        <a href="{{ route('management.stores.create') }}" class="text-amber-800 underline font-medium mt-1 inline-block">Create a Store</a>
    </div>
</div>
@endif

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 mb-4">{{ session('success') }}</div>
@endif

<x-management.card>
    <x-management.data-table>
        <x-slot:header>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Service</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Store</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Price</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider w-16"></th>
        </x-slot:header>
        @forelse($services as $service)
        <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3">
                <div class="flex items-center gap-3">
                    @php $img = $service->primaryImage(); @endphp
                    <div class="w-9 h-9 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
                        @if($img && $img->path)
                        <img src="{{ asset('storage/' . $img->path) }}" alt="" class="w-full h-full object-cover">
                        @else
                        <i class="fi fi-rr-hand-holding-heart text-slate-300 text-sm"></i>
                        @endif
                    </div>
                    <span class="text-sm font-medium text-slate-800">{{ $service->name }}</span>
                </div>
            </td>
            <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-500">{{ $service->store?->name ?? '—' }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($service->amount, 2) }}</span></td>
            <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$service->status" /></td>
            <td class="px-5 py-3 text-center">
                <x-management.action-menu>
                    <a href="{{ route('management.services.edit', $service) }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50"><i class="fi fi-rr-edit w-4"></i> Edit</a>
                    <button onclick="event.preventDefault(); if(confirm('Delete this service?')) document.getElementById('del-svc-{{ $service->id }}').submit();" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i> Delete</button>
                    <form id="del-svc-{{ $service->id }}" action="{{ route('management.services.destroy', $service) }}" method="POST" class="hidden">@csrf @method('DELETE')</form>
                </x-management.action-menu>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-hand-holding-heart" title="No services yet" description="Create services tied to your stores — perfect for consulting, maintenance, or any unquantifiable offering." action-label="Add Service" action-url="{{ route('management.services.create') }}" />
        </td></tr>
        @endforelse
    </x-management.data-table>
</x-management.card>
@endsection
