@extends('admin.layout')

@section('title', 'Business KYC Applications')
@section('subtitle', 'Review and manage business verification requests')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(($statusOptions ?? []) as $value => $label)
                    <option value="{{ $value }}" @selected((string) $status === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if($status)
                <a href="{{ route('admin.vendor-kyc.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            @endif
        </form>

        <div class="d-flex flex-wrap gap-2">
            @foreach($statusOptions as $value => $label)
                <span class="badge rounded-pill text-bg-light">
                    {{ $label }}:
                    <span class="fw-semibold">{{ $statusCounts[$value] ?? 0 }}</span>
                </span>
            @endforeach
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
                            <th>Business / Vendor</th>
                            <th>Legal Name</th>
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
                                        @if($application->business)
                                            <a href="{{ route('admin.vendors.show', $application->user) }}" class="fw-semibold">
                                                {{ $application->business->name }}
                                            </a>
                                            <span class="text-muted small font-monospace">{{ $application->business->business_code }}</span>
                                            <span class="text-muted small">Owner: {{ $application->user?->name }}</span>
                                        @else
                                            <a href="{{ route('admin.vendors.show', $application->user) }}" class="fw-semibold">
                                                {{ $application->user->name }}
                                            </a>
                                            <span class="text-muted small">{{ $application->user->email }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $application->legal_name }}</td>
                                <td>
                                    @if($application->submitted_at)
                                        {{ $application->submitted_at->format('d M Y H:i') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @php($badge = $statusBadgeData[$application->status] ?? null)
                                    <span class="badge {{ $badge['class'] ?? 'bg-secondary' }}">
                                        {{ $badge['label'] ?? ucfirst(str_replace('_', ' ', $application->status)) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.vendor-kyc.show', $application) }}" class="btn btn-sm btn-primary">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fi fi-rr-inbox d-block fs-2 mb-2"></i>
                                    No KYC applications found.
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
