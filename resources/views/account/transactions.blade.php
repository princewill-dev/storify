@extends('home.layout')
@section('title', 'My Transactions')

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="content-inner-1">
        <div class="container">
            <div class="row">
                <!-- Sidebar -->
                <div class="col-lg-3 col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="avatar avatar-xl bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 32px;">
                                    {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                                </div>
                                <h5 class="mt-3 mb-1">{{ Auth::user()->name }}</h5>
                                <p class="text-muted small">{{ Auth::user()->email }}</p>
                            </div>
                            <nav class="nav flex-column">
                                <a class="nav-link" href="{{ route('account.dashboard') }}">
                                    <i class="fas fa-home me-2"></i> Dashboard
                                </a>
                                <a class="nav-link" href="{{ route('account.info') }}">
                                    <i class="fas fa-user me-2"></i> Account Info
                                </a>
                                <a class="nav-link" href="{{ route('account.orders') }}">
                                    <i class="fas fa-shopping-bag me-2"></i> My Orders
                                </a>
                                <a class="nav-link active" href="{{ route('account.transactions') }}">
                                    <i class="fas fa-credit-card me-2"></i> Transactions
                                </a>
                                <form method="POST" action="{{ route('account.logout') }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="nav-link border-0 bg-transparent text-danger w-100 text-start">
                                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                                    </button>
                                </form>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-lg-9 col-md-8">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="mb-0">My Transactions</h2>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" action="{{ route('account.transactions') }}" class="row g-3">
                                <div class="col-md-6">
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-2"></i> Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Transactions List -->
                    @if($transactions->count() > 0)
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Transaction ID</th>
                                                <th>Order #</th>
                                                <th>Payment Method</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($transactions as $transaction)
                                            <tr>
                                                <td>
                                                    <strong class="text-primary">{{ $transaction->reference }}</strong>
                                                </td>
                                                <td>
                                                    @if($transaction->order)
                                                    <a href="{{ route('account.order.show', $transaction->order->order_number) }}" class="text-decoration-none">
                                                        {{ $transaction->order->order_number }}
                                                    </a>
                                                    @else
                                                    <span class="text-muted">N/A</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($transaction->paymentMethod)
                                                    <span class="badge bg-light text-dark">{{ $transaction->paymentMethod->name }}</span>
                                                    @else
                                                    <span class="text-muted">N/A</span>
                                                    @endif
                                                </td>
                                                <td><strong>₦{{ number_format($transaction->amount, 2) }}</strong></td>
                                                <td>
                                                    @if($transaction->status === 'completed')
                                                        <span class="badge bg-success">Completed</span>
                                                    @elseif($transaction->status === 'pending')
                                                        <span class="badge bg-warning">Pending</span>
                                                    @elseif($transaction->status === 'failed')
                                                        <span class="badge bg-danger">Failed</span>
                                                    @else
                                                        <span class="badge bg-secondary">{{ ucfirst($transaction->status) }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ $transaction->created_at->format('M d, Y') }}<br><small class="text-muted">{{ $transaction->created_at->format('h:i A') }}</small></td>
                                                <td>
                                                    <a href="{{ route('account.transaction.show', ['transactionId' => $transaction->reference]) }}" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-center mt-4">
                            {{ $transactions->links() }}
                        </div>
                    @else
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-credit-card fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No transactions found</h5>
                                <p class="text-muted">You don't have any transactions yet.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
