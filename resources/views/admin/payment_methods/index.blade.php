@extends('admin.layout')
@section('subtitle', 'Payment Methods')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Payment Methods</h4>
    <small class="text-muted">Toggle availability for checkout</small>
  </div>

  @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      {{ session('success') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif

  <div class="card">
    <div class="card-body">
      <div class="alert alert-info mb-3">
        <i class="fa fa-info-circle me-2"></i>
        Click the toggle button to enable or disable a payment method.
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th width="50">#</th>
              <th>Name</th>
              <th>Code</th>
              <th>Description</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($methods as $method)
              <tr>
                <td>
                  <span class="badge bg-secondary">{{ $loop->iteration }}</span>
                </td>
                <td>{{ $method->name }}</td>
                <td><code>{{ $method->code }}</code></td>
                <td>
                  <p class="mb-1">{{ $method->description ?? '—' }}</p>
                </td>
                <td>
                  <span class="badge {{ $method->is_active ? 'bg-success' : 'bg-secondary' }}">
                    {{ $method->is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#togglePaymentMethodModal"
                    data-action="{{ route('admin.payment-methods.toggle', $method) }}"
                    data-name="{{ $method->name }}"
                    data-active="{{ $method->is_active ? 'disable' : 'enable' }}"
                  >
                    {{ $method->is_active ? 'Disable' : 'Enable' }}
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No payment methods configured.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Toggle Modal -->
<div class="modal fade" id="togglePaymentMethodModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="togglePaymentMethodForm" method="POST" action="">
        @csrf
        <div class="modal-header border-0">
          <h5 class="modal-title" id="togglePaymentMethodModalLabel"></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p id="togglePaymentMethodMessage"></p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" id="togglePaymentMethodButton"></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  const toggleModal = document.getElementById('togglePaymentMethodModal');
  if (toggleModal) {
    toggleModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const action = button.getAttribute('data-action');
      const name = button.getAttribute('data-name');
      const activeState = button.getAttribute('data-active');

      const form = document.getElementById('togglePaymentMethodForm');
      const title = document.getElementById('togglePaymentMethodModalLabel');
      const message = document.getElementById('togglePaymentMethodMessage');
      const submit = document.getElementById('togglePaymentMethodButton');

      form.action = action;
      title.textContent = `${activeState === 'enable' ? 'Enable' : 'Disable'} ${name}`;
      message.textContent = `Are you sure you want to ${activeState} ${name}?`;
      submit.textContent = activeState === 'enable' ? 'Enable' : 'Disable';
      submit.classList.toggle('btn-danger', activeState === 'disable');
      submit.classList.toggle('btn-primary', activeState === 'enable');
    });
  }
</script>
@endsection
