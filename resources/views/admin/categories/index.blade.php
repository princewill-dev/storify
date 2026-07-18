@extends('admin.layout')
@section('subtitle', 'Categories')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Categories</h2>
  <div class="flex items-center gap-2">
    <button type="button" onclick="openModal('createCategoryModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">New category</button>
    @if(!empty($store))
      <a href="{{ route('admin.stores.show', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Back</a>
    @else
      <a href="{{ route('admin.categories.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Back</a>
    @endif
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <table class="w-full text-sm">
    <thead class="border-b border-slate-100">
      <tr>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-50">
      @forelse($categories as $c)
        <tr class="hover:bg-slate-50/50">
          <td class="py-3 px-4 text-slate-700">{{ $c->store?->name }}</td>
          <td class="py-3 px-4 text-slate-700">{{ $c->name }}</td>
          <td class="py-3 px-4">
            <span class="inline-flex items-center rounded-full {{ $c->status==='active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">{{ $c->status }}</span>
          </td>
          <td class="py-3 px-4 text-right">
            <a href="{{ route('admin.categories.edit', $c) }}" class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="Edit">
              <i class="fi fi-rr-pencil text-sm"></i>
            </a>
            <button type="button" class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete" onclick="prepareDeleteCategory('{{ route('admin.categories.destroy', $c) }}', '{{ addslashes($c->name) }}')">
              <i class="fi fi-rr-trash text-sm"></i>
            </button>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="py-12 text-center text-slate-400">No categories</td></tr>
      @endforelse
    </tbody>
  </table>
  <div class="px-4 py-3 border-t border-slate-100">{{ $categories->links() }}</div>
</div>

<div id="deleteCategoryModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteCategoryModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Delete category</h5>
        <button onclick="closeModal('deleteCategoryModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" id="deleteCategoryForm">
        @csrf
        @method('DELETE')
        <p class="text-sm text-slate-600 mb-4">Are you sure you want to delete <strong id="del-cat-name" class="text-slate-800"></strong>?</p>
        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" onclick="closeModal('deleteCategoryModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="createCategoryModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createCategoryModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">New category</h5>
        <button onclick="closeModal('createCategoryModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" action="{{ route('admin.categories.store') }}" class="space-y-4">
        @csrf
        @if(!empty($store))
          <input type="hidden" name="store_id" value="{{ $store->id }}">
        @else
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
            <select name="store_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
              <option value="">Select store</option>
              @foreach(($stores ?? collect()) as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
              @endforeach
            </select>
          </div>
        @endif
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
            <option value="active">active</option>
            <option value="inactive">inactive</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" onclick="closeModal('createCategoryModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function prepareDeleteCategory(action, name) {
  var form = document.getElementById('deleteCategoryForm');
  var nameEl = document.getElementById('del-cat-name');
  if (form) form.setAttribute('action', action);
  if (nameEl) nameEl.textContent = name || '';
  openModal('deleteCategoryModal');
}
</script>
@endsection
