@extends('admin.layout')

@section('subtitle', 'Review Family Pack - ' . $familyPackOrder->pack_code)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.family-packs.index') }}">Family Packs</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $familyPackOrder->pack_code }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0 text-gray-800">Review Request</h1>
        </div>
        <div>
            <div class="d-flex align-items-center">
                <span class="badge {{ $familyPackOrder->status->badgeClass() }} px-3 py-2 text-lg">
                    {{ $familyPackOrder->status->label() }}
                </span>
                -
                @if($familyPackOrder->last_updated_by === 'customer')
                    <span class="badge bg-success px-3 py-2 text-lg ml-2">Customer accepted</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Items Management -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Pack Items</h6>
                    @if($showEditHint)
                        <span class="text-xs text-info"><i class="fas fa-info-circle mr-1"></i> You can update prices and quantities below</span>
                    @endif
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.family-packs.update', $familyPackOrder->id) }}" method="POST" id="updateForm">
                        @csrf
                        @method('PUT')

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width: 40%">Product</th>
                                        <th style="width: 15%">Qty</th>
                                        <th style="width: 20%">Unit Price (₦)</th>
                                        <th style="width: 25%">Total (₦)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($familyPackOrder->items as $item)
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold">{{ $item->product_name }}</div>
                                                @if($item->is_custom)
                                                    <span class="badge badge-info">Custom Item</span>
                                                    <div class="small text-muted mt-1">
                                                        Budget: ₦{{ number_format($item->budgeted_amount, 2) }}
                                                    </div>
                                                @else
                                                    <div class="small text-muted">{{ $item->product_code }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <input type="number" name="items[{{ $item->id }}][quantity]" 
                                                    class="form-control form-control-sm" 
                                                    value="{{ $item->quantity }}" min="1"
                                                    {{ $disableInputs ? 'disabled' : '' }}>
                                            </td>
                                            <td>
                                                <input type="number" name="items[{{ $item->id }}][unit_price]" 
                                                    class="form-control form-control-sm mb-1" 
                                                    value="{{ $item->unit_price }}" step="0.01" min="0" placeholder="0.00"
                                                    {{ $disableInputs ? 'disabled' : '' }}>
                                                @if($item->is_custom)
                                                    <div class="small text-muted">Accepted total for this custom item</div>
                                                    <input type="number" name="items[{{ $item->id }}][accepted_amount]"
                                                        class="form-control form-control-sm" step="0.01" min="0" placeholder="Accepted amount (₦)"
                                                        {{ $disableInputs ? 'disabled' : '' }}>
                                                @endif
                                            </td>
                                            <td class="text-right align-middle">
                                                @if($item->subtotal > 0)
                                                    ₦{{ number_format($item->subtotal, 2) }}
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="4">
                                            <div class="order-summary-inline">
                                                <span><strong>Subtotal:</strong> ₦{{ number_format($familyPackOrder->subtotal, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span><strong>Shipping:</strong> ₦{{ number_format($shippingFee, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span><strong>Tax ({{ number_format($vatPercentage, 1) }}%):</strong> ₦{{ number_format($tax, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span class="total"><strong>Total:</strong> ₦{{ number_format($total, 2) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="form-group mt-3">
                            <label class="font-weight-bold">Admin Notes</label>
                            <textarea name="review_notes" class="form-control" rows="3" placeholder="Add notes for the customer...">{{ $familyPackOrder->review_notes }}</textarea>
                            <small class="form-text text-muted">These notes will be visible to the customer.</small>
                        </div>
                    </form>

                    @if($canSave || $canFinalize)
                        <div class="row mt-3 justify-content-between">
                            @if($canSave)
                                <div class="col-md-6">
                                    <button type="submit" form="updateForm" class="btn btn-primary btn-block">
                                        <i class="fas fa-save mr-1"></i> Save Updates & Notify Customer
                                    </button>
                                </div>
                            @endif

                            @if($canFinalize && $finalize)
                                <div class="col-md-6">
                                    <form action="{{ $finalize['route'] }}" method="POST" id="finalizeForm">
                                        @csrf
                                        <button type="button" id="finalizeTriggerBtn" class="btn btn-success btn-block" data-bs-toggle="modal" data-bs-target="#finalizeConfirmModal">
                                            <i class="fas fa-{{ $finalize['icon'] }} mr-2"></i> {{ $finalize['label'] }}
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

        </div>

        <div class="col-lg-4">
            <!-- Customer Info -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Customer Details</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="font-weight-bold">{{ $familyPackOrder->customer->first_name }} {{ $familyPackOrder->customer->last_name }}</div>
                        <div><a href="mailto:{{ $familyPackOrder->customer->email }}">{{ $familyPackOrder->customer->email }}</a></div>
                        <div>{{ $familyPackOrder->customer->phone }}</div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <div class="font-weight-bold text-gray-600 mb-1">Delivery Address</div>
                        @if($familyPackOrder->deliveryAddress)
                            <div>{{ $familyPackOrder->deliveryAddress->full_address }}</div>
                        @else
                            <div class="text-muted">No address provided</div>
                        @endif
                    </div>
                    @if($familyPackOrder->notes)
                        <hr>
                        <div>
                            <div class="font-weight-bold text-gray-600 mb-1">Customer Notes</div>
                            <div class="bg-light p-2 rounded small">{{ $familyPackOrder->notes }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <!-- Pack Info -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Pack Information</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-gray-600">Type:</td>
                                    <td class="font-weight-bold text-capitalize">{{ $familyPackOrder->pack_type }}</td>
                                </tr>
                                <tr>
                                    <td class="text-gray-600">Store:</td>
                                    <td>{{ $familyPackOrder->store->name }}</td>
                                </tr>
                                <tr>
                                    <td class="text-gray-600">Route:</td>
                                    <td>{{ $familyPackOrder->deliveryRoute->state ?? 'N/A' }} -> {{ $familyPackOrder->deliveryRoute->area ?? 'N/A' }}</td>
                                </tr>
                                @if($familyPackOrder->pack_type === 'recurring')
                                    <tr>
                                        <td class="text-gray-600">Payment:</td>
                                        <td>{{ $familyPackOrder->payment_interval }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-gray-600">Delivery:</td>
                                        <td>{{ $familyPackOrder->deliveryInterval->name ?? 'Monthly' }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
            </div>
            <div class="col-md-6">
                <!-- Subscription Summary -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Subscription Summary</h6>
                        </div>
                        <div class="card-body">
                            @if($familyPackOrder->subscription)
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-gray-600">Payment Interval:</td>
                                        <td>{{ $familyPackOrder->subscription->payment_interval->label() }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-gray-600">Cycles:</td>
                                        <td>
                                            {{ $familyPackOrder->subscription->current_cycle }} / {{ $familyPackOrder->subscription->total_cycles }}
                                            <span class="text-muted">(Remaining: {{ $familyPackOrder->subscription->remaining_cycles }})</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-gray-600">Next Payment:</td>
                                        <td>{{ optional($familyPackOrder->subscription->next_payment_date)->format('M j, Y') ?? '—' }}</td>
                                    </tr>
                                </table>
                            @else
                                <div class="text-muted">No active subscription.</div>
                            @endif
                        </div>
                    </div>
            </div>
        </div>


        <div class="row">
            <div class="col-md-12">
                <!-- Delivery Cycles -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Delivery Cycles</h6>
                        @if($familyPackOrder->subscription)
                            <span class="small text-muted">
                                Cycle {{ $familyPackOrder->subscription->current_cycle }} of {{ $familyPackOrder->subscription->total_cycles }} · Remaining {{ $familyPackOrder->subscription->remaining_cycles }}
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if($familyPackOrder->deliveries && $familyPackOrder->deliveries->count())
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width: 10%">Cycle</th>
                                            <th style="width: 20%">Scheduled</th>
                                            <th style="width: 15%">Amount</th>
                                            <th style="width: 15%">Status</th>
                                            <th style="width: 20%">Order</th>
                                            <th style="width: 20%">Payment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($familyPackOrder->deliveries->sortBy('cycle_number') as $delivery)
                                            <tr>
                                                <td class="align-middle">#{{ $delivery->cycle_number }}</td>
                                                <td class="align-middle">{{ optional($delivery->scheduled_date)->format('M j, Y') ?? '-' }}</td>
                                                <td class="align-middle">₦{{ number_format($total, 2) }}</td>
                                                <td class="align-middle">
                                                    @if($delivery->order)
                                                        <span class="badge {{ $delivery->order->status->badgeClass() }}">{{ $delivery->order->status->label() }}</span>
                                                    @else
                                                        <span class="badge {{ $delivery->status->badgeClass() }}">{{ $delivery->status->label() }}</span>
                                                    @endif
                                                </td>
                                                <td class="align-middle">
                                                    @if($delivery->order)
                                                        <a href="{{ route('admin.orders.show', $delivery->order) }}">{{ $delivery->order->order_number }}</a>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="align-middle">
                                                    @if($delivery->payment)
                                                        <span class="badge {{ $delivery->payment->status_badge_class }}">{{ $delivery->payment->status_label }}</span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-muted">No cycles generated yet.</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($canFinalize && $finalize)
<!-- Finalize Confirmation Modal -->
<div class="modal fade" id="finalizeConfirmModal" tabindex="-1" aria-labelledby="finalizeConfirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="finalizeConfirmLabel">{{ $finalize['label'] }}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        {{ $finalize['confirm'] }}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmFinalizeBtn">
          <i class="fas fa-{{ $finalize['icon'] }} mr-1"></i> {{ $finalize['label'] }}
        </button>
      </div>
    </div>
  </div>
  </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var confirmBtn = document.getElementById('confirmFinalizeBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var form = document.getElementById('finalizeForm');
            if (form) form.submit();
        });
    }
    // Fallback: explicitly show modal via JS (Bootstrap 5 API)
    var trigger = document.getElementById('finalizeTriggerBtn');
    var modalEl = document.getElementById('finalizeConfirmModal');
    if (trigger && modalEl && window.bootstrap && bootstrap.Modal) {
        trigger.addEventListener('click', function(e){
            try {
                var inst = bootstrap.Modal.getOrCreateInstance(modalEl);
                inst.show();
            } catch (err) {}
        });
    }
});
</script>
@endif
@endsection
