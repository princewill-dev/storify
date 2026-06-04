@extends('admin.layout')
@section('subtitle', $warehouse->name)

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">{{ $warehouse->name }} <small class="text-muted font-monospace fs-6 ms-2">{{ $warehouse->warehouse_code }}</small></h4>
      <div class="text-muted small mt-1">
        <span class="badge bg-{{ $warehouse->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($warehouse->status) }}</span>
        @if($warehouse->address) · {{ $warehouse->address }} @endif
      </div>
    </div>
    <a href="{{ route('admin.warehouses.index') }}" class="btn btn-light btn-sm">← Back</a>
  </div>

  {{-- Summary cards --}}
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card"><div class="card-body text-center py-3">
        <h3 class="mb-0 fw-bold">{{ $totalStock }}</h3>
        <small class="text-muted">Total Stock</small>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body text-center py-3">
        <h3 class="mb-0 fw-bold">{{ $lowStockCount }}</h3>
        <small class="text-muted">Low Stock (≤10)</small>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body text-center py-3">
        <h3 class="mb-0 fw-bold">{{ $warehouse->stock_locations_count }}</h3>
        <small class="text-muted">Stock Items</small>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body text-center py-3">
        <h3 class="mb-0 fw-bold">{{ $warehouse->sections_count }}</h3>
        <small class="text-muted">Sections</small>
      </div></div>
    </div>
  </div>

  <div class="row g-3">
    {{-- Business & Owner --}}
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Business & Owner</strong></div>
        <div class="card-body">
          @if($warehouse->business)
            <table class="table table-sm">
              <tr><td class="text-muted" style="width: 100px">Business</td><td>
                <a href="{{ route('admin.vendors.show', $warehouse->user) }}">{{ $warehouse->business->name }}</a>
                <span class="text-muted font-monospace small ms-2">{{ $warehouse->business->business_code }}</span>
              </td></tr>
              <tr><td class="text-muted">Owner</td><td>{{ $warehouse->user?->name ?? '—' }}</td></tr>
              <tr><td class="text-muted">Email</td><td>{{ $warehouse->user?->email ?? '—' }}</td></tr>
            </table>
          @else
            <div class="text-muted">No business assigned.</div>
          @endif
        </div>
      </div>
    </div>

    {{-- Sections --}}
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Sections ({{ $warehouse->sections->count() }})</strong></div>
        <div class="card-body">
          @if($warehouse->sections->isEmpty())
            <div class="text-muted">No sections.</div>
          @else
            <table class="table table-sm">
              <thead><tr><th>Name</th><th>Items</th><th>Status</th></tr></thead>
              <tbody>
                @foreach($warehouse->sections as $section)
                  <tr>
                    <td>{{ $section->name }}</td>
                    <td>{{ $section->stock_locations_count }}</td>
                    <td><span class="badge bg-{{ $section->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($section->status ?? 'active') }}</span></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </div>
      </div>
    </div>

    {{-- Stock --}}
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Stock (showing up to 20)</strong></div>
        <div class="card-body">
          @if($warehouse->stockLocations->isEmpty())
            <div class="text-muted">No stock.</div>
          @else
            <table class="table table-sm">
              <thead><tr><th>Product</th><th>Code</th><th>Quantity</th></tr></thead>
              <tbody>
                @foreach($warehouse->stockLocations as $loc)
                  <tr>
                    <td>{{ $loc->product?->name ?? 'Unknown' }}</td>
                    <td><code>{{ $loc->product?->product_code ?? '—' }}</code></td>
                    <td>
                      <span class="fw-bold">{{ $loc->quantity }}</span>
                      @if($loc->quantity <= 10 && $loc->quantity > 0)
                        <span class="badge bg-warning ms-1">Low</span>
                      @elseif($loc->quantity <= 0)
                        <span class="badge bg-danger ms-1">Out</span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </div>
      </div>
    </div>

    {{-- Recent Movements --}}
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Recent Stock Movements</strong></div>
        <div class="card-body">
          @if($recentMovements->isEmpty())
            <div class="text-muted">No movements yet.</div>
          @else
            <table class="table table-sm">
              <thead><tr><th>Product</th><th>Type</th><th>Qty</th><th>By</th><th>Date</th></tr></thead>
              <tbody>
                @foreach($recentMovements as $m)
                  <tr>
                    <td>{{ $m->product?->name ?? '—' }}</td>
                    <td>
                      <span class="badge bg-{{ $m->type === 'added' ? 'success' : ($m->type === 'removed' ? 'danger' : 'info') }}">
                        {{ ucfirst($m->type) }}
                      </span>
                    </td>
                    <td class="fw-bold">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                    <td>{{ $m->performedBy?->name ?? '—' }}</td>
                    <td class="small">{{ $m->created_at->format('d M H:i') }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
