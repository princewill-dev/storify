@extends('admin.layout')
@section('subtitle', 'Customers')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Customer Management</h2>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-users-alt text-blue-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Customers</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['total']) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-check-circle text-emerald-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Active</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['active']) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-ban text-red-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Suspended</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['suspended']) }}</div>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-lg bg-sky-50 flex items-center justify-center flex-shrink-0">
        <i class="fi fi-rr-shopping-cart text-sky-600 text-lg"></i>
      </div>
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Orders</div>
        <div class="text-xl font-bold text-slate-900">{{ number_format($stats['total_orders']) }}</div>
      </div>
    </div>
  </div>
</div>

<div class="flex items-center justify-between mb-4">
  <div>
    @if(request()->hasAny(['search', 'status', 'country']))
      <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 mr-2">
        <i class="fi fi-rr-settings-sliders mr-1"></i> Filters Active
      </span>
      <a href="{{ route('admin.customers.index') }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
        <i class="fi fi-rr-cross-small"></i> Clear Filters
      </a>
    @endif
  </div>
  <button type="button" onclick="openModal('filterModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
    <i class="fi fi-rr-settings-sliders"></i> Filter Customers
  </button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="px-4 py-3 border-b border-slate-100">
    <h3 class="text-sm font-semibold text-slate-900">All Customers</h3>
  </div>
  
    <table class="w-full text-sm">
      <thead class="border-b border-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Phone</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Location</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Orders</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Joined</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($customers as $customer)
        <tr class="hover:bg-slate-50/50">
          <td class="px-4 py-3">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs flex-shrink-0">
                {{ strtoupper(substr($customer->first_name, 0, 1)) }}{{ strtoupper(substr($customer->last_name, 0, 1)) }}
              </div>
              <div>
                <div class="font-medium text-slate-900">{{ $customer->full_name }}</div>
                @if($customer->company_name)
                <div class="text-xs text-slate-400">{{ $customer->company_name }}</div>
                @endif
                <div class="text-xs text-slate-400">{{ $customer->account_id }}</div>
              </div>
            </div>
          </td>
          <td class="px-4 py-3 text-slate-700">{{ $customer->email }}</td>
          <td class="px-4 py-3 text-slate-700">{{ $customer->phone ?? '-' }}</td>
          <td class="px-4 py-3 text-slate-700">{{ $customer->location ?? '-' }}</td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600 border border-slate-200">{{ $customer->orders_count }} orders</span>
          </td>
          <td class="px-4 py-3">
            @php($customerBadge = $statusBadgeData[$customer->status] ?? null)
            <span class="inline-flex items-center rounded-full {{ $customerBadge['class'] ?? 'bg-slate-100 text-slate-700' }} px-2.5 py-0.5 text-xs font-medium border">
              {{ $customerBadge['label'] ?? ucfirst(strtolower($customer->status)) }}
            </span>
          </td>
          <td class="px-4 py-3 text-slate-500">{{ $customer->created_at->format('M d, Y') }}</td>
          <td class="px-4 py-3">
            <div class="relative inline-block text-left" x-data="{ open: false }">
              <button @click="open = !open" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Customer actions">
                <i class="fi fi-rr-menu-dots-vertical text-sm"></i>
              </button>
              <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 z-10 mt-1 w-44 bg-white rounded-lg shadow-lg border border-slate-200 py-1">
                <a class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" href="{{ route('admin.customers.show', $customer) }}">
                  <i class="fi fi-rr-eye text-slate-400 text-sm"></i> View
                </a>
                <a class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" href="{{ route('admin.customers.edit', $customer) }}">
                  <i class="fi fi-rr-pencil text-slate-400 text-sm"></i> Edit
                </a>
                @if($customer->status === \App\Models\Customer::STATUS_ACTIVE)
                  <button class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" type="button"
                          onclick="showSuspendModal({{ Js::from($customer->account_id) }}, {{ Js::from($customer->full_name) }})">
                    <i class="fi fi-rr-ban text-slate-400 text-sm"></i> Suspend
                  </button>
                @elseif($customer->status === \App\Models\Customer::STATUS_SUSPENDED)
                  <button class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" type="button"
                          onclick="showActivateModal({{ Js::from($customer->account_id) }}, {{ Js::from($customer->full_name) }})">
                    <i class="fi fi-rr-check text-slate-400 text-sm"></i> Activate
                  </button>
                @endif
              </div>
            </div>
          </td>
        </tr>
        @empty
        <tr>
          <td colspan="8" class="px-4 py-12 text-center">
            <i class="fi fi-rr-users-alt text-5xl text-slate-200 block mb-3"></i>
            <p class="text-slate-500">No customers found</p>
          </td>
        </tr>
        @endforelse
      </tbody>
    </table>

  @if($customers->hasPages())
  <div class="px-4 py-3 border-t border-slate-100">
    {{ $customers->links() }}
  </div>
  @endif
</div>

{{-- Filter Modal --}}
<div id="filterModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="filterModalLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('filterModal')"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-xl w-full p-6">
      <div class="flex items-center justify-between mb-6">
        <h5 class="text-lg font-semibold text-slate-900" id="filterModalLabel">Filter Customers</h5>
        <button type="button" onclick="closeModal('filterModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
      </div>
      <form method="GET" action="{{ route('admin.customers.index') }}">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
            <input type="text" name="search" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Name, email, phone, or account ID..." value="{{ request('search') }}">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All Statuses</option>
              <option value="ACTIVE" {{ request('status') === 'ACTIVE' ? 'selected' : '' }}>Active</option>
              <option value="SUSPENDED" {{ request('status') === 'SUSPENDED' ? 'selected' : '' }}>Suspended</option>
              <option value="DELETED" {{ request('status') === 'DELETED' ? 'selected' : '' }}>Deleted</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
            <select name="country" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All Countries</option>
              @foreach($countries as $country)
              <option value="{{ $country }}" {{ request('country') === $country ? 'selected' : '' }}>{{ $country }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
          <a href="{{ route('admin.customers.index') }}" class="inline-flex items-center gap-1 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
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
