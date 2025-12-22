@extends('vendors.layout')
@section('subtitle', 'Dashboard')

@section('content')
<div class="row">
    
    <div class="row">

        <div class="col-md-3">
            <div class="card text-bg-primary overflow-hidden z-1">
                <img src="{{ asset('vendor_files/assets/images/card-bg1.png') }}" alt="" class="position-absolute top-0 start-0 z-n1">
                <div class="card-header pb-0 border-0 align-items-start pt-4">
                    <h4 class="card-title">Revenue</h4>
                    <div class="clearfix">
                        <span class="badge badge-light">All Time</span>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <h3 class="display-4 text-white fw-semibold mb-2">₦{{ number_format($stats['total_revenue'], 2) }}</h3>
                    <div class="d-flex justify-content-between align-items-end">
                        <div class="clearfix">
                            @if($stats['revenue_change_percent'] >= 0)
                                <span class="text-success fw-medium fs-lg">+{{ $stats['revenue_change_percent'] }}%</span>
                            @else
                                <span class="text-danger fw-medium fs-lg">{{ $stats['revenue_change_percent'] }}%</span>
                            @endif
                            <span class="text-white fs-lg">prev month</span>												
                        </div>
                        <div id="chartRevenue"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card overflow-hidden z-1">
                <img src="{{ asset('vendor_files/assets/images/card-bg1.png') }}" alt="" class="position-absolute top-0 start-0 z-n1">
                <div class="card-header pb-0 border-0 align-items-start pt-4">
                    <h4 class="card-title">Items</h4>
                    <div class="clearfix">
                        <button type="button" class="btn btn-primary btn-xxs" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="fi fi-rr-plus me-1"></i> Add to store
                        </button>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <h3 class="display-4 fw-semibold mb-2">{{ number_format($stats['total_items']) }}</h3>
                    <div class="d-flex justify-content-between align-items-end">
                        <div class="clearfix">
                            <span class="text-gray-400 fs-lg">Products & Services</span>												
                        </div>
                        <div id="chartTotalSales"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Item Modal -->
        <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="addItemModalLabel">What would you like to add?</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body pt-3">
                        <p class="text-muted mb-4">Choose the type of item you want to list in your store. Understanding the difference helps us set up the right tools for you.</p>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <a href="{{ route('vendor.products.create', ['vendor' => $vendor]) }}" class="card border border-primary-light shadow-none hover-shadow-sm transition-300 text-decoration-none h-100 mb-0">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="avatar avatar-sm bg-primary-light text-primary rounded-circle me-3">
                                                <i class="fi fi-rr-box"></i>
                                            </div>
                                            <h5 class="mb-0 fw-bold text-dark">Physical Product</h5>
                                        </div>
                                        <p class="text-muted small mb-0">Best for tangible goods like clothing, electronics, or food. Includes inventory tracking, shipping options, and weight measurements.</p>
                                    </div>
                                </a>
                            </div>
                            
                            <div class="col-12">
                                <a href="{{ route('vendor.services.create', ['vendor' => $vendor]) }}" class="card border border-info-light shadow-none hover-shadow-sm transition-300 text-decoration-none h-100 mb-0">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="avatar avatar-sm bg-info-light text-info rounded-circle me-3">
                                                <i class="fi fi-rr-customer-service"></i>
                                            </div>
                                            <h5 class="mb-0 fw-bold text-dark">Service or Digital</h5>
                                        </div>
                                        <p class="text-muted small mb-0">Best for skills, appointments, or digital downloads. Ideal for consulting, photography, bookings, or downloadable files.</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card overflow-hidden z-1">
                <img src="{{ asset('vendor_files/assets/images/card-bg1.png') }}" alt="" class="position-absolute top-0 start-0 z-n1">
                <div class="card-header pb-0 border-0 align-items-start pt-4">
                    <h4 class="card-title">Orders</h4>
                    <div class="clearfix">
                        <span class="badge badge-primary light">{{ $stats['pending_orders'] }} Pending</span>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <h3 class="display-4 fw-semibold mb-2">{{ number_format($stats['total_orders']) }}</h3>
                    <div class="d-flex justify-content-between align-items-end">
                        <div class="clearfix">
                            @if($stats['orders_change_percent'] >= 0)
                                <span class="text-success fw-medium fs-lg">+{{ $stats['orders_change_percent'] }}%</span>
                            @else
                                <span class="text-danger fw-medium fs-lg">{{ $stats['orders_change_percent'] }}%</span>
                            @endif
                            <span class="text-gray-400 fs-lg">prev month</span>												
                        </div>
                        <div id="chartTotalSales"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card overflow-hidden z-1">
                <img src="{{ asset('vendor_files/assets/images/card-bg1.png') }}" alt="" class="position-absolute top-0 start-0 z-n1">
                <div class="card-header pb-0 border-0 align-items-start pt-4">
                    <h4 class="card-title">Customers</h4>
                    <div class="clearfix">
                        <span class="badge badge-primary light">Total Customers</span>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <h3 class="display-4 fw-semibold mb-2">{{ number_format($stats['total_customers']) }}</h3>
                    <div class="d-flex justify-content-between align-items-end">
                        <div class="clearfix">
                            <span class="text-gray-400 fs-lg">With Orders</span>												
                        </div>
                        <div id="chartTotalSales"></div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row">

        <!-- Start - Sales Analytics  -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header pb-0 border-0 align-items-start">
                    <h4 class="card-title">Platform Statistics</h4>
                    <div class="clearfix">
                        <a href="{{ route('vendor.orders.index', ['vendor' => auth('vendor')->user()]) }}" class="btn btn-primary light"><i class="fi fi-rr-eye"></i> View Orders</a>
                    </div>
                </div>
                <div class="card-body py-0">
                    <span class="fs-lg">Monthly Revenue</span>
                    <h3 class="display-5 fw-semibold mb-0">₦{{ number_format($stats['revenue_this_month'], 2) }} 
                        @if($stats['revenue_change_percent'] >= 0)
                            <span class="text-success fw-medium fs-lg">+{{ $stats['revenue_change_percent'] }}%</span>
                        @else
                            <span class="text-danger fw-medium fs-lg">{{ $stats['revenue_change_percent'] }}%</span>
                        @endif
                    </h3>
                </div>
                <div id="chartSpendingStatistic"></div>
                <div class="card-footer p-2 pt-0 border-0">
                    <div class="row g-2">
                        <div class="col-sm-4 col-6 col-xl-4">
                            <div class="border rounded px-3 py-2">
                                <span class="fs-sm text-gray-500">Total Customers</span>
                                <h4 class="mb-2 fw-semibold">{{ number_format($stats['total_customers']) }}</h4>
                                <span class="fs-sm text-muted">{{ $stats['active_customers'] }} active</span>
                            </div>
                        </div>
                        <div class="col-sm-4 col-6 col-xl-4">
                            <div class="border rounded px-3 py-2">
                                <span class="fs-sm text-gray-500">Total Vendors</span>
                                <h4 class="mb-2 fw-semibold">{{ number_format($stats['total_vendors']) }}</h4>
                                <span class="fs-sm text-muted">{{ $stats['active_vendors'] }} active</span>
                            </div>
                        </div>
                        <div class="col-sm-4 col-12 col-xl-4">
                            <div class="border rounded px-3 py-2">
                                <span class="fs-sm text-gray-500">Total Stores</span>
                                <h4 class="mb-2 fw-semibold">{{ number_format($stats['total_stores']) }}</h4>
                                <span class="fs-sm text-muted">{{ $stats['active_stores'] }} active</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- End - Sales Analytics  -->

        <!-- Start - Products & Inventory -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header border-0 pb-0">
                    <h4 class="card-title">Products & Inventory</h4>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fs-sm text-gray-500">Total Products</span>
                            <h4 class="mb-0 fw-semibold">{{ number_format($stats['total_products']) }}</h4>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $stats['total_products'] > 0 ? ($stats['active_products'] / $stats['total_products']) * 100 : 0 }}%"></div>
                        </div>
                        <small class="text-muted">{{ $stats['active_products'] }} active products</small>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fs-sm text-gray-500">Total Stock</span>
                            <h4 class="mb-0 fw-semibold">{{ number_format($stats['total_stock']) }}</h4>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: 75%"></div>
                        </div>
                        <small class="text-muted">Units in inventory</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fs-sm text-gray-500">Low Stock Alert</span>
                            <h4 class="mb-0 fw-semibold text-warning">{{ number_format($stats['low_stock_products']) }}</h4>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: {{ $stats['total_products'] > 0 ? ($stats['low_stock_products'] / $stats['total_products']) * 100 : 0 }}%"></div>
                        </div>
                        <small class="text-muted">Products need restocking</small>
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('vendor.products.index', ['vendor' => auth('vendor')->user()]) }}" class="btn btn-primary btn-sm w-100">
                            <i class="fi fi-rr-box"></i> Manage Products
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- End - Products & Inventory -->

    </div>

    <div class="row">
        <!-- Start - Customer Transaction -->
        <div class="col-xxl-12 col-xl-12 order-md-1 order-xl-0">
            <div class="card">
                <div class="card-header border-0 align-items-center pb-2">
                    <h4 class="card-title">Recent Transactions</h4>
                    <span class="badge badge-primary">{{ $stats['total_transactions'] }} Total</span>
                </div>
                <div class="card-body px-2 pt-0 pb-2">
                    <div class="table-responsive check-wrapper">
                        <table class="table table-sm table-row-rounded table-borderless table-sm-responsive text-nowrap mb-2">
                            <thead>
                                <tr class="text-nowrap">
                                    <th>Transaction ID</th>
                                    <th>Customer</th>
                                    <th>Order #</th>
                                    <th>Payment Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($stats['recent_transactions'] as $transaction)
                                <tr>
                                    <td>
                                        <a href="{{ route('vendor.transactions.show', ['vendor' => $vendor, 'transaction' => $transaction]) }}" class="fw-bold text-decoration-none">{{ $transaction->reference }}</a>
                                    </td>
                                    <td>
                                        @if($transaction->order && $transaction->order->customer)
                                        <div class="d-flex align-items-center">
                                            <div class="avatar avatar-xs bg-primary text-white rounded-circle d-flex align-items-center justify-content-center">
                                                {{ strtoupper(substr($transaction->order->customer->first_name, 0, 1)) }}{{ strtoupper(substr($transaction->order->customer->last_name, 0, 1)) }}
                                            </div>
                                            <p class="mb-0 ms-2">{{ $transaction->order->customer->first_name }} {{ $transaction->order->customer->last_name }}</p>	
                                        </div>
                                        @else
                                        <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($transaction->order)
                                        <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $transaction->order]) }}" class="text-primary">
                                            {{ $transaction->order->order_number }}
                                        </a>
                                        @else
                                        <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($transaction->paymentMethod)
                                        <span class="badge badge-light">{{ $transaction->paymentMethod->name }}</span>
                                        @else
                                        <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="fw-semibold">₦{{ number_format($transaction->amount, 2) }}</span>
                                    </td>
                                    <td>
                                        @if($transaction->status === 'completed')
                                            <span class="badge badge-success light">Completed</span>
                                        @elseif($transaction->status === 'pending')
                                            <span class="badge badge-warning light">Pending</span>
                                        @elseif($transaction->status === 'failed')
                                            <span class="badge badge-danger light">Failed</span>
                                        @else
                                            <span class="badge badge-secondary light">{{ ucfirst($transaction->status) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-muted">{{ $transaction->created_at->format('d M Y') }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fi fi-rr-inbox fs-3 d-block mb-2"></i>
                                            No transactions found
                                        </div>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- End - Customer Transaction -->
    </div>

</div>
@endsection
