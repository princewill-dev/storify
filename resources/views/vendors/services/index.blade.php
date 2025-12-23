@extends('vendors.layout')

@section('title', 'Vendor')
@section('subtitle', 'Services')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center">
      <h5 class="mb-0 me-3">
        Services
        @if(isset($selectedStore))
          <span class="text-primary small ms-1">for {{ $selectedStore->name }}</span>
        @endif
      </h5>
      <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#filterServicesModal">Filter</button>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('vendor.services.create', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-primary me-2">New service</a>
      <a href="{{ route('vendor.dashboard') }}" class="btn btn-light">Back</a>
    </div>
  </div>
  <div class="card">
    <div class="card-body table-responsive">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }}</div>
        <form method="get" class="d-inline-flex align-items-center gap-2">
          @foreach(request()->except('per_page','page') as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
          @endforeach
          <label for="per_page" class="me-1">Per page</label>
          <select id="per_page" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
            <option value="10" @selected((request('per_page', 10) == 10))>10</option>
            <option value="50" @selected((request('per_page', 10) == 50))>50</option>
            <option value="100" @selected((request('per_page', 10) == 100))>100</option>
          </select>
        </form>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>Image</th>
            <th>Code</th>
            <th>Name</th>
            <th>Store</th>
            <th>Amount</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($services as $s)
            <tr>
              <td>
                @if(!empty($serviceImages[$s->id] ?? null))
                  <img src="{{ $serviceImages[$s->id] }}" alt="{{ $s->name }}" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                @else
                  <h6>N/A</h6>
                @endif
              </td>
              <td><code>{{ $s->service_code }}</code></td>
              <td>{{ $s->name }}</td>
              <td>{{ $s->store?->name }}</td>
              <td>
                {{ $s->currency?->symbol ?? '' }} {{ number_format($s->amount, 2) }}
              </td>
              <td>
                <span class="badge bg-{{ $s->status === 'active' ? 'success' : 'secondary' }}">{{ $s->status }}</span>
              </td>
              <td class="text-end">
                <a class="btn btn-sm p-1 border-0 bg-transparent text-primary" href="{{ route('vendor.services.edit', ['vendor' => $vendor, 'service' => $s]) }}" title="Edit">
                  <i class="fa fa-pen"></i>
                </a>
                <button type="button" class="btn btn-sm p-1 border-0 bg-transparent text-danger" data-bs-toggle="modal" data-bs-target="#deleteModal{{ $s->id }}" title="Delete">
                  <i class="fa fa-trash"></i>
                </button>
              </td>
            </tr>
            <!-- Delete Modal -->
            <div class="modal fade" id="deleteModal{{ $s->id }}" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Delete service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    This action cannot be undone. Delete <strong>{{ $s->name }}</strong>?
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="{{ route('vendor.services.destroy', ['vendor' => $vendor, 'service' => $s]) }}" class="d-inline">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-danger">Yes, Delete</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          @empty
            <tr><td colspan="7" class="text-center text-muted">No services yet</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small text-muted">Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }}</div>
        <div>{{ $services->appends(request()->query())->links('general.pagination.only-links') }}</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Services Modal -->
<div class="modal fade" id="filterServicesModal" tabindex="-1" aria-labelledby="filterServicesLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filterServicesLabel">Filter Services</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="GET">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="">All</option>
                <option value="active" @selected(($status ?? '')==='active')>Active</option>
                <option value="inactive" @selected(($status ?? '')==='inactive')>Inactive</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Search</label>
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Name or code">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="{{ route('vendor.services.index', ['vendor' => $vendor]) }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
