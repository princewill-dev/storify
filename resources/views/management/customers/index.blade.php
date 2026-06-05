@extends('management.layout')
@section('subtitle', 'Customers')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Customers" subtitle="People who have ordered from your stores" />

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Customer</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Email</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Phone</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Orders</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>
    @forelse($customers as $customer)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.customers.show', $customer) }}" class="flex items-center gap-2.5">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 shrink-0">{{ strtoupper(substr($customer->first_name ?? '?', 0, 1)) }}</span>
                <span class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $customer->first_name }} {{ $customer->last_name }}</span>
            </a>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-500">{{ $customer->email }}</span></td>
        <td class="px-5 py-3 hidden md:table-cell"><span class="text-sm text-slate-500">{{ $customer->phone ?? '—' }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$customer->status" /></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><span class="text-sm font-medium text-slate-600">{{ $customer->orders_count ?? 0 }}</span></td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.customers.show', $customer) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View">
                    <i class="fi fi-rr-eye text-xs"></i>
                </a>
                <a href="{{ route('management.customers.edit', $customer) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </a>
            </div>
        </td>
    </tr>
    @empty
    <tr><td colspan="6" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-users-alt" title="No customers yet" description="Customer data will appear once orders start coming in." /></td></tr>
    @endforelse
</x-management.data-table>
@endsection
