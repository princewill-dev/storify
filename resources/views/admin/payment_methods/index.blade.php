@extends('admin.layout')
@section('subtitle', 'Payment Methods')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Payment Methods</h2>
        <p class="text-sm text-slate-500 mt-0.5">Toggle availability for checkout</p>
    </div>
</div>

<div class="bg-blue-50 border border-blue-200 text-blue-700 rounded-xl p-4 mb-6 text-sm">
    <i class="fi fi-rr-info text-blue-500 mr-2"></i>
    Click the toggle button to enable or disable a payment method.
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 w-12">#</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Name</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Code</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Description</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($methods as $method)
                    <tr>
                        <td class="py-3 px-4">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-xs font-medium text-slate-600">{{ $loop->iteration }}</span>
                        </td>
                        <td class="py-3 px-4 text-slate-700">{{ $method->name }}</td>
                        <td class="py-3 px-4"><code class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-600">{{ $method->code }}</code></td>
                        <td class="py-3 px-4 text-slate-600">{{ $method->description ?? '&mdash;' }}</td>
                        <td class="py-3 px-4">
                            <span class="inline-flex items-center rounded-full {{ $method->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">
                                {{ $method->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border {{ $method->is_active ? 'border-red-200 text-red-600 hover:bg-red-50' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50' }}"
                                onclick="prepareToggleModal('{{ route('admin.payment-methods.toggle', $method) }}', '{{ $method->name }}', '{{ $method->is_active ? 'disable' : 'enable' }}'); openModal('togglePaymentMethodModal')"
                            >
                                {{ $method->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-12 text-center text-slate-400">No payment methods configured.</td></tr>
                @endforelse
            </tbody>
        </table>

</div>

<!-- Toggle Modal -->
<div id="togglePaymentMethodModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="togglePaymentMethodModalLabel" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('togglePaymentMethodModal')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl">
            <form id="togglePaymentMethodForm" method="POST" action="">
                @csrf
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900" id="togglePaymentMethodModalLabel"></h3>
                    <button type="button" onclick="closeModal('togglePaymentMethodModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <div class="p-6">
                    <p class="text-sm text-slate-600" id="togglePaymentMethodMessage"></p>
                </div>
                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                    <button type="button" onclick="closeModal('togglePaymentMethodModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800" id="togglePaymentMethodButton"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function prepareToggleModal(action, name, state) {
        const form = document.getElementById('togglePaymentMethodForm');
        const title = document.getElementById('togglePaymentMethodModalLabel');
        const message = document.getElementById('togglePaymentMethodMessage');
        const submit = document.getElementById('togglePaymentMethodButton');

        form.action = action;
        title.textContent = (state === 'enable' ? 'Enable' : 'Disable') + ' ' + name;
        message.textContent = 'Are you sure you want to ' + state + ' ' + name + '?';
        submit.textContent = state === 'enable' ? 'Enable' : 'Disable';
        submit.className = state === 'disable'
            ? 'inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700'
            : 'inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800';
    }
</script>
@endsection
