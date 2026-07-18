@extends('admin.layout')
@section('subtitle', 'Transfer ' . $transfer->transfer_code)

@php
    $statusColor = match($transfer->status->value) {
        'draft' => 'bg-slate-100 text-slate-600',
        'pending' => 'bg-amber-50 text-amber-700',
        'approved' => 'bg-sky-50 text-sky-700',
        'awaiting_acknowledgment' => 'bg-amber-50 text-amber-700',
        'dispatched' => 'bg-blue-50 text-blue-700',
        'received' => 'bg-emerald-50 text-emerald-700',
        'rejected', 'cancelled' => 'bg-red-50 text-red-700',
        default => 'bg-slate-100 text-slate-600',
    };
    $totalUnits = $transfer->items->sum('quantity');
@endphp

@section('content')
<div>
  <div class="flex items-center justify-between mb-6">
    <div>
      <h2 class="text-lg font-bold text-slate-900">
        <code class="text-base bg-slate-100 px-2 py-0.5 rounded text-slate-700">{{ $transfer->transfer_code }}</code>
        <span class="inline-flex items-center rounded-full {{ $statusColor }} px-2.5 py-0.5 text-xs font-medium ml-2">{{ $transfer->status->label() }}</span>
      </h2>
      <div class="text-sm text-slate-500 mt-1">
        {{ $transfer->fromLocation?->name ?? '—' }} → {{ $transfer->toLocation?->name ?? '—' }}
      </div>
    </div>
    <a href="{{ route('admin.transfers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">← Back</a>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    {{-- Summary strip --}}
    <div class="lg:col-span-12">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 px-6 py-3">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
          <div><span class="text-slate-400">From</span> <strong class="text-slate-800">{{ $transfer->fromLocation?->name ?? '—' }}</strong></div>
          <span class="text-slate-300">→</span>
          <div><span class="text-slate-400">To</span> <strong class="text-slate-800">{{ $transfer->toLocation?->name ?? '—' }}</strong></div>
          <div class="border-l border-slate-200 pl-4"><span class="text-slate-400">Items</span> <strong class="text-slate-800">{{ $transfer->items->count() }}</strong></div>
          <div class="border-l border-slate-200 pl-4"><span class="text-slate-400">Units</span> <strong class="text-slate-800">{{ $totalUnits }}</strong></div>
          <div class="border-l border-slate-200 pl-4"><span class="text-slate-400">Requested by</span> <strong class="text-slate-800">{{ $transfer->requester?->name ?? '—' }}</strong></div>
          <div class="border-l border-slate-200 pl-4"><span class="text-slate-400">Date</span> <strong class="text-slate-800">{{ $transfer->created_at->format('d M Y, H:i') }}</strong></div>
        </div>
      </div>
    </div>

    {{-- Items --}}
    <div class="lg:col-span-7 space-y-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100">
          <strong class="text-sm text-slate-800">Items ({{ $transfer->items->count() }})</strong>
        </div>
        <table class="w-full text-sm">
          <thead class="border-b border-slate-100">
            <tr>
              <th class="py-3 px-6 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Product</th>
              <th class="py-3 px-6 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Requested</th>
              <th class="py-3 px-6 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Approved</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            @foreach($transfer->items as $item)
              @php $adjusted = $item->approved_quantity && $item->approved_quantity < $item->quantity; @endphp
              <tr>
                <td class="py-3 px-6">
                  <div class="font-semibold text-slate-800">{{ $item->product?->name ?? 'Unknown' }}</div>
                  <small class="text-slate-400 font-mono">{{ $item->product?->product_code ?? '—' }}</small>
                </td>
                <td class="py-3 px-6 text-center font-bold text-slate-700">{{ $item->quantity }}</td>
                <td class="py-3 px-6 text-center">
                  @if($item->approved_quantity)
                    @if($adjusted)
                      <span class="text-amber-600 font-bold">{{ $item->approved_quantity }}</span>
                      <small class="text-slate-400 line-through ml-1">{{ $item->quantity }}</small>
                    @else
                      <span class="text-emerald-600 font-bold">{{ $item->approved_quantity }}</span>
                    @endif
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      {{-- Approval form --}}
      @if($transfer->canBeApproved())
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Approve Transfer</h3>
        <form method="POST" action="{{ route('admin.transfers.approve', $transfer) }}">
          @csrf @method('PATCH')
          @foreach($transfer->items as $item)
          <div class="flex items-center gap-4 mb-2">
            <div class="w-1/2"><small class="text-slate-600">{{ $item->product?->name }}</small></div>
            <div class="w-1/4"><small class="text-slate-400">Req: {{ $item->quantity }}</small></div>
            <div class="w-1/4">
              <input type="number" name="approved_quantities[{{ $item->id }}]" value="{{ $item->quantity }}" min="1" max="{{ $item->quantity }}" class="w-20 rounded-lg border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
          </div>
          @endforeach
          <div class="flex items-center gap-2 mt-4">
            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Approve</button>
            <button type="button" onclick="openModal('rejectModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Reject</button>
          </div>
        </form>
      </div>

      {{-- Reject modal --}}
      <div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen p-4">
          <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('rejectModal')"></div>
          <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-4">
              <h5 class="text-base font-semibold text-slate-900">Reject Transfer</h5>
              <button onclick="closeModal('rejectModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.transfers.reject', $transfer) }}">
              @csrf @method('PATCH')
              <textarea name="rejection_reason" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Reason for rejection..." required></textarea>
              <div class="flex justify-end gap-2 mt-4 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('rejectModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Reject</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      @endif
    </div>

    {{-- Sidebar --}}
    <div class="lg:col-span-5 space-y-6">
      {{-- Timeline --}}
      <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100">
          <strong class="text-sm text-slate-800">Timeline</strong>
        </div>
        <div class="p-6">
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
            <div class="flex gap-2 {{ !$loop->last ? 'mb-2 pb-2 border-l-2 pl-3' : 'pl-3' }}" style="{{ $step['done'] ? 'border-color: #16a34a' : 'border-color: #e2e8f0' }}">
              <div>
                <div class="text-xs font-semibold text-slate-700">{{ $step['label'] }}</div>
                @if($step['who'] || $step['when'])
                  <div class="text-xs text-slate-400">{{ $step['who'] }}{{ $step['who'] && $step['when'] ? ' · ' : '' }}{{ $step['when']?->format('d M H:i') }}</div>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Details --}}
      <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100">
          <strong class="text-sm text-slate-800">Details</strong>
        </div>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-slate-50">
            <tr><td class="py-2.5 px-6 text-slate-400 w-32">From</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->fromLocation?->name ?? '—' }}</td></tr>
            <tr><td class="py-2.5 px-6 text-slate-400">To</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->toLocation?->name ?? '—' }}</td></tr>
            <tr><td class="py-2.5 px-6 text-slate-400">Requested by</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->requester?->name ?? '—' }}</td></tr>
            @if($transfer->approver)<tr><td class="py-2.5 px-6 text-slate-400">Approved by</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->approver->name }}</td></tr>@endif
            @if($transfer->dispatcher)<tr><td class="py-2.5 px-6 text-slate-400">Dispatched by</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->dispatcher->name }}</td></tr>@endif
            @if($transfer->receiver)<tr><td class="py-2.5 px-6 text-slate-400">Received by</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->receiver->name }}</td></tr>@endif
            @if($transfer->notes)<tr><td class="py-2.5 px-6 text-slate-400">Notes</td><td class="py-2.5 px-6 text-slate-700">{{ $transfer->notes }}</td></tr>@endif
            @if($transfer->rejection_reason)<tr><td class="py-2.5 px-6 text-slate-400">Rejection</td><td class="py-2.5 px-6 text-red-600">{{ $transfer->rejection_reason }}</td></tr>@endif
          </tbody>
        </table>
      </div>

      {{-- Actions --}}
      @if($transfer->canBeDispatched() || $transfer->canBeReceived() || $transfer->canBeCancelled())
      <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100">
          <strong class="text-sm text-slate-800">Actions</strong>
        </div>
        <div class="p-6 space-y-2">
          @if($transfer->canBeDispatched())
          <button type="button" onclick="openModal('dispatchTransfer{{ $transfer->id }}')" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800 w-full">Dispatch</button>
          <x-admin.confirm-modal id="dispatchTransfer{{ $transfer->id }}" title="Dispatch Transfer" message="Confirm dispatch?" action="{{ route('admin.transfers.dispatch', $transfer) }}" method="PATCH" confirmText="Dispatch" :danger="false" />
          @endif
          @if($transfer->canBeReceived())
          <button type="button" onclick="openModal('receiveTransfer{{ $transfer->id }}')" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 w-full">Confirm Receipt</button>
          <x-admin.confirm-modal id="receiveTransfer{{ $transfer->id }}" title="Confirm Receipt" message="Confirm receipt?" action="{{ route('admin.transfers.receive', $transfer) }}" method="PATCH" confirmText="Confirm Receipt" :danger="false" />
          @endif
          @if($transfer->canBeCancelled())
          <button type="button" onclick="openModal('cancelTransfer{{ $transfer->id }}')" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50 w-full">Cancel Transfer</button>
          <x-admin.confirm-modal id="cancelTransfer{{ $transfer->id }}" title="Cancel Transfer" message="Cancel this transfer?" action="{{ route('management.transfers.cancel', $transfer) }}" method="PATCH" confirmText="Cancel Transfer" />
          @endif
        </div>
      </div>
      @endif
    </div>
  </div>
</div>
@endsection
