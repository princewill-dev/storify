@extends('admin.layout')
@section('subtitle', 'Company Services')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Company Services</h2>
  <button type="button" onclick="openModal('createServiceModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">New service</button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="px-6 py-4 border-b border-slate-100">
    <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-sky-50 border border-sky-200 text-sm text-sky-700">
      <i class="fi fi-rr-info text-sky-500"></i>
      <strong>Drag & Drop</strong> to reorder services. Changes are saved automatically.
    </div>
  </div>

  <table class="w-full text-sm">
    <thead class="border-b border-slate-100">
      <tr>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-[50px]">#</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Title</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Page Link</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
      </tr>
    </thead>
    <tbody id="sortable-services" class="divide-y divide-slate-50">
      @forelse($services as $s)
        <tr data-id="{{ $s->id }}" class="cursor-move hover:bg-slate-50/50">
          <td class="py-3 px-4">
            <div class="flex items-center gap-2">
              <i class="fi fi-rr-menu-dots text-slate-300"></i>
              <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ $s->order }}</span>
            </div>
          </td>
          <td class="py-3 px-4 text-slate-700">{{ $s->title }}</td>
          <td class="py-3 px-4">
            @if($s->page_link)
              <code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded text-slate-600">/{{ $s->page_link }}</code>
            @else
              <span class="text-slate-400">—</span>
            @endif
          </td>
          <td class="py-3 px-4">
            <span class="inline-flex items-center rounded-full {{ $s->status==='active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">{{ $s->status }}</span>
          </td>
          <td class="py-3 px-4 text-right">
            <div class="flex items-center justify-end gap-1">
              <a href="{{ $s->page_link ? url('/'.$s->page_link) : '#' }}" target="_blank" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 {{ !$s->page_link ? 'opacity-40 pointer-events-none' : '' }}" title="Visit">
                <i class="fi fi-rr-arrow-up-right text-sm"></i>
              </a>
              <button type="button" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-600 hover:text-slate-800 hover:bg-slate-100" title="Edit"
                onclick="prepareEditService('{{ $s->id }}','{{ addslashes($s->title) }}','{{ addslashes($s->description) }}','{{ $s->page_link }}','{{ $s->status }}','{{ $s->background_image_path ? asset('storage/'.$s->background_image_path) : '' }}','{{ $s->order }}')">
                <i class="fi fi-rr-pencil text-sm"></i>
              </button>
              <button type="button" class="inline-flex items-center justify-center p-2 rounded-lg {{ $s->status==='active' ? 'text-emerald-500 hover:bg-emerald-50' : 'text-slate-400 hover:bg-slate-100' }}" title="{{ $s->status==='active' ? 'Deactivate' : 'Activate' }}"
                onclick="prepareToggleService('{{ route('admin.company-services.toggle', $s) }}','{{ addslashes($s->title) }}','{{ $s->status }}')">
                @if($s->status==='active')
                  <i class="fi fi-rr-toggle-on text-lg"></i>
                @else
                  <i class="fi fi-rr-toggle-off text-lg"></i>
                @endif
              </button>
              <button type="button" class="inline-flex items-center justify-center p-2 rounded-lg text-red-600 hover:bg-red-50" title="Delete"
                onclick="prepareDeleteService('{{ route('admin.company-services.destroy', $s) }}','{{ addslashes($s->title) }}')">
                <i class="fi fi-rr-trash text-sm"></i>
              </button>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="py-12 text-center text-slate-400">No services found</td></tr>
      @endforelse
    </tbody>
  </table>
  <div class="px-4 py-3 border-t border-slate-100">{{ $services->links() }}</div>
</div>

<!-- Create Modal -->
<div id="createServiceModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createServiceModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">New service</h5>
        <button onclick="closeModal('createServiceModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" action="{{ route('admin.company-services.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @if($errors->any())
          <div class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
            <ul class="list-disc pl-4">
              @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
        @endif
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Display Order</label>
          <input type="number" name="order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('order') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('order', 0) }}" min="0">
          <p class="mt-1 text-xs text-slate-400">Lower numbers appear first (e.g., 1, 2, 3...)</p>
          @error('order')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
          <input type="text" name="title" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('title') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('title') }}" required>
          @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea name="description" rows="3" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('description') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror">{{ old('description') }}</textarea>
          @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Page link</label>
          <div class="flex items-center">
            <span class="inline-flex items-center px-3 py-2.5 text-sm border border-r-0 border-slate-300 rounded-l-lg bg-slate-50 text-slate-500">{{ url('/') }}/</span>
            <input type="text" name="page_link" class="flex-1 rounded-r-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('page_link') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('page_link') }}" placeholder="shop4me or main_store">
          </div>
          <p class="mt-1 text-xs text-slate-400">Enter a unique path without leading slash. Example: <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600">shop4me</code></p>
          @error('page_link')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Background image</label>
          <input type="file" name="background_image" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 @error('background_image') border-red-300 @enderror" accept=".jpg,.jpeg,.png,.webp">
          <p class="mt-1 text-xs text-slate-400">Accepted: jpg, jpeg, png, webp. Max size 10MB.</p>
          @error('background_image')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('status') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required>
            <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>active</option>
            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>inactive</option>
          </select>
          @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('createServiceModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div id="editServiceModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editServiceModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Edit service</h5>
        <button onclick="closeModal('editServiceModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" id="editServiceForm" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Display Order</label>
          <input type="number" name="order" id="edit-order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="0">
          <p class="mt-1 text-xs text-slate-400">Lower numbers appear first (e.g., 1, 2, 3...)</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
          <input type="text" name="title" id="edit-title" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea name="description" id="edit-description" rows="3" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Page link</label>
          <div class="flex items-center">
            <span class="inline-flex items-center px-3 py-2.5 text-sm border border-r-0 border-slate-300 rounded-l-lg bg-slate-50 text-slate-500">{{ url('/') }}/</span>
            <input type="text" name="page_link" id="edit-page_link" class="flex-1 rounded-r-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="shop4me or main_store">
          </div>
          <p class="mt-1 text-xs text-slate-400">Enter a unique path without leading slash. Example: <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600">shop4me</code></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Background image</label>
          <input type="file" name="background_image" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".jpg,.jpeg,.png,.webp">
          <p class="mt-1 text-xs text-slate-400">Accepted: jpg, jpeg, png, webp. Max size 10MB. Uploading a new file will replace the current one.</p>
          <div class="mt-2">
            <small class="block text-xs text-slate-400">Current:</small>
            <div id="edit-bg-preview" class="mt-1 w-[180px] h-20 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden bg-cover bg-center">
              <span class="text-xs text-slate-400">No image</span>
            </div>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select name="status" id="edit-status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
            <option value="active">active</option>
            <option value="inactive">inactive</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('editServiceModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toggle Status Modal -->
<div id="toggleServiceModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('toggleServiceModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-sm p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Change status</h5>
        <button onclick="closeModal('toggleServiceModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" id="toggleServiceForm" class="space-y-4">
        @csrf
        <p class="text-sm text-slate-600">Are you sure you want to <strong id="toggle-action" class="text-slate-800"></strong> <strong id="toggle-title" class="text-slate-800"></strong>?</p>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('toggleServiceModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div id="deleteServiceModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteServiceModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-sm p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Delete service</h5>
        <button onclick="closeModal('deleteServiceModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="post" id="deleteServiceForm" class="space-y-4">
        @csrf
        @method('DELETE')
        <p class="text-sm text-slate-600">Are you sure you want to delete <strong id="delete-title" class="text-slate-800"></strong>?</p>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('deleteServiceModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  function prepareEditService(id, title, description, pageLink, status, order, bg) {
    var form = document.getElementById('editServiceForm');
    if (form) form.setAttribute('action', '{{ url('superadmin/company-services') }}' + '/' + id);
    document.getElementById('edit-order').value = order;
    document.getElementById('edit-title').value = title;
    document.getElementById('edit-description').value = description.replace(/^\"|\"$/g, '');
    document.getElementById('edit-page_link').value = pageLink;
    document.getElementById('edit-status').value = status;
    var prev = document.getElementById('edit-bg-preview');
    if (prev) {
      if (bg) {
        prev.innerHTML = '';
        prev.style.backgroundImage = 'url(' + bg + ')';
        prev.style.backgroundSize = 'cover';
        prev.style.backgroundPosition = 'center';
      } else {
        prev.style.backgroundImage = 'none';
        prev.innerHTML = '<span class="text-xs text-slate-400">No image</span>';
      }
    }
    openModal('editServiceModal');
  }

  function prepareToggleService(action, title, status) {
    var form = document.getElementById('toggleServiceForm');
    var actionEl = document.getElementById('toggle-action');
    var titleEl = document.getElementById('toggle-title');
    if (form) form.setAttribute('action', action);
    if (actionEl) actionEl.textContent = status === 'active' ? 'deactivate' : 'activate';
    if (titleEl) titleEl.textContent = title;
    openModal('toggleServiceModal');
  }

  function prepareDeleteService(action, title) {
    var form = document.getElementById('deleteServiceForm');
    var titleEl = document.getElementById('delete-title');
    if (form) form.setAttribute('action', action);
    if (titleEl) titleEl.textContent = title;
    openModal('deleteServiceModal');
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Auto-open create modal if there are validation errors
    @if($errors->any() && !request()->has('_method'))
      openModal('createServiceModal');
    @endif

    // Initialize drag-and-drop sorting
    var sortableEl = document.getElementById('sortable-services');
    if (sortableEl && typeof Sortable !== 'undefined') {
      var sortable = Sortable.create(sortableEl, {
        animation: 150,
        handle: 'tr',
        ghostClass: 'opacity-40',
        dragClass: 'shadow-lg bg-white',
        onEnd: function (evt) {
          var items = [];
          var rows = sortableEl.querySelectorAll('tr[data-id]');
          rows.forEach(function(row, index) {
            items.push({
              id: row.getAttribute('data-id'),
              order: index + 1
            });
          });

          fetch('{{ route("admin.company-services.reorder") }}', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ items: items })
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              rows.forEach(function(row, index) {
                var badge = row.querySelector('span.inline-flex.bg-slate-100');
                if (badge) badge.textContent = index + 1;
              });
              showToast('Order updated successfully!', 'success');
            } else {
              showToast('Failed to update order', 'error');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update order', 'error');
          });
        }
      });
    }

    function showToast(message, type) {
      var toast = document.createElement('div');
      toast.className = 'fixed top-4 right-4 z-50 px-4 py-2 rounded-lg shadow-lg text-sm text-white ' + (type === 'success' ? 'bg-emerald-600' : 'bg-red-600');
      toast.textContent = message;
      document.body.appendChild(toast);
      setTimeout(function() {
        toast.remove();
      }, 3000);
    }
  });
</script>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
@endsection
