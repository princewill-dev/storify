@extends('management.layout')
@section('subtitle', 'POS Session Details')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="card-title mb-0">Session #{{ $session->session_code }}</h5>
                <span class="badge {{ \App\Enums\PosSessionStatus::badgeData()[$session->status]['class'] ?? 'bg-secondary' }}">
                    {{ ucfirst($session->status) }}
                </span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Staff</dt><dd class="col-sm-8">{{ $session->staff?->name ?? '--' }}</dd>
                    <dt class="col-sm-4">Opened</dt><dd class="col-sm-8">{{ $session->opened_at->format('d M Y, H:i') }}</dd>
                    <dt class="col-sm-4">Closed</dt><dd class="col-sm-8">{{ $session->closed_at?->format('d M Y, H:i') ?? 'Still open' }}</dd>
                    <dt class="col-sm-4">Opening Float</dt><dd class="col-sm-8">₦{{ number_format($session->opening_balance / 100, 2) }}</dd>
                    <dt class="col-sm-4">Expected Close</dt><dd class="col-sm-8">₦{{ number_format(($session->closing_balance_expected ?? 0) / 100, 2) }}</dd>
                    <dt class="col-sm-4">Actual Close</dt><dd class="col-sm-8">₦{{ number_format(($session->closing_balance_actual ?? 0) / 100, 2) }}</dd>
                    <dt class="col-sm-4">Difference</dt>
                    <dd class="col-sm-8">
                        @if($session->difference !== null)
                            <span class="{{ $session->difference >= 0 ? 'text-success' : 'text-danger' }} fw-bold">
                                ₦{{ number_format(abs($session->difference) / 100, 2) }}
                                ({{ $session->difference >= 0 ? 'over' : 'short' }})
                            </span>
                        @else
                            --
                        @endif
                    </dd>
                    @if($session->notes)
                    <dt class="col-sm-4">Notes</dt><dd class="col-sm-8">{{ $session->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Sales Summary</h6></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Orders</span>
                    <span class="fw-bold">{{ $session->orders->count() }}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Total Sales</span>
                    <span class="fw-bold">₦{{ number_format($session->orders->sum('total'), 2) }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0">Orders in this Session</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Items</th>
                        <th class="text-end">Total</th>
                        <th>Payment</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($session->orders as $order)
                    <tr>
                        <td><span class="fw-semibold">{{ $order->order_number }}</span></td>
                        <td>{{ $order->items->count() }}</td>
                        <td class="text-end">₦{{ number_format($order->total, 2) }}</td>
                        <td>{{ ucfirst($order->meta['payment_method'] ?? '--') }}</td>
                        <td>{{ $order->created_at->format('H:i') }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">No orders in this session</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <a href="{{ route('management.pos.sessions.index', ['store' => $store->store_id]) }}" class="btn btn-light">Back to Sessions</a>
</div>
@endsection
