@extends('admin.layout')
@section('subtitle', 'Warehouses')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Warehouses</h6>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.warehouses.index') }}" class="btn btn-light btn-sm">Reset</a>
                </div>
            </div>
            <div class="card-body">
                {{-- Filters --}}
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            <option value="active" @selected(($status ?? '') === 'active')>Active</option>
                            <option value="inactive" @selected(($status ?? '') === 'inactive')>Inactive</option>
                            <option value="deleted" @selected(($status ?? '') === 'deleted')>Deleted</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control form-control-sm" placeholder="Name, code, or business name">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Business</th>
                                <th>Owner</th>
                                <th>Stock Items</th>
                                <th>Sections</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($warehouses as $wh)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.warehouses.show', $wh) }}" class="fw-semibold text-decoration-none">{{ $wh->name }}</a>
                                        @if($wh->address)<div class="small text-muted">{{ $wh->address }}</div>@endif
                                    </td>
                                    <td><code>{{ $wh->warehouse_code }}</code></td>
                                    <td>
                                        @if($wh->business)
                                            <a href="{{ route('admin.vendors.show', $wh->user) }}">{{ $wh->business->name }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $wh->user?->name ?? '—' }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $wh->stock_locations_count }}</span></td>
                                    <td><span class="badge bg-light text-dark">{{ $wh->sections_count }}</span></td>
                                    <td>
                                        <span class="badge bg-{{ $wh->status === 'active' ? 'success' : ($wh->status === 'inactive' ? 'warning' : 'secondary') }}">
                                            {{ ucfirst($wh->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No warehouses found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">{{ $warehouses->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
