<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay Invoice {{ $invoice->invoice_number }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body{background:#f8fafc;}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-2xl">
    @if(session('paymentSuccess'))
    <div class="text-center mb-6">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 mb-4">
            <svg class="w-8 h-8 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2 class="text-xl font-bold text-slate-900">Payment Successful</h2>
        <p class="text-slate-500 mt-1">Thank you for your payment.</p>
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if(session('success'))
    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        {{-- Header --}}
        <div class="bg-slate-900 px-6 py-8 text-white">
            <div class="flex justify-between items-start">
                <div>
                    <h2 class="text-lg font-bold">{{ $businessName }}</h2>
                    <p class="text-sm opacity-50 mt-1">{{ $invoice->invoice_number }}</p>
                </div>
                <div class="text-right">
                    <h1 class="text-2xl font-bold">INVOICE</h1>
                    @php
                        $badge = $invoice->status->badgeData()[$invoice->status->value] ?? [];
                        $isPaid = $invoice->status === \App\Enums\InvoiceStatus::PAID;
                        $isVoid = $invoice->status === \App\Enums\InvoiceStatus::VOID;
                    @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium mt-2 bg-white/20 text-white">{{ $invoice->status->label() }}</span>
                </div>
            </div>
        </div>

        <div class="px-6 py-5 border-b border-slate-100">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-xs text-slate-400 uppercase font-semibold">Bill To</p>
                    <p class="font-medium text-slate-800 mt-1">{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? '—' }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-400">Issued: {{ $invoice->issue_date->format('M d, Y') }}</p>
                    <p class="text-xs text-slate-400">Due: {{ $invoice->due_date->format('M d, Y') }}</p>
                </div>
            </div>
        </div>

        <div class="px-6 py-4">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-100 text-xs text-slate-400 uppercase font-semibold">
                    <th class="text-left pb-2">Description</th>
                    <th class="text-center pb-2">Qty</th>
                    <th class="text-right pb-2">Rate</th>
                    <th class="text-right pb-2">Amount</th>
                </tr></thead>
                <tbody>
                    @foreach($invoice->items as $item)
                    <tr class="border-b border-slate-50">
                        <td class="py-2 text-slate-700">{{ $item->description }}</td>
                        <td class="py-2 text-center text-slate-500">{{ $item->quantity }}</td>
                        <td class="py-2 text-right text-slate-500">₦{{ number_format($item->unit_price, 2) }}</td>
                        <td class="py-2 text-right font-medium text-slate-800">₦{{ number_format($item->amount, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex justify-end mt-4">
                <div class="w-52 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-400">Subtotal</span><span>₦{{ number_format($invoice->subtotal, 2) }}</span></div>
                    @if($invoice->tax_rate > 0)
                    <div class="flex justify-between"><span class="text-slate-400">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</span><span>₦{{ number_format($invoice->tax_amount, 2) }}</span></div>
                    @endif
                    @if($invoice->discount_value > 0)
                    <div class="flex justify-between"><span class="text-slate-400">Discount</span><span class="text-red-500">−₦{{ number_format($invoice->discount_value, 2) }}</span></div>
                    @endif
                    <div class="flex justify-between font-bold text-base border-t border-slate-200 pt-2 mt-2"><span>Total</span><span>₦{{ number_format($invoice->total, 2) }}</span></div>
                </div>
            </div>

            @if($invoice->amount_paid > 0)
            <div class="mt-4 p-3 bg-slate-50 rounded-xl grid grid-cols-2 gap-2 text-sm">
                <div><span class="text-slate-400">Total Paid</span><p class="font-bold text-emerald-600">₦{{ number_format($invoice->amount_paid, 2) }}</p></div>
                <div><span class="text-slate-400">Remaining</span><p class="font-bold {{ $invoice->remainingBalance() > 0 ? 'text-amber-600' : 'text-emerald-600' }}">₦{{ number_format($invoice->remainingBalance(), 2) }}</p></div>
            </div>
            @endif
        </div>

        {{-- Payment Form --}}
        @if(!$isPaid && !$isVoid)
        <div class="border-t border-slate-100 px-6 py-5">
            {{-- Tabs --}}
            <div class="flex gap-1 bg-slate-100 rounded-xl p-1 mb-4 w-fit" id="payTabs">
                <button onclick="switchPayTab('paystack')" id="tabPaystack" class="px-4 py-1.5 rounded-lg text-sm font-medium bg-white text-slate-900 shadow-sm">Paystack</button>
                @if($storeBankAccounts->isNotEmpty())
                <button onclick="switchPayTab('bank')" id="tabBank" class="px-4 py-1.5 rounded-lg text-sm font-medium text-slate-500">Bank Transfer</button>
                @endif
            </div>

            <div id="paystackSection">
                <form id="paymentForm" onsubmit="payWithPaystack(event)">
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-slate-500 mb-1">Amount to Pay (₦)</label>
                        <input type="number" id="payAmount" value="{{ number_format($invoice->remainingBalance(), 2, '.', '') }}" step="0.01" min="1" max="{{ $invoice->remainingBalance() }}"
                            class="w-full rounded-xl border-slate-300 px-4 py-3 text-lg font-bold shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 text-center">
                        <p class="text-xs text-slate-400 mt-1 text-center">Maximum: ₦{{ number_format($invoice->remainingBalance(), 2) }}</p>
                    </div>
                    <button type="submit" id="payBtn" class="w-full py-3 bg-slate-900 text-white font-semibold rounded-xl hover:bg-slate-800 transition-colors">
                        Pay with Paystack
                    </button>
                </form>
            </div>

            @if($storeBankAccounts->isNotEmpty())
            <div id="bankSection" class="hidden">
                @foreach($storeBankAccounts as $bank)
                <div class="bg-slate-50 rounded-xl p-4 mb-3">
                    <p class="font-semibold text-slate-800">{{ $bank->bank_name }}</p>
                    <p class="text-sm text-slate-500">{{ $bank->account_number }} — {{ $bank->account_name }}</p>
                </div>
                @endforeach
                <form method="POST" action="{{ route('invoice.pay.bank-transfer', ['token' => $token]) }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="number" name="amount" value="{{ number_format($invoice->remainingBalance(), 2, '.', '') }}" step="0.01" min="1" max="{{ $invoice->remainingBalance() }}" required
                        class="w-full rounded-xl border-slate-300 px-4 py-3 text-lg font-bold shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 text-center">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Upload Payment Slip</label>
                        <input type="file" name="payment_slip" accept=".jpg,.jpeg,.png,.pdf" required
                            class="w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-5 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700">
                    </div>
                    <button type="submit" class="w-full py-3 bg-slate-900 text-white font-semibold rounded-xl hover:bg-slate-800">Upload & Submit</button>
                </form>
            </div>
            @endif
        </div>
        @endif

        {{-- Payment History --}}
        @if($invoice->transactions->isNotEmpty())
        <div class="border-t border-slate-100 px-6 py-4">
            <p class="text-xs font-semibold text-slate-400 uppercase mb-3">Payment History</p>
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-100 text-xs text-slate-400">
                    <th class="text-left pb-2">Date</th>
                    <th class="text-left pb-2">Reference</th>
                    <th class="text-right pb-2">Amount</th>
                    <th class="text-right pb-2">Status</th>
                </tr></thead>
                <tbody>
                    @foreach($invoice->transactions->where('status', '!=', 'pending')->sortByDesc('created_at') as $tx)
                    <tr class="border-b border-slate-50">
                        <td class="py-2 text-slate-600 text-xs">{{ $tx->created_at->format('M d, Y') }}</td>
                        <td class="py-2 font-mono text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($tx->reference, 14) }}</td>
                        <td class="py-2 text-right font-medium">₦{{ number_format($tx->amount, 2) }}</td>
                        <td class="py-2 text-right">
                            <span class="text-xs font-medium {{ $tx->status->value === 'confirmed' ? 'text-emerald-600' : 'text-red-500' }}">
                                {{ $tx->status->value === 'confirmed' ? 'Paid' : $tx->status->label() }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <p class="text-center text-xs text-slate-400 mt-4">{{ config('app.name') }} · Invoice Payment</p>
</div>

<script>
function switchPayTab(tab) {
    document.getElementById('paystackSection').classList.toggle('hidden', tab !== 'paystack');
    document.getElementById('bankSection')?.classList.toggle('hidden', tab !== 'bank');
    document.getElementById('tabPaystack').className = tab === 'paystack' ? 'px-4 py-1.5 rounded-lg text-sm font-medium bg-white text-slate-900 shadow-sm' : 'px-4 py-1.5 rounded-lg text-sm font-medium text-slate-500';
    if (document.getElementById('tabBank')) {
        document.getElementById('tabBank').className = tab === 'bank' ? 'px-4 py-1.5 rounded-lg text-sm font-medium bg-white text-slate-900 shadow-sm' : 'px-4 py-1.5 rounded-lg text-sm font-medium text-slate-500';
    }
}

async function payWithPaystack(e) {
    e.preventDefault();
    const amount = document.getElementById('payAmount').value;
    const btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    try {
        const resp = await fetch('{{ route("invoice.pay.initialize", ["token" => $token]) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ amount: parseFloat(amount) })
        });
        const data = await resp.json();

        if (!data.success) {
            alert(data.message || 'Payment initialization failed');
            btn.disabled = false;
            btn.textContent = 'Pay with Paystack';
            return;
        }

        const handler = PaystackPop.setup({
            key: '{{ $storeHasPaystack ? ($store->paymentMethods()->where("code","paystack")->first()->pivot->api_keys["public_key"] ?? config("services.paystack.public_key")) : config("services.paystack.public_key") }}',
            email: '{{ $invoice->recipient_email ?? "" }}',
            amount: Math.round(parseFloat(amount) * 100),
            currency: 'NGN',
            ref: data.reference,
            onClose: function() { btn.disabled = false; btn.textContent = 'Pay with Paystack'; },
            callback: function() { window.location.href = '{{ route("invoice.pay.callback", ["token" => $token]) }}?reference=' + data.reference; }
        });
        handler.openIframe();
    } catch(err) {
        alert('An error occurred. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Pay with Paystack';
    }
}
</script>
</body>
</html>
