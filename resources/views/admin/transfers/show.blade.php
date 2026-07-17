@extends('admin.layout')
@section('subtitle', 'Transfer ' . $transfer->transfer_code)

@php
    $statusColor = match($transfer->status->value) {
        'draft' => 'secondary',
        'pending' => 'warning',
        'approved' => 'info',
        'awaiting_acknowledgment' => 'warning',
        'dispatched' => 'primary',
        'received' => 'success',
        'rejected', 'cancelled' => 'danger',
        default => 'secondary',
    };
    $totalUnits = $transfer->items->sum('quantity');
@endphp

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">
        <code>{{ $transfer->transfer_code }}</code>
        <span class="badge bg-{{ $statusColor }} ms-2">{{ $transfer->status->label() }}</span>
      </h4>
      <div class="text-muted small mt-1">
        {{ $transfer->fromLocation?->name ?? '—' }} → {{ $transfer->toLocation?->name ?? '—' }}
      </div>
    </div>
    <a href="{{ route('admin.transfers.index') }}" class="btn btn-light btn-sm">← Back</a>
  </div>

  <div class="row g-3">
    {{-- Summary strip --}}
    <div class="col-12">
      <div class="card">
        <div class="card-body py-2">
          <div class="d-flex flex-wrap gap-3">
            <div><small class="text-muted">From</small> <strong>{{ $transfer->fromLocation?->name ?? '—' }}</strong></div>
            <div class="text-muted">→</div>
            <div><small class="text-muted">To</small> <strong>{{ $transfer->toLocation?->name ?? '—' }}</strong></div>
            <div class="border-start ps-3"><small class="text-muted">Items</small> <strong>{{ $transfer->items->count() }}</strong></div>
            <div class="border-start ps-3"><small class="text-muted">Units</small> <strong>{{ $totalUnits }}</strong></div>
            <div class="border-start ps-3"><small class="text-muted">Requested by</small> <strong>{{ $transfer->requester?->name ?? '—' }}</strong></div>
            <div class="border-start ps-3"><small class="text-muted">Date</small> <strong>{{ $transfer->created_at->format('d M Y, H:i') }}</strong></div>
          </div>
        </div>
      </div>
    </div>

    {{-- Items --}}
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><strong>Items ({{ $transfer->items->count() }})</strong></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Product</th><th class="text-center">Requested</th><th class="text-center">Approved</th></tr>
            </thead>
            <tbody>
              @foreach($transfer->items as $item)
                @php $adjusted = $item->approved_quantity && $item->approved_quantity < $item->quantity; @endphp
                <tr>
                  <td>
                    <div class="fw-semibold">{{ $item->product?->name ?? 'Unknown' }}</div>
                    <small class="text-muted font-monospace">{{ $item->product?->product_code ?? '—' }}</small>
                  </td>
                  <td class="text-center fw-bold">{{ $item->quantity }}</td>
                  <td class="text-center">
                    @if($item->approved_quantity)
                      @if($adjusted)
                        <span class="text-warning fw-bold">{{ $item->approved_quantity }}</span>
                        <small class="text-muted text-decoration-line-through ms-1">{{ $item->quantity }}</small>
                      @else
                        <span class="text-success fw-bold">{{ $item->approved_quantity }}</span>
                      @endif
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      {{-- Approval form --}}
      @if($transfer->canBeApproved())
      <div class="card mt-3">
        <div class="card-header"><strong>Approve Transfer</strong></div>
        <div class="card-body">
          <form method="POST" action="{{ route('admin.transfers.approve', $transfer) }}">
            @csrf @method('PATCH')
            @foreach($transfer->items as $item)
            <div class="row align-items-center mb-2">
              <div class="col-6"><small>{{ $item->product?->name }}</small></div>
              <div class="col-2"><small class="text-muted">Req: {{ $item->quantity }}</small></div>
              <div class="col-4">
                <input type="number" name="approved_quantities[{{ $item->id }}]" value="{{ $item->quantity }}" min="1" max="{{ $item->quantity }}" class="form-control form-control-sm" style="width:80px">
              </div>
            </div>
            @endforeach
            <div class="d-flex gap-2 mt-3">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">Reject</button>
            </div>
          </form>
        </div>
      </div>

      {{-- Reject modal --}}
      <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Reject Transfer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <form method="POST" action="{{ route('admin.transfers.reject', $transfer) }}">
            @csrf @method('PATCH')
            <div class="modal-body">
              <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Reason for rejection..." required></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-danger">Reject</button>
            </div>
          </form>
        </div></div>
      </div>
      @endif
    </div>

    {{-- Sidebar --}}
    <div class="col-lg-5">
      {{-- Timeline --}}
      <div class="card mb-3">
        <div class="card-header"><strong>Timeline</strong></div>
        <div class="card-body">
          @php
            $steps = [];
            $steps[] = ['label' => 'Created', 'done' => true, 'who' => $transfer->requester?->name, 'when' => $transfer->created_at];
            if (!$transfer->isDraft()) $steps[] = ['label' => 'Submitted', 'done' => true, 'who' => null, 'when' => null];
            if ($transfer->isAwaitingAcknowledgment()) $steps[] = ['label' => 'Awaiting Ack', 'done' => true, 'who' => null, 'when' => null];
            if ($transfer->isApproved() || $transfer->isDispatched() || $transfer->isReceived())
                $steps[] = ['label' => 'Approved', 'done' => true, 'who' => $transfer->approver?->name, 'when' => null];
            if ($transfer->isDispatched() || $transfer->isReceived())
                $steps[] = ['label' => 'Dispatched', 'done' => true, 'who' => $transfer->dispatcher?->name, 'when' => null];
            if ($transfer->isReceived())
                $steps[] = ['label' => 'Received', 'done' => true, 'who' => $transfer->receiver?->name, 'when' => null];
            if ($transfer->isRejected()) $steps[] = ['label' => 'Rejected', 'done' => true, 'who' => null, 'when' => null];
            if ($transfer->isCancelled()) $steps[] = ['label' => 'Cancelled', 'done' => true, 'who' => null, 'when' => null];
          @endphp
          @foreach($steps as $i => $step)
            <div class="d-flex gap-2 {{ !$loop->last ? 'mb-2 pb-2 border-start border-2 ps-3' : 'ps-3' }}" style="{{ $step['done'] ? 'border-color: #198754!important' : 'border-color: #dee2e6!important' }}">
              <div>
                <div class="fw-semibold small">{{ $step['label'] }}</div>
                @if($step['who'] || $step['when'])
                  <div class="text-muted small">{{ $step['who'] }}{{ $step['who'] && $step['when'] ? ' · ' : '' }}{{ $step['when']?->format('d M H:i') }}</div>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Details --}}
      <div class="card mb-3">
        <div class="card-header"><strong>Details</strong></div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><td class="text-muted">From</td><td>{{ $transfer->fromLocation?->name ?? '—' }}</td></tr>
            <tr><td class="text-muted">To</td><td>{{ $transfer->toLocation?->name ?? '—' }}</td></tr>
            <tr><td class="text-muted">Requested by</td><td>{{ $transfer->requester?->name ?? '—' }}</td></tr>
            @if($transfer->approver)<tr><td class="text-muted">Approved by</td><td>{{ $transfer->approver->name }}</td></tr>@endif
            @if($transfer->dispatcher)<tr><td class="text-muted">Dispatched by</td><td>{{ $transfer->dispatcher->name }}</td></tr>@endif
            @if($transfer->receiver)<tr><td class="text-muted">Received by</td><td>{{ $transfer->receiver->name }}</td></tr>@endif
            @if($transfer->notes)<tr><td class="text-muted">Notes</td><td>{{ $transfer->notes }}</td></tr>@endif
            @if($transfer->rejection_reason)<tr><td class="text-muted">Rejection</td><td class="text-danger">{{ $transfer->rejection_reason }}</td></tr>@endif
          </table>
        </div>
      </div>

      {{-- Actions --}}
      @if($transfer->canBeDispatched() || $transfer->canBeReceived() || $transfer->canBeCancelled())
      <div class="card">
        <div class="card-header"><strong>Actions</strong></div>
        <div class="card-body">
          @if($transfer->canBeDispatched())
          <button type="button" class="btn btn-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#dispatchTransfer{{ $transfer->id }}">Dispatch</button>
          <x-admin.confirm-modal id="dispatchTransfer{{ $transfer->id }}" title="Dispatch Transfer" message="Confirm dispatch?" action="{{ route('admin.transfers.dispatch', $transfer) }}" method="PATCH" confirmText="Dispatch" :danger="false" />
          @endif
          @if($transfer->canBeReceived())
          <button type="button" class="btn btn-success btn-sm w-100" data-bs-toggle="modal" data-bs-target="#receiveTransfer{{ $transfer->id }}">Confirm Receipt</button>
          <x-admin.confirm-modal id="receiveTransfer{{ $transfer->id }}" title="Confirm Receipt" message="Confirm receipt?" action="{{ route('admin.transfers.receive', $transfer) }}" method="PATCH" confirmText="Confirm Receipt" :danger="false" />
          @endif
          @if($transfer->canBeCancelled())
          <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#cancelTransfer{{ $transfer->id }}">Cancel Transfer</button>
          <x-admin.confirm-modal id="cancelTransfer{{ $transfer->id }}" title="Cancel Transfer" message="Cancel this transfer?" action="{{ route('management.transfers.cancel', $transfer) }}" method="PATCH" confirmText="Cancel Transfer" />
          @endif
        </div>
      </div>
      @endif
    </div>
  </div>
</div>
@endsection
