@extends('admin.layouts.app')

@section('title', 'Delivery Intervals')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Delivery Intervals</h1>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    <div class="row">
        <!-- Create Form -->
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Add New Interval</h6>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.delivery-intervals.store') }}" method="POST">
                        @csrf
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Weekly" required>
                        </div>
                        <div class="form-group">
                            <label>Days Count</label>
                            <input type="number" name="days_count" class="form-control" placeholder="e.g. 7" min="1" required>
                            <small class="form-text text-muted">Number of days between deliveries.</small>
                        </div>
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-plus mr-1"></i> Create Interval
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- List -->
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Existing Intervals</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Days</th>
                                    <th>Order</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($intervals as $interval)
                                    <tr>
                                        <td>{{ $interval->name }}</td>
                                        <td>{{ $interval->days_count }} days</td>
                                        <td>{{ $interval->sort_order }}</td>
                                        <td>
                                            @if($interval->is_active)
                                                <span class="badge badge-success">Active</span>
                                            @else
                                                <span class="badge badge-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#editModal{{ $interval->id }}">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form action="{{ route('admin.delivery-intervals.toggle', $interval->id) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm {{ $interval->is_active ? 'btn-warning' : 'btn-success' }}" title="{{ $interval->is_active ? 'Deactivate' : 'Activate' }}">
                                                        <i class="fas {{ $interval->is_active ? 'fa-ban' : 'fa-check' }}"></i>
                                                    </button>
                                                </form>
                                                <form action="{{ route('admin.delivery-intervals.destroy', $interval->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal{{ $interval->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Interval</h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <form action="{{ route('admin.delivery-intervals.update', $interval->id) }}" method="POST">
                                                            @csrf
                                                            @method('PUT')
                                                            <div class="modal-body">
                                                                <div class="form-group">
                                                                    <label>Name</label>
                                                                    <input type="text" name="name" class="form-control" value="{{ $interval->name }}" required>
                                                                </div>
                                                                <div class="form-group">
                                                                    <label>Days Count</label>
                                                                    <input type="number" name="days_count" class="form-control" value="{{ $interval->days_count }}" min="1" required>
                                                                </div>
                                                                <div class="form-group">
                                                                    <label>Sort Order</label>
                                                                    <input type="number" name="sort_order" class="form-control" value="{{ $interval->sort_order }}" min="0">
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">No delivery intervals found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
