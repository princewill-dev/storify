@extends('management.layout')
@section('subtitle', 'Suppliers')

@section('content')

<div x-data="suppliersPage">
    <x-management.page-header title="Suppliers" subtitle="People and companies you buy from">
        <x-slot:actions>
            @can('accounting suppliers')
            <button @click="openCreate()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
                <i class="fi fi-rr-plus text-xs"></i> Add Supplier
            </button>
            @endcan
        </x-slot:actions>
    </x-management.page-header>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <form method="GET" class="px-5 py-3 border-b border-slate-100">
            <input name="q" value="{{ request('q') }}" placeholder="Search suppliers..." class="w-full max-w-sm rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        </form>
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Supplier</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Contact</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Bills</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Outstanding</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($suppliers as $supplier)
                @php $outstanding = max(0, (int) $supplier->bills_total_kobo - (int) $supplier->bills_paid_kobo); @endphp
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3">
                        <a href="{{ route('management.accounting.suppliers.show', $supplier) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $supplier->name }}</a>
                    </td>
                    <td class="px-5 py-3 text-slate-500 hidden md:table-cell">
                        <div>{{ $supplier->email ?? '—' }}</div>
                        @if($supplier->phone)<div class="text-xs text-slate-400">{{ $supplier->phone }}</div>@endif
                    </td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $supplier->bills_count }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $outstanding > 0 ? 'text-amber-600' : 'text-slate-800' }}">₦{{ number_format($outstanding / 100, 2) }}</td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1.5">
                            @can('accounting suppliers')
                            <button
                                data-supplier="{{ json_encode($supplier->only(['id', 'name', 'email', 'phone', 'address', 'notes'])) }}"
                                @click="openEdit($el.dataset.supplier)"
                                class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Edit</button>
                            <form method="POST" action="{{ route('management.accounting.suppliers.destroy', $supplier) }}" onsubmit="return confirm('Delete {{ $supplier->name }}?')">
                                @csrf @method('DELETE')
                                <button class="px-2.5 py-1 text-xs font-medium text-red-600 bg-red-50 rounded-md hover:bg-red-100">Delete</button>
                            </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-5 py-12">
                    <x-management.empty-state icon="fi fi-rr-truck-side" title="No suppliers yet" description="Add suppliers to track bills and payables." />
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($suppliers->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">{{ $suppliers->links() }}</div>
        @endif
    </div>

    {{-- Supplier form modal --}}
    <div x-show="showForm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="showForm = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4" x-text="form.id ? 'Edit Supplier' : 'Add Supplier'"></h3>
            <form method="POST" :action="form.id ? '{{ url('management/accounting/suppliers') }}/' + form.id : '{{ route('management.accounting.suppliers.store') }}'" class="space-y-4">
                @csrf
                <template x-if="form.id"><input type="hidden" name="_method" value="PUT"></template>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Name</label>
                    <input type="text" name="name" x-model="form.name" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                        <input type="email" name="email" x-model="form.email" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone</label>
                        <input type="text" name="phone" x-model="form.phone" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Address</label>
                    <textarea name="address" rows="2" x-model="form.address" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Notes</label>
                    <textarea name="notes" rows="2" x-model="form.notes" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500"></textarea>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800" x-text="form.id ? 'Save Changes' : 'Add Supplier'"></button>
                    <button type="button" @click="showForm = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('suppliersPage', () => ({
        showForm: false,
        form: { id: null, name: '', email: '', phone: '', address: '', notes: '' },
        openCreate() {
            this.form = { id: null, name: '', email: '', phone: '', address: '', notes: '' };
            this.showForm = true;
        },
        openEdit(payload) {
            const data = JSON.parse(payload);
            this.form = {
                id: data.id,
                name: data.name ?? '',
                email: data.email ?? '',
                phone: data.phone ?? '',
                address: data.address ?? '',
                notes: data.notes ?? '',
            };
            this.showForm = true;
        },
    }));
});
</script>
@endpush
