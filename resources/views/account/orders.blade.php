@extends('account.layout')
@section('title', 'My Orders')
@section('subtitle', 'Orders')

@section('content')
<div class="card">
    <div class="p-3 border-bottom">
        <form method="GET" class="row g-2">
            <div class="col-sm-4">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search orders..." class="form-control form-control-sm">
            </div>
            <div class="col-sm-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    @foreach(\App\Enums\OrderStatus::cases() as $s)
                    <option value="{{ $s->value }}" {{ request('status') === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        @if($orders->count() > 0)
        <table class="table mb-0">
            <thead><tr>
                <th class="ps-4">Order #</th>
                <th>Store</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th class="pe-4"></th>
            </tr></thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td class="ps-4 fw-semibold">{{ $order->order_number }}</td>
                    <td class="text-muted">{{ $order->store?->name ?? '—' }}</td>
                    <td>{{ $order->items->count() }}</td>
                    <td class="fw-semibold">₦{{ number_format($order->total, 2) }}</td>
                    <td><span class="badge-status bg-secondary bg-opacity-10 text-secondary">{{ $order->status->label() }}</span></td>
                    <td class="text-muted">{{ $order->created_at->format('M d, Y') }}</td>
                    <td class="pe-4"><a href="{{ route('account.order.show', $order->order_number) }}" class="btn btn-outline btn-sm">View</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-bag-shopping fa-2x mb-3 d-block"></i>
            <p>No orders found</p>
        </div>
        @endif
    </div>
    @if($orders->hasPages())
    <div class="px-4 py-3 border-top">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
