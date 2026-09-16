@extends('management.layout')
@section('subtitle', $bill->bill_number)

@section('content')

<div x-data="{ payOpen: false }">
    <x-management.page-header :title="$bill->bill_number" :subtitle="$bill->supplier?->name.' · issued '.$bill->issue_date->format('d M Y')">
        <x-slot:actions>
            @if(!in_array($bill->status, ['void', 'paid']))
            @can('accounting bills')
            <button @click="payOpen = true" class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Record Payment</button>
            <form method="POST" action="{{ route('management.accounting.bills.void', $bill) }}" onsubmit="return confirm('Void this bill? A reversing entry will be posted.')">
                @csrf
                <button class="px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-lg hover:bg-red-100">Void</button>
            </form>
            @endcan
            @endif
            <a href="{{ route('management.accounting.bills.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
        </x-slot:actions>
    </x-management.page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-800">Items</h2>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $bill->status === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($bill->status === 'void' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700') }}">{{ ucfirst($bill->status) }}</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50/50 border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Qty</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Unit Cost</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($bill->items as $item)
                        <tr>
                            <td class="px-5 py-3 text-slate-700">{{ $item->description }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">₦{{ number_format($item->unit_cost_kobo / 100, 2) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($item->amount_kobo / 100, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50/50 border-t border-slate-100">
                        <tr><td colspan="3" class="px-5 py-2.5 text-right text-xs text-slate-500">Subtotal</td><td class="px-5 py-2.5 text-right font-medium text-slate-700">₦{{ number_format($bill->subtotal_kobo / 100, 2) }}</td></tr>
                        <tr><td colspan="3" class="px-5 py-2.5 text-right text-xs text-slate-500">Input VAT</td><td class="px-5 py-2.5 text-right font-medium text-slate-700">₦{{ number_format($bill->tax_kobo / 100, 2) }}</td></tr>
                        <tr><td colspan="3" class="px-5 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</td><td class="px-5 py-2.5 text-right font-bold text-slate-900">₦{{ number_format($bill->total_kobo / 100, 2) }}</td></tr>
                        <tr><td colspan="3" class="px-5 py-2.5 text-right text-xs text-slate-500">Paid</td><td class="px-5 py-2.5 text-right font-medium text-emerald-600">₦{{ number_format($bill->amount_paid_kobo / 100, 2) }}</td></tr>
                        <tr><td colspan="3" class="px-5 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Balance</td><td class="px-5 py-2.5 text-right font-bold {{ $bill->remainingBalanceKobo() > 0 ? 'text-amber-600' : 'text-emerald-600' }}">₦{{ number_format($bill->remainingBalanceKobo() / 100, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            @if($bill->payments->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Payments</h2></div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50/50 border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Method</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($bill->payments as $payment)
                        <tr>
                            <td class="px-5 py-3 text-xs text-slate-500">{{ $payment->payment_date->format('d M Y') }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ ucfirst(str_replace('_', ' ', $payment->method ?? '—')) }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ $payment->reference ?? '—' }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($payment->amount_kobo / 100, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">Details</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Supplier</dt><dd><a href="{{ route('management.accounting.suppliers.show', $bill->supplier) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $bill->supplier?->name }}</a></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Issue date</dt><dd class="font-medium text-slate-800">{{ $bill->issue_date->format('d M Y') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Due date</dt><dd class="font-medium text-slate-800">{{ $bill->due_date?->format('d M Y') ?? '—' }}</dd></div>
                    @if($bill->notes)<div><dt class="text-slate-500 text-xs">Notes</dt><dd class="text-slate-700 mt-0.5">{{ $bill->notes }}</dd></div>@endif
                </dl>
            </div>

            @if($bill->journalEntry)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="text-sm font-semibold text-slate-800 mb-3">Journal Entry</h2>
                <a href="{{ route('management.accounting.journal.show', $bill->journalEntry) }}" class="text-sm text-blue-600 hover:text-blue-700">{{ $bill->journalEntry->entry_number }}</a>
            </div>
            @endif
        </div>
    </div>

    {{-- Payment modal --}}
    <div x-show="payOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="payOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4">Record Payment</h3>
            <form method="POST" action="{{ route('management.accounting.bills.payments.store', $bill) }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Date</label>
                        <input type="date" name="payment_date" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount (₦)</label>
                        <input type="number" step="0.01" min="0.01" max="{{ $bill->remainingBalanceKobo() / 100 }}" name="amount" value="{{ $bill->remainingBalanceKobo() / 100 }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Method</label>
                        <select name="method" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="card">Card</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid From <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select name="payment_account_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">Auto</option>
                            @foreach($paymentAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Reference <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="reference" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Payment</button>
                    <button type="button" @click="payOpen = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
