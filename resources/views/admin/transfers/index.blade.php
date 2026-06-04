@extends('admin.layout')
@section('subtitle', 'Stock Transfers')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Stock Transfers</h6>
                <a href="{{ route('admin.transfers.index') }}" class="btn btn-light btn-sm">Reset</a>
            </div>
            <div class="card-body">
                {{-- Filters --}}
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s->value }}" @selected(($status ?? '') === $s->value)>{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control form-control-sm" placeholder="Code, source, or destination name">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Items</th>
                                <th>Requested By</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transfers as $t)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.transfers.show', $t) }}" class="font-monospace fw-semibold text-decoration-none">{{ $t->transfer_code }}</a>
                                    </td>
                                    <td>{{ $t->fromLocation?->name ?? '—' }}</td>
                                    <td>{{ $t->toLocation?->name ?? '—' }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $t->items_count }}</span></td>
                                    <td>{{ $t->requester?->name ?? '—' }}</td>
                                    <td>
                                        @php
                                            $color = match($t->status->value) {
                                                'draft' => 'secondary',
                                                'pending' => 'warning',
                                                'approved' => 'info',
                                                'awaiting_acknowledgment' => 'warning',
                                                'dispatched' => 'primary',
                                                'received' => 'success',
                                                'rejected', 'cancelled' => 'danger',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <span class="badge bg-{{ $color }}">{{ $t->status->label() }}</span>
                                    </td>
                                    <td class="small">{{ $t->created_at->format('d M H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No transfers found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">{{ $transfers->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
