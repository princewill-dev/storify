@extends('admin.layout')
@section('title', 'Superadmin')
@section('subtitle', 'products')

@section('content')
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <h2 class="text-lg font-bold text-slate-900">Products</h2>
    <button type="button" onclick="openModal('filterProductsModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
      <i class="fi fi-rr-settings-sliders text-sm"></i> Filter
    </button>
  </div>
  <div class="flex items-center gap-2">
    @if(!empty($store))
      <a href="{{ route('admin.stores.product.create', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">New product</a>
      <a href="{{ route('admin.stores.show', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
    @else
      <a href="{{ route('admin.products.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">New product</a>
      <a href="{{ route('admin.products.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
    @endif
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="p-4 border-b border-slate-100">
    <div class="flex items-center justify-between">
      <div class="text-sm text-slate-500">Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}</div>
      <form method="get" class="flex items-center gap-2 text-sm">
        @foreach(request()->except('per_page','page') as $k => $v)
          <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endforeach
        <label for="per_page" class="text-slate-600">Per page</label>
        <select id="per_page" name="per_page" class="rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" onchange="this.form.submit()">
          <option value="10" @selected(($perPage ?? 10) == 10)>10</option>
          <option value="50" @selected(($perPage ?? 10) == 50)>50</option>
          <option value="100" @selected(($perPage ?? 10) == 100)>100</option>
        </select>
      </form>
    </div>
  </div>

  
    <table class="w-full text-sm">
      <thead class="border-b border-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Image</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Category</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Featured</th>
          <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($products as $p)
          <tr class="hover:bg-slate-50/50">
            <td class="px-4 py-3">
              @if(!empty($productImages[$p->id] ?? null))
                <img src="{{ $productImages[$p->id] }}" alt="{{ $p->name }}" class="w-10 h-10 rounded-lg object-cover">
              @else
                <span class="text-slate-400 text-xs">N/A</span>
              @endif
            </td>
            <td class="px-4 py-3"><code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">{{ $p->product_code }}</code></td>
            <td class="px-4 py-3 font-medium text-slate-900">{{ $p->name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $p->store?->name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $p->category?->name ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-900">{{ $displayPrices[$p->id] ?? '—' }}</td>
            <td class="px-4 py-3">
              @if($p->status === 'active')
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
              @else
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
              @endif
            </td>
            <td class="px-4 py-3">
              @if($p->featured)
                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Featured</span>
              @else
                <span class="text-slate-400 text-xs">—</span>
              @endif
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <a class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" href="{{ isset($store) ? route('admin.stores.products.show', ['store' => $store, 'code' => $p->product_code]) : route('admin.products.show', $p) }}" title="View">
                  <i class="fi fi-rr-eye text-sm"></i>
                </a>
                <a class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-blue-600" href="{{ route('admin.products.edit', $p) }}" title="Edit">
                  <i class="fi fi-rr-pencil text-sm"></i>
                </a>
                @if($p->status === 'active')
                  <button type="button" onclick="openModal('deactivateModal{{ $p->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-amber-50 hover:text-amber-600" title="Deactivate">
                    <i class="fi fi-rr-ban text-sm"></i>
                  </button>
                @else
                  <button type="button" onclick="openModal('activateModal{{ $p->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-emerald-50 hover:text-emerald-600" title="Activate">
                    <i class="fi fi-rr-check text-sm"></i>
                  </button>
                @endif
                <button type="button" onclick="openModal('deleteModal{{ $p->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600" title="Delete">
                  <i class="fi fi-rr-trash text-sm"></i>
                </button>
              </div>
            </td>
          </tr>

          {{-- Activate Modal --}}
          <div id="activateModal{{ $p->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="activateModalLabel{{ $p->id }}" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('activateModal{{ $p->id }}')"></div>
            <div class="relative min-h-screen flex items-center justify-center p-4">
              <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4">
                  <h5 class="text-lg font-semibold text-slate-900" id="activateModalLabel{{ $p->id }}">Activate product</h5>
                  <button type="button" onclick="closeModal('activateModal{{ $p->id }}')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <p class="text-slate-600 mb-6">Are you sure you want to activate <strong>{{ $p->name }}</strong>?</p>
                <div class="flex items-center justify-end gap-3">
                  <button type="button" onclick="closeModal('activateModal{{ $p->id }}')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                  <form method="post" action="{{ route('admin.products.status', $p) }}" class="inline">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="active">
                    <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Yes, Activate</button>
                  </form>
                </div>
              </div>
            </div>
          </div>

          {{-- Deactivate Modal --}}
          <div id="deactivateModal{{ $p->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="deactivateModalLabel{{ $p->id }}" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('deactivateModal{{ $p->id }}')"></div>
            <div class="relative min-h-screen flex items-center justify-center p-4">
              <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4">
                  <h5 class="text-lg font-semibold text-slate-900" id="deactivateModalLabel{{ $p->id }}">Deactivate product</h5>
                  <button type="button" onclick="closeModal('deactivateModal{{ $p->id }}')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <p class="text-slate-600 mb-6">Are you sure you want to deactivate <strong>{{ $p->name }}</strong>?</p>
                <div class="flex items-center justify-end gap-3">
                  <button type="button" onclick="closeModal('deactivateModal{{ $p->id }}')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                  <form method="post" action="{{ route('admin.products.status', $p) }}" class="inline">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="inactive">
                    <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-amber-600 text-white hover:bg-amber-700">Yes, Deactivate</button>
                  </form>
                </div>
              </div>
            </div>
          </div>

          {{-- Delete Modal --}}
          <div id="deleteModal{{ $p->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="deleteModalLabel{{ $p->id }}" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('deleteModal{{ $p->id }}')"></div>
            <div class="relative min-h-screen flex items-center justify-center p-4">
              <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4">
                  <h5 class="text-lg font-semibold text-slate-900" id="deleteModalLabel{{ $p->id }}">Delete product</h5>
                  <button type="button" onclick="closeModal('deleteModal{{ $p->id }}')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <p class="text-slate-600 mb-6">This action cannot be undone. Delete <strong>{{ $p->name }}</strong>?</p>
                <div class="flex items-center justify-end gap-3">
                  <button type="button" onclick="closeModal('deleteModal{{ $p->id }}')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                  <form method="post" action="{{ route('admin.products.destroy', $p) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Yes, Delete</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        @empty
          <tr>
            <td colspan="9" class="px-4 py-12 text-center text-slate-500">No products yet</td>
          </tr>
        @endforelse
      </tbody>
    </table>


  <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
    <div class="text-sm text-slate-500">Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}</div>
    <div>{{ $products->appends(['per_page' => ($perPage ?? 10)])->links('general.pagination.only-links') }}</div>
  </div>
</div>

{{-- Filter Products Modal --}}
<div id="filterProductsModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="filterProductsLabel" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('filterProductsModal')"></div>
  <div class="relative min-h-screen flex items-center justify-center p-4">
    <div class="relative bg-white rounded-xl shadow-xl max-w-2xl w-full p-6">
      <div class="flex items-center justify-between mb-6">
        <h5 class="text-lg font-semibold text-slate-900" id="filterProductsLabel">Filter Products</h5>
        <button type="button" onclick="closeModal('filterProductsModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
      </div>
      <form method="GET">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
              <option value="">All</option>
              <option value="active" @selected(($status ?? '')==='active')>Active</option>
              <option value="inactive" @selected(($status ?? '')==='inactive')>Inactive</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
            <input type="text" name="q" value="{{ $q ?? '' }}" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Name, code, store or category">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">From</label>
            <input type="date" name="from" value="{{ $from ?? '' }}" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">To</label>
            <input type="date" name="to" value="{{ $to ?? '' }}" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
          <a href="{{ route('admin.products.index') }}" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Reset</a>
          <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Apply Filters</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
