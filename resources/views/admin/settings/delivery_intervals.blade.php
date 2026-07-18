@extends('admin.layout')
@section('subtitle', 'Delivery Intervals')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Delivery Intervals</h2>
</div>

@if(session('success'))
    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-sm text-emerald-700 flex items-center justify-between">
        {{ session('success') }}
        <button type="button" class="text-emerald-400 hover:text-emerald-600" onclick="this.parentElement.remove()">&times;</button>
    </div>
@endif

@if(session('error'))
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 flex items-center justify-between">
        {{ session('error') }}
        <button type="button" class="text-red-400 hover:text-red-600" onclick="this.parentElement.remove()">&times;</button>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Create Form -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Add New Interval</h3>
        </div>
        <div class="p-6">
            <form action="{{ route('admin.delivery-intervals.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="e.g. Weekly" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Days Count</label>
                    <input type="number" name="days_count" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="e.g. 7" min="1" required>
                    <p class="mt-1 text-xs text-slate-400">Number of days between deliveries.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Sort Order</label>
                    <input type="number" name="sort_order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="0" min="0">
                </div>
                <button type="submit" class="inline-flex items-center justify-center gap-1.5 w-full px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                    <i class="fi fi-rr-plus text-sm"></i> Create Interval
                </button>
            </form>
        </div>
    </div>

    <!-- List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Existing Intervals</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100">
                    <tr>
                        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Days</th>
                        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
                        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($intervals as $interval)
                        <tr class="hover:bg-slate-50/50">
                            <td class="py-3 px-4 text-slate-700">{{ $interval->name }}</td>
                            <td class="py-3 px-4 text-slate-700">{{ $interval->days_count }} days</td>
                            <td class="py-3 px-4 text-slate-700">{{ $interval->sort_order }}</td>
                            <td class="py-3 px-4">
                                @if($interval->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="openModal('editModal{{ $interval->id }}')" class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="Edit">
                                        <i class="fi fi-rr-pencil text-sm"></i>
                                    </button>
                                    <form action="{{ route('admin.delivery-intervals.toggle', $interval->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center justify-center p-1.5 {{ $interval->is_active ? 'text-amber-500 hover:bg-amber-50' : 'text-emerald-500 hover:bg-emerald-50' }} hover:bg-slate-100 rounded-lg" title="{{ $interval->is_active ? 'Deactivate' : 'Activate' }}">
                                            <i class="fi {{ $interval->is_active ? 'fi-rr-ban' : 'fi-rr-check' }} text-sm"></i>
                                        </button>
                                    </form>
                                    <button type="button" onclick="openModal('deleteInterval{{ $interval->id }}')" class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete">
                                        <i class="fi fi-rr-trash text-sm"></i>
                                    </button>
                                    <x-admin.confirm-modal id="deleteInterval{{ $interval->id }}" title="Delete Interval" message="Are you sure?" action="{{ route('admin.delivery-intervals.destroy', $interval->id) }}" method="DELETE" />
                                </div>

                                <!-- Edit Modal -->
                                <div id="editModal{{ $interval->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
                                    <div class="flex items-center justify-center min-h-screen p-4">
                                        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editModal{{ $interval->id }}')"></div>
                                        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
                                            <div class="flex items-center justify-between mb-4">
                                                <h5 class="text-base font-semibold text-slate-900">Edit Interval</h5>
                                                <button onclick="closeModal('editModal{{ $interval->id }}')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
                                            </div>
                                            <form action="{{ route('admin.delivery-intervals.update', $interval->id) }}" method="POST" class="space-y-4">
                                                @csrf
                                                @method('PUT')
                                                <div>
                                                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                                                    <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ $interval->name }}" required>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-slate-700 mb-1">Days Count</label>
                                                    <input type="number" name="days_count" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ $interval->days_count }}" min="1" required>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-slate-700 mb-1">Sort Order</label>
                                                    <input type="number" name="sort_order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ $interval->sort_order }}" min="0">
                                                </div>
                                                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                                                    <button type="button" onclick="closeModal('editModal{{ $interval->id }}')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                                                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400">No delivery intervals found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
