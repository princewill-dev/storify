@extends('admin.layout')
@section('subtitle', 'Delivery Routes')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Delivery Routes</h2>
  <button type="button" onclick="openModal('routeCreateModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Add Route</button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <table class="w-full text-sm">
    <thead class="border-b border-slate-100">
      <tr>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Country</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">State</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Area</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Fee</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Delivery Days</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Updated</th>
        <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-50">
      @forelse($routes as $row)
        <tr class="hover:bg-slate-50/50">
          <td class="py-3 px-4 text-slate-500 text-xs">{{ $row->id }}</td>
          <td class="py-3 px-4 text-slate-700">{{ $row->country }}</td>
          <td class="py-3 px-4 text-slate-700">{{ $row->state }}</td>
          <td class="py-3 px-4 text-slate-700">{{ $row->area }}</td>
          <td class="py-3 px-4 text-slate-700 font-mono">&#8358;{{ number_format($row->fee/100, 2) }}</td>
          <td class="py-3 px-4 text-slate-700">{{ $row->delivery_days }}</td>
          <td class="py-3 px-4">
            <span class="inline-flex items-center rounded-full {{ $row->active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">{{ $row->active ? 'Active' : 'Inactive' }}</span>
          </td>
          <td class="py-3 px-4 text-xs text-slate-500">{{ $row->updated_at->diffForHumans() }}</td>
          <td class="py-3 px-4 text-right" x-data="{ open: false }">
            <div class="relative inline-block">
              <button @click="open = !open" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                <i class="fi fi-rr-menu-dots text-sm"></i>
              </button>
              <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-36 bg-white rounded-lg shadow-lg border border-slate-200 py-1">
                <button @click="open = false" onclick="prepareEditRoute('{{ $row->id }}','{{ addslashes($row->country) }}','{{ addslashes($row->state) }}','{{ addslashes($row->area) }}','{{ (int)($row->fee/100) }}','{{ $row->delivery_days }}','{{ $row->active ? 1 : 0 }}')" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                  <i class="fi fi-rr-pencil text-slate-400 text-xs"></i> Edit
                </button>
                <button @click="open = false" onclick="prepareToggleRoute('{{ $row->id }}','{{ $row->active ? 1 : 0 }}')" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                  @if($row->active)
                    <i class="fi fi-rr-ban text-amber-500 text-xs"></i> Disable
                  @else
                    <i class="fi fi-rr-check text-emerald-500 text-xs"></i> Enable
                  @endif
                </button>
                <button @click="open = false" onclick="prepareDeleteRoute('{{ $row->id }}')" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left">
                  <i class="fi fi-rr-trash text-red-400 text-xs"></i> Delete
                </button>
              </div>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="9" class="py-12 text-center text-slate-400">No routes</td></tr>
      @endforelse
    </tbody>
  </table>
  <div class="px-4 py-3 border-t border-slate-100">{{ $routes->onEachSide(1)->links() }}</div>
</div>

<!-- Create Route Modal -->
<div id="routeCreateModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('routeCreateModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Add Route</h5>
        <button onclick="closeModal('routeCreateModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form action="{{ route('admin.delivery-routes.store') }}" method="POST" class="space-y-4">
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
            <input type="text" name="country" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="Nigeria" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">State</label>
            <input type="text" name="state" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Area</label>
            <input type="text" name="area" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Fee (NGN)</label>
            <input type="number" min="0" step="1" name="fee" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Delivery Days</label>
            <input type="number" min="1" max="60" name="delivery_days" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="3" required>
          </div>
          <div class="flex items-end pb-2">
            <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
              <input type="checkbox" value="1" name="active" checked class="rounded border-slate-300 text-slate-900 shadow-sm focus:ring-slate-500">
              Active
            </label>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('routeCreateModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Route Modal -->
<div id="routeEditModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('routeEditModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Edit Route</h5>
        <button onclick="closeModal('routeEditModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form id="routeEditForm" action="#" method="POST" class="space-y-4">
        @csrf @method('PUT')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
            <input type="text" id="routeECountry" name="country" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">State</label>
            <input type="text" id="routeEState" name="state" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Area</label>
            <input type="text" id="routeEArea" name="area" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Fee (NGN)</label>
            <input type="number" min="0" step="1" id="routeEFee" name="fee" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Delivery Days</label>
            <input type="number" min="1" max="60" id="routeEDays" name="delivery_days" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          </div>
          <div class="flex items-end pb-2">
            <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
              <input type="checkbox" value="1" id="routeEActive" name="active" class="rounded border-slate-300 text-slate-900 shadow-sm focus:ring-slate-500">
              Active
            </label>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('routeEditModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toggle Route Modal -->
<div id="routeToggleModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('routeToggleModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-sm p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Change Route Status</h5>
        <button onclick="closeModal('routeToggleModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form id="routeToggleForm" action="#" method="POST" class="space-y-4">
        @csrf
        <p id="routeToggleText" class="text-sm text-slate-600"></p>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('routeToggleModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600" id="routeToggleBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Route Modal -->
<div id="routeDeleteModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('routeDeleteModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-sm p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Delete Route</h5>
        <button onclick="closeModal('routeDeleteModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form id="routeDeleteForm" action="#" method="POST" class="space-y-4">
        @csrf @method('DELETE')
        <p class="text-sm text-slate-600">Are you sure you want to delete this delivery route?</p>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('routeDeleteModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
    function prepareEditRoute(id, country, state, area, fee, days, active) {
        document.getElementById('routeECountry').value = country || '';
        document.getElementById('routeEState').value = state || '';
        document.getElementById('routeEArea').value = area || '';
        document.getElementById('routeEFee').value = fee || 0;
        document.getElementById('routeEDays').value = days || 3;
        document.getElementById('routeEActive').checked = (active === '1');
        document.getElementById('routeEditForm').action = '{{ url('/superadmin/delivery-routes') }}/' + id;
        openModal('routeEditModal');
    }

    function prepareToggleRoute(id, active) {
        document.getElementById('routeToggleText').textContent = (active === '1') ? 'Disable this route?' : 'Enable this route?';
        document.getElementById('routeToggleBtn').textContent = (active === '1') ? 'Disable' : 'Enable';
        document.getElementById('routeToggleForm').action = '{{ url('/superadmin/delivery-routes') }}/' + id + '/toggle';
        openModal('routeToggleModal');
    }

    function prepareDeleteRoute(id) {
        document.getElementById('routeDeleteForm').action = '{{ url('/superadmin/delivery-routes') }}/' + id;
        openModal('routeDeleteModal');
    }
</script>
@endsection
