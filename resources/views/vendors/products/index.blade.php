@extends('vendors.layout')

@section('title', 'Vendor')
@section('subtitle', 'Products')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center">
      <h5 class="mb-0 me-3">Products</h5>
      <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#filterProductsModal">Filter</button>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('vendor.products.create', ['vendor' => $vendor]) }}" class="btn btn-primary me-2">New product</a>
      <a href="{{ route('vendor.dashboard') }}" class="btn btn-light">Back</a>
    </div>
  </div>
  <div class="card">
    <div class="card-body table-responsive">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}</div>
        <form method="get" class="d-inline-flex align-items-center gap-2">
          @foreach(request()->except('per_page','page') as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
          @endforeach
          <label for="per_page" class="me-1">Per page</label>
          <select id="per_page" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
            <option value="10" @selected(($perPage ?? 10) == 10)>10</option>
            <option value="50" @selected(($perPage ?? 10) == 50)>50</option>
            <option value="100" @selected(($perPage ?? 10) == 100)>100</option>
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
            <th>Category</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Featured</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($products as $p)
            <tr>
              <td>
                @if(!empty($productImages[$p->id] ?? null))
                  <img src="{{ $productImages[$p->id] }}" alt="{{ $p->name }}" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                @else
                  <h6>N/A</h6>
                @endif
              </td>
              <td><code>{{ $p->product_code }}</code></td>
              <td>{{ $p->name }}</td>
              <td>{{ $p->store?->name }}</td>
              <td>{{ $p->category?->name ?? '—' }}</td>
              <td>
                {{ $displayPrices[$p->id] ?? '—' }}
              </td>
              <td>
                <span class="badge bg-{{ $p->status === 'active' ? 'success' : 'secondary' }}">{{ $p->status }}</span>
              </td>
              <td>
                @if($p->featured)
                  <span class="badge bg-warning text-dark">Featured</span>
                @else
                  <span class="text-muted">—</span>
                @endif
              </td>
              <td class="text-end">
                <a class="btn btn-sm p-1 border-0 bg-transparent text-secondary" href="{{ route('vendor.products.show', ['vendor' => $vendor, 'product' => $p]) }}" title="View">
                  <i class="fa fa-eye"></i>
                </a>
                <a class="btn btn-sm p-1 border-0 bg-transparent text-primary" href="{{ route('vendor.products.edit', ['vendor' => $vendor, 'product' => $p]) }}" title="Edit">
                  <i class="fa fa-pen"></i>
                </a>
                @if($p->status === 'active')
                  <button type="button" class="btn btn-sm p-1 border-0 bg-transparent text-warning" data-bs-toggle="modal" data-bs-target="#deactivateModal{{ $p->id }}" title="Deactivate">
                    <i class="fa fa-ban"></i>
                  </button>
                @else
                  <button type="button" class="btn btn-sm p-1 border-0 bg-transparent text-success" data-bs-toggle="modal" data-bs-target="#activateModal{{ $p->id }}" title="Activate">
                    <i class="fa fa-check"></i>
                  </button>
                @endif
                <button type="button" class="btn btn-sm p-1 border-0 bg-transparent text-danger" data-bs-toggle="modal" data-bs-target="#deleteModal{{ $p->id }}" title="Delete">
                  <i class="fa fa-trash"></i>
                </button>
              </td>
            </tr>
            <!-- Activate Modal -->
            <div class="modal fade" id="activateModal{{ $p->id }}" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Activate product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    Are you sure you want to activate <strong>{{ $p->name }}</strong>?
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="{{ route('vendor.products.status', ['vendor' => $vendor, 'product' => $p]) }}" class="d-inline">
                      @csrf
                      @method('PUT')
                      <input type="hidden" name="status" value="active">
                      <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                      <input type="hidden" name="page" value="{{ request('page', 1) }}">
                      <button type="submit" class="btn btn-success">Yes, Activate</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <!-- Deactivate Modal -->
            <div class="modal fade" id="deactivateModal{{ $p->id }}" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Deactivate product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    Are you sure you want to deactivate <strong>{{ $p->name }}</strong>?
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="{{ route('admin.products.status', $p) }}" class="d-inline">
                      @csrf
                      @method('PUT')
                      <input type="hidden" name="status" value="inactive">
                      <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                      <input type="hidden" name="page" value="{{ request('page', 1) }}">
                      <button type="submit" class="btn btn-warning">Yes, Deactivate</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <!-- Delete Modal -->
            <div class="modal fade" id="deleteModal{{ $p->id }}" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Delete product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    This action cannot be undone. Delete <strong>{{ $p->name }}</strong>?
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="{{ route('vendor.products.destroy', ['vendor' => $vendor, 'product' => $p]) }}" class="d-inline">
                      @csrf
                      @method('DELETE')
                      <input type="hidden" name="per_page" value="{{ request('per_page', $perPage ?? 10) }}">
                      <input type="hidden" name="page" value="{{ request('page', 1) }}">
                      <button type="submit" class="btn btn-danger">Yes, Delete</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          @empty
            <tr><td colspan="9" class="text-center text-muted">No products yet</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small text-muted">Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}</div>
        <div>{{ $products->appends(['per_page' => ($perPage ?? 10)])->links('general.pagination.only-links') }}</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Products Modal -->
<div class="modal fade" id="filterProductsModal" tabindex="-1" aria-labelledby="filterProductsLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filterProductsLabel">Filter Products</h5>
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
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Name, code, store or category">
            </div>
            <div class="col-md-4">
              <label class="form-label">From</label>
              <input type="date" name="from" value="{{ $from ?? '' }}" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">To</label>
              <input type="date" name="to" value="{{ $to ?? '' }}" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="{{ route('admin.products.index') }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
