@extends('admin.layout')
@section('subtitle', 'Order Details - #' . $order->order_number)

@section('content')
<div class="flex items-center justify-between mb-6">
  <div>
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-slate-900">Order #{{ $order->order_number }}</h2>
      @if($order->isShop4me())
        <span class="inline-flex items-center rounded-full bg-slate-900 px-2.5 py-0.5 text-xs font-medium text-white">Shop4Me</span>
      @else
        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Standard</span>
      @endif
    </div>
    <p class="text-sm text-slate-500 mt-0.5">Created {{ $order->created_at->format('F d, Y \a\t H:i') }}</p>
  </div>
  <div class="flex items-center gap-2">
    <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
      <i class="fi fi-rr-arrow-left text-sm"></i> Back to Orders
    </a>
    <a href="{{ route('admin.orders.edit', $order) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
      <i class="fi fi-rr-pencil text-sm"></i> Edit Order
    </a>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 space-y-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Order Items ({{ $order->items->count() }})</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-slate-100">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Product</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Price</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Quantity</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Subtotal</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            @foreach($order->items as $item)
            <tr>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ $item->product_name }}</div>
                @if($item->product)
                <div class="text-xs text-slate-400">ID: {{ $item->product_id }}</div>
                @endif
              </td>
              <td class="px-4 py-3 text-slate-700">{{ $item->product_code }}</td>
              <td class="px-4 py-3 text-slate-700">₦{{ number_format($item->unit_price, 2) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ $item->quantity }}</td>
              <td class="px-4 py-3 font-semibold text-slate-900">₦{{ number_format($item->subtotal, 2) }}</td>
            </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr class="bg-slate-50">
              <td colspan="5" class="px-4 py-3">
                <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-sm">
                  <span><strong class="text-slate-700">Subtotal:</strong> ₦{{ number_format($order->subtotal, 2) }}</span>
                  <span class="text-slate-300 hidden sm:inline">|</span>
                  <span><strong class="text-slate-700">Shipping:</strong> ₦{{ number_format($order->shipping_fee, 2) }}</span>
                  <span class="text-slate-300 hidden sm:inline">|</span>
                  <span><strong class="text-slate-700">Tax:</strong> ₦{{ number_format($order->tax, 2) }}</span>
                  <span class="text-slate-300 hidden sm:inline">|</span>
                  <span class="text-base"><strong class="text-slate-900">Total:</strong> <span class="font-bold text-slate-900">₦{{ number_format($order->total, 2) }}</span></span>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Customer Information</h3>
        </div>
        <div class="p-4 space-y-3">
          <p class="text-sm"><span class="text-xs text-slate-500">Name:</span><br><strong class="text-slate-900">{{ $order->customer?->full_name ?? 'Walk-in' }}</strong></p>
          <p class="text-sm"><span class="text-xs text-slate-500">Email:</span><br><strong class="text-slate-900">{{ $order->customer?->email ?? '—' }}</strong></p>
          <p class="text-sm"><span class="text-xs text-slate-500">Phone:</span><br><strong class="text-slate-900">{{ $order->customer?->phone ?? '—' }}</strong></p>
          <p class="text-sm"><span class="text-xs text-slate-500">Address:</span><br><strong class="text-slate-900">{{ $order->customer?->full_address ?? '—' }}</strong></p>
        </div>
      </div>
      @if($order->delivery_state || $order->deliveryRoute)
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Delivery Information</h3>
        </div>
        <div class="p-4 space-y-3">
          @if($order->delivery_state && $order->delivery_area)
          <p class="text-sm"><span class="text-xs text-slate-500">Delivery Location:</span><br><strong class="text-slate-900">{{ $order->delivery_area }}, {{ $order->delivery_state }}</strong></p>
          @endif
          @if($order->deliveryRoute)
          <p class="text-sm"><span class="text-xs text-slate-500">Delivery Route:</span><br><strong class="text-slate-900">{{ $order->deliveryRoute->name }}</strong></p>
          <p class="text-sm"><span class="text-xs text-slate-500">Estimated Delivery:</span><br><strong class="text-slate-900">{{ $order->deliveryRoute->delivery_days }} days</strong></p>
          @endif
        </div>
      </div>
      @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Payment Information</h3>
        </div>
        <div class="p-4">
          @if($order->transactions->isNotEmpty())
            @foreach($order->transactions as $transaction)
            <div class="pb-3 mb-3 {{ !$loop->last ? 'border-b border-slate-100' : '' }} space-y-2">
              <p class="text-sm"><span class="text-xs text-slate-500">Reference:</span><br><strong class="text-slate-900">{{ $transaction->reference }}</strong></p>
              <p class="text-sm"><span class="text-xs text-slate-500">Payment Method:</span><br><strong class="text-slate-900">{{ $transaction->paymentMethod->name ?? 'N/A' }}</strong></p>
              <p class="text-sm"><span class="text-xs text-slate-500">Amount:</span><br><strong class="text-slate-900">₦{{ number_format($transaction->amount, 2) }}</strong></p>
              <p class="text-sm"><span class="text-xs text-slate-500">Status:</span><br>
                @php
                  $txnStatusVal = $transaction->status->value ?? ($transaction->status ?? '');
                  $txnColor = match($txnStatusVal) {
                    'completed', 'successful', 'paid' => 'bg-emerald-50 text-emerald-700',
                    'pending' => 'bg-amber-50 text-amber-700',
                    'failed' => 'bg-red-50 text-red-700',
                    default => 'bg-slate-100 text-slate-700',
                  };
                @endphp
                <span class="inline-flex items-center rounded-full {{ $txnColor }} px-2.5 py-0.5 text-xs font-medium">{{ $transaction->status->label() }}</span>
              </p>
            </div>
            @endforeach
          @else
            <p class="text-sm text-slate-500">No payment transactions recorded</p>
          @endif
        </div>
      </div>

      @if($order->notes)
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Order Notes</h3>
        </div>
        <div class="p-4">
          <p class="text-sm text-slate-700 whitespace-pre-wrap">{{ $order->notes }}</p>
        </div>
      </div>
      @endif
    </div>

    @if($activityLogs->isNotEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Activity Log</h3>
      </div>
      <div class="p-4 max-h-96 overflow-y-auto">
        @foreach($activityLogs as $log)
        <div class="pb-3 mb-3 {{ !$loop->last ? 'border-b border-slate-100' : '' }}">
          <div class="flex items-start gap-3">
            <span class="w-2 h-2 rounded-full bg-slate-300 mt-1.5 flex-shrink-0"></span>
            <div class="flex-1">
              <p class="text-sm"><strong class="text-slate-900">{{ $log->user->name ?? 'System' }}</strong> <span class="text-slate-600">{{ $log->description }}</span></p>
              <span class="text-xs text-slate-400">{{ $log->created_at->diffForHumans() }}</span>
            </div>
          </div>
        </div>
        @endforeach
      </div>
    </div>
    @endif
  </div>

  <div class="space-y-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Order Status</h3>
      </div>
      <div class="p-4">
        <form action="{{ route('admin.orders.update-status', $order) }}" method="POST">
          @csrf
          @method('PATCH')
          <div class="mb-3">
            <label class="block text-xs text-slate-500 mb-1">Current Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
              @foreach(\App\Enums\OrderStatus::cases() as $status)
                <option value="{{ $status->value }}" {{ $order->status === $status ? 'selected' : '' }}>
                  {{ $status->label() }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="block text-xs text-slate-500 mb-1">Notes (Optional)</label>
            <textarea name="notes" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Add notes about this status change..."></textarea>
          </div>
          <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-disk text-sm"></i> Update Status
          </button>
        </form>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Payment Status</h3>
      </div>
      <div class="p-4">
        <form action="{{ route('admin.orders.update-payment-status', $order) }}" method="POST">
          @csrf
          @method('PATCH')
          <div class="mb-3">
            <label class="block text-xs text-slate-500 mb-1">Current Payment Status</label>
            <select name="payment_status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
              <option value="unpaid" {{ $order->payment_status === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
              <option value="paid" {{ $order->payment_status === 'paid' ? 'selected' : '' }}>Paid</option>
              <option value="refunded" {{ $order->payment_status === 'refunded' ? 'selected' : '' }}>Refunded</option>
              <option value="failed" {{ $order->payment_status === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
          </div>
          <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-disk text-sm"></i> Update Payment
          </button>
        </form>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Quick Info</h3>
      </div>
      <div class="p-4 space-y-3">
         <p class="text-sm"><span class="text-xs text-slate-500">Store:</span><br><strong class="text-slate-900">{{ $order->store?->name ?? '—' }}</strong></p>
        @if($order->store?->user)
        <p class="text-sm"><span class="text-xs text-slate-500">Store Owner:</span><br><strong class="text-slate-900">{{ $order->store?->user?->name ?? '—' }}</strong></p>
        @endif
        <p class="text-sm"><span class="text-xs text-slate-500">Order Date:</span><br><strong class="text-slate-900">{{ $order->created_at->format('M d, Y H:i') }}</strong></p>
        <p class="text-sm"><span class="text-xs text-slate-500">Last Updated:</span><br><strong class="text-slate-900">{{ $order->updated_at->diffForHumans() }}</strong></p>
      </div>
    </div>
  </div>
</div>

@if(session('bulk_finalized'))
<div id="bulkFinalizedModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="bulkFinalizedModalLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-lg font-semibold text-emerald-700" id="bulkFinalizedModalLabel">
          <i class="fi fi-rr-check-circle mr-2"></i>Bulk Order Finalized Successfully
        </h5>
        <button type="button" onclick="closeModal('bulkFinalizedModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
      </div>
      <div class="mb-4">
        <p class="text-sm text-slate-700 mb-3">
          Bulk order has been finalized and converted to Order
          <a href="{{ route('admin.orders.show', session('bulk_finalized')['order_number']) }}" class="font-semibold text-blue-600 hover:text-blue-800">
            #{{ session('bulk_finalized')['order_number'] }}
          </a>
        </p>
        <p class="text-sm text-slate-700">
          Payment link has been sent to the customer:
        </p>
        <div class="flex items-center gap-2 mt-2">
          <input type="text" id="paymentLinkInput" value="{{ session('bulk_finalized')['payment_link'] }}" readonly class="flex-1 rounded-lg border-slate-300 px-3 py-1.5 text-xs bg-slate-50">
          <button class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50" type="button" onclick="copyPaymentLink()">
            <i class="fi fi-rr-copy"></i> Copy
          </button>
        </div>
        <a href="{{ session('bulk_finalized')['payment_link'] }}" target="_blank" class="inline-flex items-center gap-1 mt-2 text-xs text-blue-600 hover:text-blue-800">
          <i class="fi fi-rr-arrow-up-right"></i> Open Payment Link
        </a>
      </div>
      <div class="flex justify-end">
        <button type="button" onclick="closeModal('bulkFinalizedModal')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('bulkFinalizedModal').classList.remove('hidden');
});

function copyPaymentLink() {
    var input = document.getElementById('paymentLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);

    var btn = event.target.closest('button');
    var originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fi fi-rr-check"></i> Copied!';
    setTimeout(() => {
        btn.innerHTML = originalHTML;
    }, 2000);
}
</script>
@endif

@endsection
