@extends('account.layout')
@section('title', 'Transactions')
@section('subtitle', 'Transactions')

@section('content')
<div class="card">
    <div class="p-3 border-bottom">
        <form method="GET" class="row g-2">
            <div class="col-sm-4">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    @foreach(\App\Enums\TransactionStatus::cases() as $s)
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
        @if($transactions->count() > 0)
        <table class="table mb-0">
            <thead><tr>
                <th class="ps-4">Reference</th>
                <th>Order</th>
                <th>Method</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date</th>
            </tr></thead>
            <tbody>
                @foreach($transactions as $tx)
                <tr>
                    <td class="ps-4"><a href="{{ route('account.transaction.show', $tx) }}" class="fw-medium text-dark text-decoration-none font-monospace small">{{ $tx->reference }}</a></td>
                    <td class="small">{{ $tx->order?->order_number ?? '—' }}</td>
                    <td class="text-muted small">{{ $tx->paymentMethod?->name ?? '—' }}</td>
                    <td class="fw-semibold">₦{{ number_format($tx->amount, 2) }}</td>
                    <td><span class="badge-status bg-secondary bg-opacity-10 text-secondary">{{ $tx->status->label() }}</span></td>
                    <td class="text-muted small">{{ $tx->created_at->format('M d, Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-credit-card fa-2x mb-3 d-block"></i>
            <p>No transactions yet</p>
        </div>
        @endif
    </div>
    @if($transactions->hasPages())
    <div class="px-4 py-3 border-top">{{ $transactions->links() }}</div>
    @endif
</div>
@endsection
