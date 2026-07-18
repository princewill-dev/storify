@extends('admin.layout')
@section('subtitle', 'Order Management')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Order Management</h2>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Total Orders</div>
    <div class="text-2xl font-bold text-slate-900">{{ number_format($stats['total']) }}</div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Pending</div>
    <div class="text-2xl font-bold text-slate-900">{{ number_format($stats['pending']) }}</div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Processing</div>
    <div class="text-2xl font-bold text-slate-900">{{ number_format($stats['processing']) }}</div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Total Revenue</div>
    <div class="text-2xl font-bold text-slate-900">₦{{ number_format($stats['total_revenue'], 2) }}</div>
  </div>
</div>

<div class="flex items-center justify-between mb-4">
  <div>
    @if(request()->hasAny(['search', 'status', 'payment_status', 'store_id', 'date_from', 'date_to']))
      <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 mr-2">
        <i class="fi fi-rr-settings-sliders mr-1"></i> Filters Active
      </span>
      <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
        <i class="fi fi-rr-cross-small"></i> Clear Filters
      </a>
    @endif
  </div>
  <button type="button" onclick="openModal('filterModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
    <i class="fi fi-rr-settings-sliders"></i> Filter Orders
  </button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="px-4 py-3 border-b border-slate-100">
    <h3 class="text-sm font-semibold text-slate-900">Orders ({{ $orders->total() }})</h3>
  </div>
  
    <table class="w-full text-sm">
      <thead class="border-b border-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order #</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Items</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Payment</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($orders as $order)
        <tr class="hover:bg-slate-50/50">
          <td class="px-4 py-3">
            <a href="{{ route('admin.orders.show', $order) }}" class="text-xs font-bold text-slate-900 hover:text-blue-600">
              {{ $order->order_number }}
            </a>
          </td>
          <td class="px-4 py-3 text-slate-700">
            <div>{{ $order->customer?->full_name ?? 'Walk-in' }}</div>
          </td>
          <td class="px-4 py-3 text-slate-700">{{ $order->store?->name ?? '—' }}</td>
          <td class="px-4 py-3 text-slate-700">{{ $order->items->count() }}</td>
          <td class="px-4 py-3">
            @if($order->isShop4me())
              <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 border border-slate-200">Shop4Me</span>
            @else
              <span class="inline-flex items-center rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-slate-500 border border-slate-200">Standard</span>
            @endif
          </td>
          <td class="px-4 py-3 font-semibold text-slate-900">₦{{ number_format($order->total, 2) }}</td>
          <td class="px-4 py-3">
            @php
              $statusVal = $order->status->value ?? ($order->status ?? '');
              $statusColor = match($statusVal) {
                'pending' => 'bg-amber-50 text-amber-700',
                'processing' => 'bg-blue-50 text-blue-700',
                'dispatched' => 'bg-indigo-50 text-indigo-700',
                'delivered', 'completed' => 'bg-emerald-50 text-emerald-700',
                'cancelled' => 'bg-red-50 text-red-700',
                'returned' => 'bg-red-50 text-red-700',
                'accepted' => 'bg-sky-50 text-sky-700',
                default => 'bg-slate-100 text-slate-700',
              };
            @endphp
            <span class="inline-flex items-center rounded-full {{ $statusColor }} px-2.5 py-0.5 text-xs font-medium">{{ $order->status->label() }}</span>
          </td>
          <td class="px-4 py-3">
            @php
              $paymentStatusEnum = $order->payment_status instanceof \App\Enums\PaymentStatus
                  ? $order->payment_status
                  : \App\Enums\PaymentStatus::tryFrom($order->payment_status ?? 'failed');
              $payColor = match($paymentStatusEnum?->value) {
                'paid' => 'bg-emerald-50 text-emerald-700',
                'unpaid' => 'bg-amber-50 text-amber-700',
                'refunded' => 'bg-purple-50 text-purple-700',
                'failed' => 'bg-red-50 text-red-700',
                default => 'bg-slate-100 text-slate-700',
              };
            @endphp
            @if($paymentStatusEnum)
              <span class="inline-flex items-center rounded-full {{ $payColor }} px-2.5 py-0.5 text-xs font-medium">{{ $paymentStatusEnum->label() }}</span>
            @else
              <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">Unknown</span>
            @endif
          </td>
          <td class="px-4 py-3 text-slate-500">{{ $order->created_at->format('M d, Y') }}</td>
          <td class="px-4 py-3">
            <div class="flex items-center gap-1">
              <a href="{{ route('admin.orders.show', $order) }}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" title="View">
                <i class="fi fi-rr-eye text-sm"></i>
              </a>
              <a href="{{ route('admin.orders.edit', $order) }}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-blue-600" title="Edit">
                <i class="fi fi-rr-pencil text-sm"></i>
              </a>
              <button type="button" onclick="openModal('deleteOrder{{ $order->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600" title="Delete">
                <i class="fi fi-rr-trash text-sm"></i>
              </button>
              <x-admin.confirm-modal id="deleteOrder{{ $order->id }}" title="Delete Order" message="Are you sure you want to delete this order?" action="{{ route('admin.orders.destroy', $order) }}" method="DELETE" />
            </div>
          </td>
        </tr>
        @empty
        <tr>
          <td colspan="10" class="px-4 py-12 text-center text-slate-500">No orders found</td>
        </tr>
        @endforelse
      </tbody>
    </table>

  @if($orders->hasPages())
  <div class="px-4 py-3 border-t border-slate-100">
    {{ $orders->links() }}
  </div>
  @endif
</div>

{{-- Filter Modal --}}
<div id="filterModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="filterModalLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('filterModal')"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-3xl w-full p-6">
      <div class="flex items-center justify-between mb-6">
        <h5 class="text-lg font-semibold text-slate-900" id="filterModalLabel">Filter Orders</h5>
        <button type="button" onclick="closeModal('filterModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
      </div>
      <form method="GET" action="{{ route('admin.orders.index') }}">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
            <input type="text" name="search" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Order # or Customer" value="{{ request('search') }}">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
            <select name="store_id" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All Stores</option>
              @foreach($stores as $store)
              <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>
                {{ $store->name }}
              </option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Order Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All Statuses</option>
              <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
              <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
              <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
              <option value="dispatched" {{ request('status') == 'dispatched' ? 'selected' : '' }}>Dispatched</option>
              <option value="delivered" {{ request('status') == 'delivered' ? 'selected' : '' }}>Delivered</option>
              <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
              <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
              <option value="returned" {{ request('status') == 'returned' ? 'selected' : '' }}>Returned</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Payment Status</label>
            <select name="payment_status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All</option>
              <option value="unpaid" {{ request('payment_status') == 'unpaid' ? 'selected' : '' }}>Unpaid</option>
              <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
              <option value="refunded" {{ request('payment_status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
              <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Date Range</label>
            <div class="flex items-center gap-2">
              <input type="date" name="date_from" class="flex-1 rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="From" value="{{ request('date_from') }}">
              <span class="text-xs text-slate-400">to</span>
              <input type="date" name="date_to" class="flex-1 rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="To" value="{{ request('date_to') }}">
            </div>
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
          <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center gap-1 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
            <i class="fi fi-rr-refresh"></i> Clear All
          </a>
          <button type="submit" class="inline-flex items-center gap-1 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-search"></i> Apply Filters
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert('{{ session('success') }}');
    });
</script>
@endif

@if(session('error'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert('{{ session('error') }}');
    });
</script>
@endif
@endsection
