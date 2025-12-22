@extends('admin.layout')

@section('content')
<div class="row">
    <!-- Header/Breadcrumb -->
    <div class="col-12 mb-4">
        <div class="d-flex align-items-center justify-content-between">
             <h4 class="mb-0">
                 <span class="text-muted fw-light">Early Access /</span> {{ $earlyPass->code }}
             </h4>
             <a href="{{ route('admin.early-access.index') }}" class="btn btn-outline-secondary">
                 <i class="fi fi-rr-arrow-left me-2"></i>Back to List
             </a>
        </div>
    </div>

    <!-- Info Card -->
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-5">
                    <div>
                        <label class="small text-muted d-block mb-1">Status</label>
                        @if($earlyPass->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-danger">Inactive</span>
                        @endif
                    </div>
                    <div>
                         <label class="small text-muted d-block mb-1">Usage Count</label>
                         <span class="fw-bold fs-5">{{ $earlyPass->usages->count() }}</span>
                    </div>
                    <div>
                         <label class="small text-muted d-block mb-1">Max Uses</label>
                         <span class="fw-bold fs-5">{{ $earlyPass->max_uses ?? '∞' }}</span>
                    </div>
                    <div>
                         <label class="small text-muted d-block mb-1">Created At</label>
                         <span class="fw-bold">{{ $earlyPass->created_at?->format('d M Y') ?? 'N/A' }}</span>
                    </div>
                    <div>
                         <label class="small text-muted d-block mb-1">Description</label>
                         <span>{{ $earlyPass->description ?? 'No description' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Usages Table -->
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-transparent py-3">
                <h5 class="card-title mb-0">Usage History</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                     <thead class="table-light">
                          <tr>
                              <th>Vendor</th>
                              <th>Store Used On</th>
                              <th>Used At</th>
                          </tr>
                     </thead>
                     <tbody>
                          @forelse($earlyPass->usages as $usage)
                          <tr>
                              <td>
                                  @if($usage->vendor)
                                  <div class="d-flex align-items-center">
                                       <div class="avatar avatar-sm me-2 bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;">
                                            {{ substr($usage->vendor->name, 0, 1) }}
                                       </div>
                                       <div>
                                            <h6 class="mb-0 fs-14">
                                                <a href="{{ route('admin.vendors.show', $usage->vendor) }}" class="text-inherit text-decoration-none">
                                                    {{ $usage->vendor->name }}
                                                </a>
                                            </h6>
                                            <small class="text-muted">{{ $usage->vendor->email }}</small>
                                       </div>
                                  </div>
                                  @else
                                    <span class="text-muted">Unknown Vendor</span>
                                  @endif
                              </td>
                              <td>
                                  @if($usage->store)
                                      <a href="{{ route('admin.stores.show', $usage->store) }}" class="text-primary fw-medium text-decoration-none">
                                          {{ $usage->store->name }}
                                      </a>
                                      <div class="small text-muted user-select-all">{{ $usage->store->store_id }}</div>
                                  @else
                                      <span class="text-muted">-</span>
                                  @endif
                              </td>
                              <td>
                                  {{ $usage->used_at->format('d M Y, H:i') }}
                              </td>
                          </tr>
                          @empty
                          <tr>
                              <td colspan="3" class="text-center py-5 text-muted">
                                  <i class="fi fi-rr-time-past d-block fs-2 mb-2"></i>
                                  No usages recorded yet.
                              </td>
                          </tr>
                          @endforelse
                     </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
