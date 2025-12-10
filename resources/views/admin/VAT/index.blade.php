@extends('admin.layout')
@section('subtitle','VAT Settings')

@section('content')
<div class="container-fluid">
  <div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h4 class="mb-0">VAT Settings</h4>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#vatCreateModal">Add VAT</button>
    </div>
  </div>

  <div class="row">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">History</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Percentage</th>
                  <th>Active</th>
                  <th>Effective At</th>
                  <th>Created</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                @forelse($vats as $row)
                  <tr>
                    <td>{{ $row->id }}</td>
                    <td>{{ number_format($row->percentage, 2) }}%</td>
                    <td>
                      <span class="badge {{ $row->active ? 'bg-success' : 'bg-secondary' }}">{{ $row->active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td>{{ $row->effective_at ? $row->effective_at->format('Y-m-d H:i') : '—' }}</td>
                    <td>{{ $row->created_at->diffForHumans() }}</td>
                    <td class="text-end">
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-primary"
                        title="Edit"
                        data-bs-toggle="modal"
                        data-bs-target="#vatEditModal"
                        data-id="{{ $row->id }}"
                        data-percentage="{{ $row->percentage }}"
                        data-effective="{{ $row->effective_at ? $row->effective_at->format('Y-m-d\\TH:i') : '' }}"
                        data-active="{{ $row->active ? 1 : 0 }}">
                        <i class="fa fa-pen"></i>
                      </button>
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-warning"
                        title="Disable (create 0% VAT)"
                        data-bs-toggle="modal"
                        data-bs-target="#vatDisableModal"
                        data-id="{{ $row->id }}">
                        <i class="fa fa-ban"></i>
                      </button>
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-danger"
                        title="Delete"
                        data-bs-toggle="modal"
                        data-bs-target="#vatDeleteModal"
                        data-id="{{ $row->id }}">
                        <i class="fa fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-center p-4">No VAT records</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer">{{ $vats->onEachSide(1)->links() }}</div>
      </div>
    </div>
  </div>
</div>

<!-- Create VAT Modal -->
<div class="modal fade" id="vatCreateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="{{ route('admin.vats.store') }}" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Percentage (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="percentage" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Effective At (optional)</label>
            <input type="datetime-local" name="effective_at" class="form-control">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="vatCActive" name="active" checked>
            <label class="form-check-label" for="vatCActive">Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
  </div>

<!-- Edit VAT Modal -->
<div class="modal fade" id="vatEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="vatEditForm" action="#" method="POST">
        @csrf @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Percentage (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="percentage" id="vatEditPercentage" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Effective At (optional)</label>
            <input type="datetime-local" name="effective_at" id="vatEditEffective" class="form-control">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="vatEditActive" name="active">
            <label class="form-check-label" for="vatEditActive">Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update</button>
        </div>
      </form>
    </div>
  </div>
  </div>

<!-- Disable VAT Modal (creates new 0% VAT) -->
<div class="modal fade" id="vatDisableModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Disable VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="vatDisableForm" action="#" method="POST">
        @csrf
        <div class="modal-body">
          <p class="mb-0">This will create a new VAT record with 0% so taxes are effectively disabled. Proceed?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Create 0% VAT</button>
        </div>
      </form>
    </div>
  </div>
  </div>

<!-- Delete VAT Modal -->
<div class="modal fade" id="vatDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Delete VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="vatDeleteForm" action="#" method="POST">
        @csrf @method('DELETE')
        <div class="modal-body">
          <p class="mb-0">Are you sure you want to delete this VAT record?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var editModal = document.getElementById('vatEditModal');
  editModal?.addEventListener('show.bs.modal', function(e){
    var src = e.relatedTarget; if(!src) return;
    var b = src.closest('[data-id]') || src;
    var id = b.getAttribute('data-id');
    var pct = b.getAttribute('data-percentage');
    var eff = b.getAttribute('data-effective');
    var act = b.getAttribute('data-active') === '1';
    document.getElementById('vatEditPercentage').value = pct || '';
    document.getElementById('vatEditEffective').value = eff || '';
    document.getElementById('vatEditActive').checked = act;
    document.getElementById('vatEditForm').action = '{{ url('/superadmin/vats') }}/'+id;
  });
  var delModal = document.getElementById('vatDeleteModal');
  delModal?.addEventListener('show.bs.modal', function(e){
    var src = e.relatedTarget; var b = src?.closest('[data-id]') || src; 
    var id = b?.getAttribute('data-id');
    document.getElementById('vatDeleteForm').action = '{{ url('/superadmin/vats') }}/'+id;
  });
  var disModal = document.getElementById('vatDisableModal');
  disModal?.addEventListener('show.bs.modal', function(e){
    var src = e.relatedTarget; var b = src?.closest('[data-id]') || src; 
    var id = b?.getAttribute('data-id');
    // Submit to toggle route; controller will create 0% VAT
    document.getElementById('vatDisableForm').action = '{{ url('/superadmin/vats') }}/'+id+'/toggle';
  });
});
</script>

@endsection
