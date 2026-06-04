@extends('admin.layout')
@section('subtitle', 'Subscriptions')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Subscriptions</h6>
                <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-light btn-sm">Reset</a>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            <option value="active" @selected(($status ?? '') === 'active')>Active</option>
                            <option value="trial" @selected(($status ?? '') === 'trial')>Trial</option>
                            <option value="expired" @selected(($status ?? '') === 'expired')>Expired</option>
                            <option value="cancelled" @selected(($status ?? '') === 'cancelled')>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control form-control-sm" placeholder="Business name or plan name">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Ends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($subscriptions as $sub)
                                <tr>
                                    <td>
                                        @if($sub->business)
                                            <a href="{{ route('admin.vendors.show', $sub->business->owner) }}" class="fw-semibold text-decoration-none">{{ $sub->business->name }}</a>
                                            <div class="small text-muted font-monospace">{{ $sub->business->business_code }}</div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $sub->subscriptionPlan?->name ?? '—' }}</td>
                                    <td>
                                        @php
                                            $subColor = match($sub->status) {
                                                'active' => 'success',
                                                'trial' => 'info',
                                                'expired' => 'danger',
                                                'cancelled' => 'secondary',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <span class="badge bg-{{ $subColor }}">{{ ucfirst($sub->status) }}</span>
                                    </td>
                                    <td class="small">{{ $sub->starts_at?->format('d M Y') ?? '—' }}</td>
                                    <td class="small">{{ $sub->ends_at?->format('d M Y') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">No subscriptions found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">{{ $subscriptions->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
