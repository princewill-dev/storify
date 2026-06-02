@extends('management.layout')
@section('subtitle', 'Services')

@section('content')
<x-management.page-header title="Services" subtitle="Manage digital products and services">
    <x-slot:actions>
        <a href="{{ route('management.services.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Service
        </a>
    </x-slot:actions>
</x-management.page-header>

<x-management.card>
    <x-management.data-table>
        <x-slot:header>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Service</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Price</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider w-16"></th>
        </x-slot:header>
        @forelse($services as $service)
        <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3"><span class="text-sm font-medium text-slate-800">{{ $service->name }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($service->amount, 2) }}</span></td>
            <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$service->status" /></td>
            <td class="px-5 py-3 text-center">
                <x-management.action-menu align="left">
                    <a href="{{ route('management.services.edit', $service) }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50"><i class="fi fi-rr-edit w-4"></i> Edit</a>
                    <button onclick="event.preventDefault(); if(confirm('Delete this service?')) document.getElementById('del-svc-{{ $service->id }}').submit();" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i> Delete</button>
                    <form id="del-svc-{{ $service->id }}" action="{{ route('management.services.destroy', $service) }}" method="POST" class="hidden">@csrf @method('DELETE')</form>
                </x-management.action-menu>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-hand-holding-heart" title="No services yet" action-label="Add Service" action-url="{{ route('management.services.create') }}" /></td></tr>
        @endforelse
    </x-management.data-table>
</x-management.card>
@endsection
