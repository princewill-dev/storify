@extends('management.layout')
@php $isEdit = isset($invoice) && $invoice->exists; @endphp
@section('subtitle', $isEdit ? 'Edit Invoice' : 'New Invoice')

@section('content')

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">{{ $isEdit ? 'Edit Invoice' : 'New Invoice' }}</h2>
        <p class="text-sm text-slate-500 mt-0.5">{{ $isEdit ? $invoice->invoice_number : 'Create and send a professional invoice' }}</p>
    </div>
    <a href="{{ $isEdit ? route('management.invoices.show', $invoice) : route('management.invoices.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-slate-300 hover:bg-slate-50 transition-colors">← Back</a>
</div>

<form method="POST" action="{{ $isEdit ? route('management.invoices.update', $invoice) : route('management.invoices.store') }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Main Form --}}
        <div class="lg:col-span-3 space-y-5">
            {{-- Customer --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-300 p-5">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Bill To</p>
                <div class="mb-4">
                    <select id="customerSelect" name="customer_id" onchange="handleCustomerSelect(this)" class="w-full rounded-xl border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                        <option value="">— Select saved customer —</option>
                        @foreach($customers as $c)
                        <option value="{{ $c->id }}" @selected(old('customer_id', $invoice->customer_id ?? null) == $c->id)
                            data-name="{{ $c->full_name }}" data-email="{{ $c->email }}" data-phone="{{ $c->phone }}">
                            {{ $c->full_name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-slate-300 text-center mb-4">— or enter new recipient —</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Name</label>
                        <input type="text" name="recipient_name" id="recipientName" value="{{ old('recipient_name', $invoice->recipient_name ?? '') }}"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="Client or business name">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Email</label>
                        <input type="email" name="recipient_email" id="recipientEmail" value="{{ old('recipient_email', $invoice->recipient_email ?? '') }}"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="client@email.com">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Phone</label>
                        <input type="text" name="recipient_phone" id="recipientPhone" value="{{ old('recipient_phone', $invoice->recipient_phone ?? '') }}"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="+234...">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Address</label>
                        <input type="text" name="recipient_address" id="recipientAddress" value="{{ old('recipient_address', $invoice->recipient_address ?? '') }}"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="Street, city">
                    </div>
                </div>
                <label class="flex items-center gap-2 mt-3 cursor-pointer">
                    <input type="checkbox" name="save_customer" value="1" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                    <span class="text-xs text-slate-400">Save as customer for future invoices</span>
                </label>
            </div>

            {{-- Line Items --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-300 p-5">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Items</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm" id="itemsTable">
                        <thead>
                            <tr class="border-b border-slate-100">
                                <th class="pb-2 text-left text-[10px] font-bold text-slate-400 uppercase tracking-widest">Description</th>
                                <th class="pb-2 text-center text-[10px] font-bold text-slate-400 uppercase tracking-widest w-20">Qty</th>
                                <th class="pb-2 text-right text-[10px] font-bold text-slate-400 uppercase tracking-widest w-28">Rate (₦)</th>
                                <th class="pb-2 text-right text-[10px] font-bold text-slate-400 uppercase tracking-widest w-28">Amount</th>
                                <th class="pb-2 w-8"></th>
                            </tr>
                        </thead>
                        <tbody id="itemRows">
                            @php $items = old('items', ($isEdit && $invoice->items->isNotEmpty()) ? $invoice->items : [['description' => '', 'quantity' => 1, 'unit_price' => 0]]); @endphp
                            @foreach($items as $i => $item)
                            <tr class="item-row border-b border-slate-50">
                                <td class="py-2 pr-2">
                                    <input type="text" name="items[{{ $i }}][description]" value="{{ is_array($item) ? $item['description'] : $item->description }}"
                                        class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 placeholder:text-slate-300" placeholder="Item description">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" name="items[{{ $i }}][quantity]" value="{{ is_array($item) ? $item['quantity'] : $item->quantity }}" min="1" step="1"
                                        class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-sm text-center shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 item-qty" oninput="recalcTotals()">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" name="items[{{ $i }}][unit_price]" value="{{ is_array($item) ? $item['unit_price'] : $item->unit_price }}" min="0" step="0.01"
                                        class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-sm text-right shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 item-price" oninput="recalcTotals()">
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <span class="item-row-total text-sm font-semibold text-slate-700">₦0.00</span>
                                </td>
                                <td class="py-2">
                                    <button type="button" onclick="removeItemRow(this)" class="p-1 text-slate-300 hover:text-red-500 transition-colors">&times;</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="button" onclick="addItemRow()" 
                    class="w-full mt-2 py-2 border-2 border-dashed border-slate-200 rounded-xl text-sm text-slate-400 hover:border-slate-400 hover:text-slate-600 transition-colors flex items-center justify-center gap-1.5">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add another line item
                </button>

                {{-- Totals — Horizontal Bar --}}
                <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2 mt-4 pt-4 border-t border-slate-100 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-400">Subtotal</span>
                        <span id="displaySubtotal" class="font-semibold text-slate-700 tabular-nums">₦0.00</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="text-slate-400">Tax</span>
                        <input type="number" name="tax_rate" id="taxRate" value="{{ old('tax_rate', $invoice->tax_rate ?? 0) }}" step="0.01" min="0" max="100"
                            class="w-14 rounded-lg border-slate-300 px-1.5 py-1 text-xs text-right shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" oninput="recalcTotals()">
                        <span class="text-slate-400 text-xs">%</span>
                        <span id="displayTax" class="font-semibold text-slate-500 tabular-nums">₦0.00</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="text-slate-400">Discount</span>
                        <select name="discount_type" id="discountType" class="w-12 rounded-lg border-slate-300 px-1 py-1 text-xs shadow-sm" onchange="recalcTotals()">
                            <option value="">—</option>
                            <option value="fixed" @selected(old('discount_type', $invoice->discount_type ?? '') === 'fixed')>₦</option>
                            <option value="percentage" @selected(old('discount_type', $invoice->discount_type ?? '') === 'percentage')>%</option>
                        </select>
                        <input type="number" name="discount_value" id="discountValue" value="{{ old('discount_value', $invoice->discount_value ?? 0) }}" step="0.01" min="0"
                            class="w-16 rounded-lg border-slate-300 px-1.5 py-1 text-xs text-right shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" oninput="recalcTotals()">
                    </div>
                    <div class="flex items-center gap-2 bg-slate-900 text-white rounded-xl px-4 py-1.5">
                        <span class="text-xs opacity-70">Total</span>
                        <span id="displayTotal" class="text-base font-bold tabular-nums">₦0.00</span>
                    </div>
                </div>

                <input type="hidden" name="subtotal" id="subtotalField">
                <input type="hidden" name="tax_amount" id="taxField">
                <input type="hidden" name="discount_value" id="discountField">
                <input type="hidden" name="total" id="totalField">
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="lg:col-span-2 space-y-5">
            {{-- Dates & Store --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-300 p-5">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Details</p>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Issue Date</label>
                        <input type="date" name="issue_date" value="{{ old('issue_date', $invoice->issue_date?->toDateString() ?? now()->toDateString()) }}" required
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Due Date</label>
                        <input type="date" name="due_date" value="{{ old('due_date', $invoice->due_date?->toDateString() ?? now()->addDays(14)->toDateString()) }}" required
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Store</label>
                        <select name="store_id" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                            <option value="">— Select a store —</option>
                            @foreach($stores as $s)
                            <option value="{{ $s->id }}" @selected(old('store_id', $invoice->store_id ?? ($isEdit ? null : $stores->first()?->id)) == $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Notes & Terms --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-300 p-5">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Additional Info</p>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 resize-none" placeholder="Internal notes...">{{ old('notes', $invoice->notes ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Payment Terms</label>
                        <textarea name="terms" rows="2" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 resize-none" placeholder="e.g. Net 14 days. Bank transfer only.">{{ old('terms', $invoice->terms ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-300 p-5 space-y-3">
                <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors shadow-sm">
                    {{ $isEdit ? 'Save Changes' : 'Save as Draft' }}
                </button>
                @if(!$isEdit)
                <button type="submit" name="finalize" value="1" class="w-full py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                    Save & Send
                </button>
                @endif
                @if($isEdit)
                <button type="submit" name="finalize" value="1" class="w-full py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                    Save & Send
                </button>
                @endif
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
let itemCounter = {{ count($items) }};

function addItemRow() {
    const tbody = document.getElementById('itemRows');
    const tr = document.createElement('tr');
    tr.className = 'item-row border-b border-slate-50';
    tr.innerHTML = `
        <td class="py-2 pr-2"><input type="text" name="items[${itemCounter}][description]" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 placeholder:text-slate-300" placeholder="Item description"></td>
        <td class="py-2 px-2"><input type="number" name="items[${itemCounter}][quantity]" value="1" min="1" step="1" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-sm text-center shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 item-qty" oninput="recalcTotals()"></td>
        <td class="py-2 px-2"><input type="number" name="items[${itemCounter}][unit_price]" value="0" min="0" step="0.01" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-sm text-right shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 item-price" oninput="recalcTotals()"></td>
        <td class="py-2 px-2 text-right"><span class="item-row-total text-sm font-semibold text-slate-700">₦0.00</span></td>
        <td class="py-2"><button type="button" onclick="removeItemRow(this)" class="p-1 text-slate-300 hover:text-red-500 transition-colors">&times;</button></td>
    `;
    tbody.appendChild(tr);
    itemCounter++;
    recalcTotals();
}

function removeItemRow(btn) {
    const rows = document.querySelectorAll('#itemRows .item-row');
    if (rows.length <= 1) return;
    btn.closest('tr').remove();
    recalcTotals();
}

function recalcTotals() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty')?.value) || 0;
        const price = parseFloat(row.querySelector('.item-price')?.value) || 0;
        const amt = qty * price;
        subtotal += amt;
        const totalEl = row.querySelector('.item-row-total');
        if (totalEl) totalEl.textContent = '₦' + amt.toFixed(2);
    });

    const taxRate = parseFloat(document.getElementById('taxRate')?.value) || 0;
    const tax = subtotal * (taxRate / 100);
    const discType = document.getElementById('discountType')?.value;
    const discVal = parseFloat(document.getElementById('discountValue')?.value) || 0;
    let discount = discType === 'percentage' ? subtotal * (discVal / 100) : discVal;
    discount = Math.min(discount, subtotal + tax);
    const total = subtotal + tax - discount;

    document.getElementById('displaySubtotal').textContent = '₦' + subtotal.toFixed(2);
    document.getElementById('displayTax').textContent = '₦' + tax.toFixed(2);
    document.getElementById('displayTotal').textContent = '₦' + total.toFixed(2);
    document.getElementById('subtotalField').value = subtotal.toFixed(2);
    document.getElementById('taxField').value = tax.toFixed(2);
    document.getElementById('discountField').value = discount.toFixed(2);
    document.getElementById('totalField').value = total.toFixed(2);
}

function handleCustomerSelect(sel) {
    const opt = sel.selectedOptions[0];
    if (opt && opt.dataset.name) {
        document.getElementById('recipientName').value = opt.dataset.name;
        const email = opt.dataset.email || '';
        document.getElementById('recipientEmail').value = email.includes('@walkin.local') ? '' : email;
        document.getElementById('recipientPhone').value = opt.dataset.phone || '';
    }
}

recalcTotals();
</script>
@endpush
