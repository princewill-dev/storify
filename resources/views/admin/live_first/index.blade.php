@extends('admin.layout')

@section('title', 'Live First Applications')
@section('subtitle', 'Review and manage customer credit applications')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="pending" @selected($currentStatus === 'pending')>Pending</option>
                <option value="approved" @selected($currentStatus === 'approved')>Approved</option>
                <option value="rejected" @selected($currentStatus === 'rejected')>Rejected</option>
            </select>
            @if($currentStatus)
                <a href="{{ route('admin.live-first.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            @endif
        </form>

        <div class="d-flex flex-wrap gap-2">
            <span class="badge rounded-pill text-bg-light">
                Pending:
                <span class="fw-semibold">{{ $statusCounts['pending'] ?? 0 }}</span>
            </span>
            <span class="badge rounded-pill text-bg-success">
                Approved:
                <span class="fw-semibold">{{ $statusCounts['approved'] ?? 0 }}</span>
            </span>
            <span class="badge rounded-pill text-bg-danger">
                Rejected:
                <span class="fw-semibold">{{ $statusCounts['rejected'] ?? 0 }}</span>
            </span>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0 pb-0">
            <h5 class="card-title mb-0">Applications</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Full Name</th>
                            <th>Employer</th>
                            <th>Store</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($applications as $application)
                            <tr>
                                <td>{{ $application->id }}</td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $application->user->name }}</span>
                                        <span class="text-muted small">{{ $application->user->email }}</span>
                                    </div>
                                </td>
                                <td>{{ $application->full_name }}</td>
                                <td>{{ $application->employer_name }}</td>
                                <td>
                                    <span class="badge bg-info">{{ $application->store->name }}</span>
                                </td>
                                <td>
                                    @if($application->submitted_at)
                                        {{ $application->submitted_at->format('d M Y H:i') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($application->status === 'pending')
                                        <span class="badge bg-warning">Pending Review</span>
                                    @elseif($application->status === 'approved')
                                        <span class="badge bg-success">Approved</span>
                                    @elseif($application->status === 'rejected')
                                        <span class="badge bg-danger">Rejected</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.live-first.show', $application) }}" class="btn btn-sm btn-primary">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fi fi-rr-inbox d-block fs-2 mb-2"></i>
                                    No Live First applications found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer border-0">
            {{ $applications->links() }}
        </div>
    </div>
@endsection
