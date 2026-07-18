@extends('admin.layout')
@section('subtitle', 'Customer Details')

@section('content')
<div class="flex items-center justify-between mb-6">
  <div>
    <a href="{{ route('admin.customers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 mb-3">
      <i class="fi fi-rr-arrow-left text-sm"></i> Back
    </a>
  </div>
  <div class="flex items-center gap-2">
    <a href="{{ route('admin.customers.edit', $customer) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
      <i class="fi fi-rr-pencil text-sm"></i> Edit
    </a>
    @if($customer->status === \App\Models\Customer::STATUS_ACTIVE)
      <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50" onclick="showSuspendModal({{ Js::from($customer->account_id) }}, {{ Js::from($customer->full_name) }})">
        <i class="fi fi-rr-ban text-sm"></i> Suspend
      </button>
    @elseif($customer->status === \App\Models\Customer::STATUS_SUSPENDED)
      <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-emerald-200 text-emerald-600 hover:bg-emerald-50" onclick="showActivateModal({{ Js::from($customer->account_id) }}, {{ Js::from($customer->full_name) }})">
        <i class="fi fi-rr-check-circle text-sm"></i> Activate
      </button>
    @endif
  </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-shopping-cart text-blue-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Orders</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['total_orders']) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-check-circle text-emerald-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Completed</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['completed_orders']) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-sky-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-money text-sky-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Spent</div>
        <div class="text-xl font-bold text-slate-900">₦{{ number_format($stats['total_spent'], 2) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-time-fast text-amber-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pending</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['pending_orders']) }}</div>
      </div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-1 space-y-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Customer Information</h3>
      </div>
      <div class="p-6">
        <div class="text-center mb-6">
          <div class="w-16 h-16 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-2xl mx-auto mb-3">
            {{ strtoupper(substr($customer->first_name, 0, 1)) }}{{ strtoupper(substr($customer->last_name, 0, 1)) }}
          </div>
          <h4 class="text-base font-semibold text-slate-900">{{ $customer->full_name }}</h4>
          <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-700 px-2.5 py-0.5 text-xs font-medium border border-slate-200 mt-1">Status: {{ ucfirst(strtolower($customer->status)) }}</span>
        </div>

        <div class="space-y-4">
          <div class="flex items-start gap-3">
            <i class="fi fi-rr-envelope text-blue-600 mt-0.5"></i>
            <div>
              <div class="text-xs text-slate-500">Email</div>
              <div class="text-sm font-medium text-slate-900">{{ $customer->email }}</div>
            </div>
          </div>
          @if($customer->phone)
          <div class="flex items-start gap-3">
            <i class="fi fi-rr-phone-call text-blue-600 mt-0.5"></i>
            <div>
              <div class="text-xs text-slate-500">Phone</div>
              <div class="text-sm font-medium text-slate-900">{{ $customer->phone }}</div>
            </div>
          </div>
          @endif
          @if($customer->company_name)
          <div class="flex items-start gap-3">
            <i class="fi fi-rr-building text-blue-600 mt-0.5"></i>
            <div>
              <div class="text-xs text-slate-500">Company</div>
              <div class="text-sm font-medium text-slate-900">{{ $customer->company_name }}</div>
            </div>
          </div>
          @endif
          <div class="flex items-start gap-3">
            <i class="fi fi-rr-calendar text-blue-600 mt-0.5"></i>
            <div>
              <div class="text-xs text-slate-500">Member Since</div>
              <div class="text-sm font-medium text-slate-900">{{ $customer->created_at->format('M d, Y') }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Address Information</h3>
      </div>
      <div class="p-6">
        @if($customer->street_address)
        <p class="text-sm mb-2"><strong class="text-slate-700">Street Address:</strong><br><span class="text-slate-600">{{ $customer->street_address }}</span></p>
        @if($customer->apartment)
        <p class="text-sm mb-2"><strong class="text-slate-700">Apartment/Unit:</strong><br><span class="text-slate-600">{{ $customer->apartment }}</span></p>
        @endif
        <p class="text-sm mb-2"><strong class="text-slate-700">City:</strong> <span class="text-slate-600">{{ $customer->city ?? '-' }}</span></p>
        <p class="text-sm mb-2"><strong class="text-slate-700">State:</strong> <span class="text-slate-600">{{ $customer->state ?? '-' }}</span></p>
        <p class="text-sm mb-2"><strong class="text-slate-700">ZIP Code:</strong> <span class="text-slate-600">{{ $customer->zip_code ?? '-' }}</span></p>
        <p class="text-sm"><strong class="text-slate-700">Country:</strong> <span class="text-slate-600">{{ $customer->country ?? '-' }}</span></p>
        @else
        <p class="text-sm text-slate-500 text-center">No address information available</p>
        @endif
      </div>
    </div>
  </div>

  <div class="lg:col-span-2 space-y-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Recent Orders</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-slate-100">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order #</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Items</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            @forelse($customer->orders as $order)
            <tr class="hover:bg-slate-50/50">
              <td class="px-4 py-3">
                <a href="{{ route('admin.orders.show', $order) }}" class="text-slate-900 font-medium hover:text-blue-600">
                  {{ $order->order_number }}
                </a>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ $order->store?->name ?? '—' }}</td>
              <td class="px-4 py-3 text-slate-700">{{ $order->items->count() }} items</td>
              <td class="px-4 py-3 text-slate-900">₦{{ number_format($order->total, 2) }}</td>
              <td class="px-4 py-3">
                @php
                  $ordStatusVal = $order->status->value ?? ($order->status ?? '');
                  $ordColor = match($ordStatusVal) {
                    'pending' => 'bg-amber-50 text-amber-700',
                    'processing' => 'bg-blue-50 text-blue-700',
                    'dispatched' => 'bg-indigo-50 text-indigo-700',
                    'delivered', 'completed' => 'bg-emerald-50 text-emerald-700',
                    'cancelled' => 'bg-red-50 text-red-700',
                    default => 'bg-slate-100 text-slate-700',
                  };
                @endphp
                <span class="inline-flex items-center rounded-full {{ $ordColor }} px-2.5 py-0.5 text-xs font-medium">{{ $order->status->label() }}</span>
              </td>
              <td class="px-4 py-3 text-slate-500">{{ $order->created_at->format('M d, Y') }}</td>
            </tr>
            @empty
            <tr>
              <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                No orders yet
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Recent Transactions</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-slate-100">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Payment Method</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            @forelse($transactions as $transaction)
            <tr class="hover:bg-slate-50/50">
              <td class="px-4 py-3 text-slate-900">{{ $transaction->reference }}</td>
              <td class="px-4 py-3 text-slate-700">{{ $transaction->paymentMethod->name ?? 'N/A' }}</td>
              <td class="px-4 py-3 text-slate-900">₦{{ number_format($transaction->amount, 2) }}</td>
              <td class="px-4 py-3">
                @php
                  $txnStatVal = $transaction->status->value ?? ($transaction->status ?? '');
                  $txnStatColor = match($txnStatVal) {
                    'completed', 'successful', 'paid' => 'bg-emerald-50 text-emerald-700',
                    'pending' => 'bg-amber-50 text-amber-700',
                    'failed' => 'bg-red-50 text-red-700',
                    default => 'bg-slate-100 text-slate-700',
                  };
                @endphp
                <span class="inline-flex items-center rounded-full {{ $txnStatColor }} px-2.5 py-0.5 text-xs font-medium">{{ $transaction->status->label() }}</span>
              </td>
              <td class="px-4 py-3 text-slate-500">{{ \Carbon\Carbon::parse($transaction->created_at)->format('M d, Y') }}</td>
            </tr>
            @empty
            <tr>
              <td colspan="5" class="px-4 py-8 text-center text-slate-500">
                No transactions yet
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Activity Log</h3>
      </div>
      <div class="p-6">
        @forelse($activityLogs as $log)
        <div class="flex items-start gap-3 py-3 {{ !$loop->last ? 'border-b border-slate-50' : '' }}">
          <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0">
            <i class="fi fi-rr-time-past text-slate-500 text-sm"></i>
          </div>
          <div class="flex-1">
            <p class="text-sm">
              <strong class="text-slate-900">{{ $log->user ? $log->user->name : 'System' }}</strong>
              <span class="text-slate-600">{{ $log->description }}</span>
            </p>
            <span class="text-xs text-slate-400">
              {{ $log->created_at->diffForHumans() }}
              ({{ $log->created_at->format('M d, Y H:i') }})
            </span>
          </div>
        </div>
        @empty
        <p class="text-sm text-slate-500 text-center">No activity recorded</p>
        @endforelse
      </div>
    </div>
  </div>
</div>

{{-- Suspend Modal --}}
<div id="suspendModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="suspendModalLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('suspendModal')"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
      <form id="suspendForm" method="POST">
        @csrf
        <div class="flex items-center justify-between mb-4">
          <h5 class="text-lg font-semibold text-red-700" id="suspendModalLabel">
            <i class="fi fi-rr-ban mr-2"></i> Suspend Customer Account
          </h5>
          <button type="button" onclick="closeModal('suspendModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
          <strong class="text-amber-700 text-sm">Warning:</strong> <span class="text-amber-600 text-sm">This will prevent the customer from accessing their account and placing new orders.</span>
        </div>
        <p class="text-sm text-slate-700 mb-2">You are about to suspend the account for:</p>
        <p class="text-sm text-center font-semibold text-slate-900 mb-4" id="suspendCustomerName"></p>
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-700 mb-1">Reason for Suspension *</label>
          <textarea name="reason" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="4" required
                    placeholder="Please provide a detailed reason for suspending this account..."></textarea>
          <p class="text-xs text-slate-400 mt-1">The customer will receive an email with this reason.</p>
        </div>
        <div class="flex items-center justify-end gap-3">
          <button type="button" onclick="closeModal('suspendModal')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">
            <i class="fi fi-rr-ban"></i> Suspend Account
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

{{-- Activate Modal --}}
<div id="activateModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="activateModalLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('activateModal')"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
      <form id="activateForm" method="POST">
        @csrf
        <div class="flex items-center justify-between mb-4">
          <h5 class="text-lg font-semibold text-emerald-700" id="activateModalLabel">
            <i class="fi fi-rr-check-circle mr-2"></i> Activate Customer Account
          </h5>
          <button type="button" onclick="closeModal('activateModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 mb-4">
          <strong class="text-emerald-700 text-sm">Confirmation:</strong> <span class="text-emerald-600 text-sm">This will restore full access to the customer's account.</span>
        </div>
        <p class="text-sm text-slate-700 mb-2">You are about to activate the account for:</p>
        <p class="text-sm text-center font-semibold text-slate-900 mb-4" id="activateCustomerName"></p>
        <p class="text-xs text-slate-400 mb-4">The customer will receive an email notification confirming their account has been activated.</p>
        <div class="flex items-center justify-end gap-3">
          <button type="button" onclick="closeModal('activateModal')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
            <i class="fi fi-rr-check-circle"></i> Activate Account
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showSuspendModal(accountId, customerName) {
    document.getElementById('suspendCustomerName').textContent = customerName;
    document.getElementById('suspendForm').action = `/superadmin/customers/${accountId}/suspend`;
    openModal('suspendModal');
}

function showActivateModal(accountId, customerName) {
    document.getElementById('activateCustomerName').textContent = customerName;
    document.getElementById('activateForm').action = `/superadmin/customers/${accountId}/activate`;
    openModal('activateModal');
}
</script>
@endsection
