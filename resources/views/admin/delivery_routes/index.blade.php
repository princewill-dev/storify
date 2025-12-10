@extends('admin.layout')
@section('subtitle','Delivery Routes')

@section('content')
<div class="container-fluid">
  <div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h4 class="mb-0">Delivery Routes</h4>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#routeCreateModal">Add Route</button>
    </div>
  </div>

  <div class="row">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">All Routes</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Country</th>
                  <th>State</th>
                  <th>Area</th>
                  <th>Fee</th>
                  <th>Delivery Days</th>
                  <th>Status</th>
                  <th>Updated</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                @forelse($routes as $row)
                  <tr>
                    <td>{{ $row->id }}</td>
                    <td>{{ $row->country }}</td>
                    <td>{{ $row->state }}</td>
                    <td>{{ $row->area }}</td>
                    <td>₦{{ number_format($row->fee/100, 2) }}</td>
                    <td>{{ $row->delivery_days }}</td>
                    <td>
                      <span class="badge {{ $row->active ? 'bg-success' : 'bg-secondary' }}">{{ $row->active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td>{{ $row->updated_at->diffForHumans() }}</td>
                    <td class="text-end">
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-primary"
                        title="Edit"
                        data-bs-toggle="modal"
                        data-bs-target="#routeEditModal"
                        data-id="{{ $row->id }}"
                        data-country="{{ $row->country }}"
                        data-state="{{ $row->state }}"
                        data-area="{{ $row->area }}"
                        data-fee="{{ (int)($row->fee/100) }}"
                        data-days="{{ $row->delivery_days }}"
                        data-active="{{ $row->active ? 1 : 0 }}">
                        <i class="fa fa-pen"></i>
                      </button>
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-warning"
                        title="{{ $row->active ? 'Disable' : 'Enable' }}"
                        data-bs-toggle="modal"
                        data-bs-target="#routeToggleModal"
                        data-id="{{ $row->id }}"
                        data-active="{{ $row->active ? 1 : 0 }}">
                        <i class="fa {{ $row->active ? 'fa-ban' : 'fa-check' }}"></i>
                      </button>
                      <button class="btn btn-sm p-1 border-0 bg-transparent text-danger"
                        title="Delete"
                        data-bs-toggle="modal"
                        data-bs-target="#routeDeleteModal"
                        data-id="{{ $row->id }}">
                        <i class="fa fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="9" class="text-center p-4">No routes</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer">{{ $routes->onEachSide(1)->links() }}</div>
      </div>
    </div>
  </div>
</div>

<!-- Create Route Modal -->
<div class="modal fade" id="routeCreateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Route</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="{{ route('admin.delivery-routes.store') }}" method="POST">
        @csrf
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Country</label><input type="text" name="country" class="form-control" value="Nigeria" required></div>
            <div class="col-md-4"><label class="form-label">State</label><input type="text" name="state" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Area</label><input type="text" name="area" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Fee (NGN)</label><input type="number" min="0" step="1" name="fee" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Delivery Days</label><input type="number" min="1" max="60" name="delivery_days" class="form-control" value="3" required></div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="routeCActive" name="active" checked>
                <label class="form-check-label" for="routeCActive">Active</label>
              </div>
            </div>
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

<!-- Edit Route Modal -->
<div class="modal fade" id="routeEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Route</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="routeEditForm" action="#" method="POST">
        @csrf @method('PUT')
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Country</label><input type="text" id="routeECountry" name="country" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">State</label><input type="text" id="routeEState" name="state" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Area</label><input type="text" id="routeEArea" name="area" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Fee (NGN)</label><input type="number" min="0" step="1" id="routeEFee" name="fee" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Delivery Days</label><input type="number" min="1" max="60" id="routeEDays" name="delivery_days" class="form-control" required></div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="routeEActive" name="active">
                <label class="form-check-label" for="routeEActive">Active</label>
              </div>
            </div>
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

<!-- Toggle Route Modal -->
<div class="modal fade" id="routeToggleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Change Route Status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="routeToggleForm" action="#" method="POST">
        @csrf
        <div class="modal-body"><p id="routeToggleText" class="mb-0"></p></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning" id="routeToggleBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
  </div>

<!-- Delete Route Modal -->
<div class="modal fade" id="routeDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Delete Route</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="routeDeleteForm" action="#" method="POST">
        @csrf @method('DELETE')
        <div class="modal-body"><p class="mb-0">Are you sure you want to delete this delivery route?</p></div>
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
    var editModal = document.getElementById('routeEditModal');
    editModal?.addEventListener('show.bs.modal', function(e){
        var b = e.relatedTarget; if(!b) return;
        var id = b.getAttribute('data-id');
        document.getElementById('routeECountry').value = b.getAttribute('data-country') || '';
        document.getElementById('routeEState').value = b.getAttribute('data-state') || '';
        document.getElementById('routeEArea').value = b.getAttribute('data-area') || '';
        document.getElementById('routeEFee').value = b.getAttribute('data-fee') || 0;
        document.getElementById('routeEDays').value = b.getAttribute('data-days') || 3;
        document.getElementById('routeEActive').checked = (b.getAttribute('data-active') === '1');
        document.getElementById('routeEditForm').action = '{{ url('/superadmin/delivery-routes') }}/'+id;
    });
    var toggleModal = document.getElementById('routeToggleModal');
    toggleModal?.addEventListener('show.bs.modal', function(e){
        var b = e.relatedTarget; if(!b) return;
        var id = b.getAttribute('data-id');
        var active = b.getAttribute('data-active') === '1';
        document.getElementById('routeToggleText').textContent = active ? 'Disable this route?' : 'Enable this route?';
        document.getElementById('routeToggleBtn').textContent = active ? 'Disable' : 'Enable';
        document.getElementById('routeToggleForm').action = '{{ url('/superadmin/delivery-routes') }}/'+id+'/toggle';
    });
    var delModal = document.getElementById('routeDeleteModal');
    delModal?.addEventListener('show.bs.modal', function(e){
        var id = e.relatedTarget?.getAttribute('data-id');
        document.getElementById('routeDeleteForm').action = '{{ url('/superadmin/delivery-routes') }}/'+id;
    });
    });
</script>

@endsection
