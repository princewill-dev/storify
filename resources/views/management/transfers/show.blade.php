@extends('management.layout')
@section('subtitle', 'Transfer ' . $transfer->transfer_code)

@php
    $statusColor = match($transfer->status->value) {
        'draft' => 'slate',
        'pending' => 'amber',
        'approved' => 'indigo',
        'awaiting_acknowledgment' => 'orange',
        'dispatched' => 'purple',
        'received' => 'emerald',
        'rejected' => 'red',
        'cancelled' => 'red',
        default => 'slate',
    };
    $statusDot = match($transfer->status->value) {
        'draft' => 'bg-slate-400',
        'pending' => 'bg-amber-500',
        'approved' => 'bg-indigo-500',
        'awaiting_acknowledgment' => 'bg-orange-500',
        'dispatched' => 'bg-purple-500',
        'received' => 'bg-emerald-500',
        'rejected' => 'bg-red-500',
        'cancelled' => 'bg-red-500',
        default => 'bg-slate-400',
    };
    $totalUnits = $transfer->items->sum('quantity');
    $fromIsWarehouse = $transfer->fromLocation_type === 'App\\Models\\Warehouse';
    $toIsWarehouse = $transfer->toLocation_type === 'App\\Models\\Warehouse';
    $fromCode = $fromIsWarehouse
        ? ($transfer->fromLocation?->warehouse_code ?? '')
        : ($transfer->fromLocation?->store_code ?? '');
    $toCode = $toIsWarehouse
        ? ($transfer->toLocation?->warehouse_code ?? '')
        : ($transfer->toLocation?->store_code ?? '');

    // Build timeline steps
    $timelineSteps = [];
    $timelineSteps[] = ['label' => 'Created', 'done' => true, 'who' => $transfer->requester?->name, 'when' => $transfer->created_at, 'color' => 'blue'];
    if (!$transfer->isDraft()) {
        $timelineSteps[] = ['label' => 'Submitted', 'done' => true, 'who' => null, 'when' => null, 'color' => 'amber'];
    }
    if ($transfer->isAwaitingAcknowledgment()) {
        $timelineSteps[] = ['label' => 'Awaiting Ack', 'done' => true, 'who' => null, 'when' => null, 'color' => 'orange'];
    }
    if ($transfer->isApproved() || $transfer->isDispatched() || $transfer->isReceived()) {
        $timelineSteps[] = ['label' => 'Approved', 'done' => true, 'who' => $transfer->approver?->name, 'when' => $transfer->updated_at, 'color' => 'indigo'];
    } elseif ($transfer->isPending() && !$transfer->isAwaitingAcknowledgment()) {
        $timelineSteps[] = ['label' => 'Approved', 'done' => false, 'who' => null, 'when' => null, 'color' => 'indigo'];
    }
    if ($transfer->isDispatched() || $transfer->isReceived()) {
        $timelineSteps[] = ['label' => 'Dispatched', 'done' => true, 'who' => $transfer->dispatcher?->name, 'when' => $transfer->updated_at, 'color' => 'purple'];
    } elseif ($transfer->isApproved()) {
        $timelineSteps[] = ['label' => 'Dispatched', 'done' => false, 'who' => null, 'when' => null, 'color' => 'purple'];
    }
    if ($transfer->isReceived()) {
        $timelineSteps[] = ['label' => 'Received', 'done' => true, 'who' => $transfer->receiver?->name, 'when' => $transfer->updated_at, 'color' => 'emerald'];
    } elseif ($transfer->isDispatched()) {
        $timelineSteps[] = ['label' => 'Received', 'done' => false, 'who' => null, 'when' => null, 'color' => 'emerald'];
    }
    if ($transfer->isRejected()) {
        $timelineSteps[] = ['label' => 'Rejected', 'done' => true, 'who' => null, 'when' => $transfer->updated_at, 'color' => 'red'];
    }
    if ($transfer->isCancelled()) {
        $timelineSteps[] = ['label' => 'Cancelled', 'done' => true, 'who' => null, 'when' => $transfer->updated_at, 'color' => 'red'];
    }
@endphp

@section('content')
{{-- Flash messages --}}
@if(session('success'))
<div class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 mb-4">
    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 mb-4">
    <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span>
    {{ session('error') }}
</div>
@endif

{{-- ═══════════ BREADCRUMB + STATUS BAR ═══════════ --}}
<div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-200">
    <div class="flex items-center gap-2 text-sm">
        <a href="{{ route('management.transfers.index') }}" class="text-slate-500 hover:text-blue-600 transition-colors">Transfers</a>
        <span class="text-slate-300">/</span>
        <span class="font-mono font-medium text-slate-700">{{ $transfer->transfer_code }}</span>
    </div>
    <div class="flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full {{ $statusDot }}"></span>
        <span class="text-xs font-semibold text-slate-600">{{ $transfer->status->label() }}</span>
    </div>
</div>

{{-- ═══════════ SUMMARY STRIP ═══════════ --}}
<div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 bg-white rounded-lg border border-slate-200 mb-4 text-sm">
    <div class="flex items-center gap-1.5">
        <span class="text-slate-400 text-xs">From</span>
        <span class="font-medium text-slate-800">{{ $transfer->fromLocation?->name ?? '—' }}</span>
        @if($fromCode)
        <span class="font-mono text-[11px] text-slate-400">({{ $fromCode }})</span>
        @endif
    </div>
    <span class="text-slate-300">→</span>
    <div class="flex items-center gap-1.5">
        <span class="text-slate-400 text-xs">To</span>
        <span class="font-medium text-slate-800">{{ $transfer->toLocation?->name ?? '—' }}</span>
        @if($toCode)
        <span class="font-mono text-[11px] text-slate-400">({{ $toCode }})</span>
        @endif
    </div>
    <span class="w-px h-4 bg-slate-200"></span>
    <div>
        <span class="text-slate-400 text-xs">Items</span>
        <span class="font-semibold text-slate-800 ml-1">{{ $transfer->items->count() }}</span>
    </div>
    <span class="w-px h-4 bg-slate-200"></span>
    <div>
        <span class="text-slate-400 text-xs">Units</span>
        <span class="font-semibold text-slate-800 ml-1">{{ $totalUnits }}</span>
    </div>
    <span class="w-px h-4 bg-slate-200"></span>
    <div>
        <span class="text-slate-400 text-xs">Requested by</span>
        <span class="font-medium text-slate-700 ml-1">{{ $transfer->requester?->name ?? '—' }}</span>
    </div>
    <span class="w-px h-4 bg-slate-200"></span>
    <div>
        <span class="text-slate-400 text-xs">Date</span>
        <span class="font-medium text-slate-700 ml-1">{{ $transfer->created_at->format('d M Y, H:i') }}</span>
    </div>
</div>

{{-- ═══════════ AWAITING ACK BANNER ═══════════ --}}
@if($transfer->isAwaitingAcknowledgment())
<div class="flex items-start gap-3 rounded-lg border border-orange-200 bg-orange-50/80 px-4 py-3 mb-4">
    <span class="w-1.5 h-1.5 rounded-full bg-orange-500 mt-1.5 shrink-0"></span>
    <div>
        <p class="text-sm font-semibold text-orange-800">Quantities were adjusted during approval.</p>
        <p class="text-xs text-orange-600 mt-0.5">Review the approved quantities below and acknowledge to proceed.</p>
    </div>
</div>
@endif

{{-- ═══════════ MAIN GRID ═══════════ --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    {{-- LEFT: Items + Approval --}}
    <div class="lg:col-span-2 space-y-4">

        {{-- Items table --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="flex items-center justify-between pl-3 pr-4 py-2.5 border-b border-slate-200">
                <div class="flex items-center gap-2">
                    <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                    <h3 class="text-sm font-semibold text-slate-700">Items</h3>
                </div>
                <span class="text-[11px] text-slate-400 font-mono">{{ $transfer->items->count() }} line items</span>
            </div>
            <table class="w-full">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="pl-4 pr-2 py-2 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Product</th>
                        <th class="px-2 py-2 text-center text-[11px] font-semibold text-slate-500 uppercase tracking-wider w-24">Requested</th>
                        <th class="pr-4 pl-2 py-2 text-center text-[11px] font-semibold text-slate-500 uppercase tracking-wider w-24">Approved</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($transfer->items as $item)
                    @php
                        $img = $item->product?->images?->first();
                        $adjusted = $item->approved_quantity && $item->approved_quantity < $item->quantity;
                    @endphp
                    <tr class="{{ $loop->even ? 'bg-slate-50/50' : '' }} hover:bg-blue-50/30 transition-colors">
                        <td class="pl-4 pr-2 py-2.5">
                            <div class="flex items-center gap-2.5">
                                <a href="{{ route('management.products.show', $item->product) }}" class="w-8 h-8 rounded-md bg-slate-100 flex items-center justify-center overflow-hidden shrink-0 border border-slate-200">
                                    @if($img && $img->path)
                                    <img src="{{ asset('storage/' . $img->path) }}" alt="" class="w-full h-full object-cover" loading="lazy">
                                    @else
                                    <i class="fi fi-rr-cube text-slate-300 text-xs"></i>
                                    @endif
                                </a>
                                <div class="min-w-0">
                                    <a href="{{ route('management.products.show', $item->product) }}" class="text-sm font-medium text-slate-700 hover:text-blue-600 transition-colors truncate block">{{ $item->product?->name ?? 'Unknown Product' }}</a>
                                    <span class="text-[11px] font-mono text-slate-400">{{ $item->product?->product_code ?? '—' }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-center">
                            <span class="text-sm font-semibold text-slate-600">{{ $item->quantity }}</span>
                        </td>
                        <td class="pr-4 pl-2 py-2.5 text-center">
                            @if($item->approved_quantity)
                                @if($adjusted)
                                <span class="text-sm font-semibold text-amber-600">{{ $item->approved_quantity }}</span>
                                <span class="text-[11px] text-slate-300 line-through ml-1">{{ $item->quantity }}</span>
                                @else
                                <span class="text-sm font-semibold text-emerald-600">{{ $item->approved_quantity }}</span>
                                @endif
                            @else
                            <span class="text-xs text-slate-300">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Approval form --}}
        @if($transfer->canBeApproved() && auth()->user()->can('transfers approve'))
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-2 pl-3 pr-4 py-2.5 border-b border-slate-200">
                <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                <h3 class="text-sm font-semibold text-slate-700">Approve Transfer</h3>
            </div>
            <div class="p-4">
                <p class="text-xs text-slate-500 mb-4">Adjust quantities if needed. Changes will require requester acknowledgement.</p>
                <form method="POST" action="{{ route('management.transfers.approve', $transfer) }}">
                    @csrf @method('PATCH')
                    <div class="space-y-2 mb-4">
                        @foreach($transfer->items as $item)
                        <div class="flex items-center gap-3 py-1.5 px-3 rounded-md {{ $loop->even ? 'bg-slate-50/50' : '' }}">
                            <span class="text-sm text-slate-700 flex-1 truncate">{{ $item->product?->name }}</span>
                            <span class="text-[11px] text-slate-400">Req: {{ $item->quantity }}</span>
                            <input type="number" name="approved_quantities[{{ $item->id }}]" value="{{ $item->quantity }}" min="1" max="{{ $item->quantity }}" class="w-20 rounded-md border-slate-200 text-sm py-1.5 text-center shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-3 pt-3 border-t border-slate-100">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 transition-colors">
                            Approve Transfer
                        </button>
                        <button type="button" onclick="document.getElementById('zohoRejectForm').classList.toggle('hidden')" class="text-sm text-red-500 hover:text-red-700 font-medium transition-colors">
                            Reject
                        </button>
                    </div>
                </form>

                <form id="zohoRejectForm" method="POST" action="{{ route('management.transfers.reject', $transfer) }}" class="hidden mt-4 pt-4 border-t border-slate-100 space-y-3">
                    @csrf @method('PATCH')
                    <label class="block text-xs font-medium text-slate-600">Rejection Reason</label>
                    <textarea name="rejection_reason" rows="2" class="block w-full rounded-md border-slate-200 px-3 py-2 shadow-sm focus:border-red-400 focus:ring-1 focus:ring-red-400 text-sm" placeholder="Explain why this transfer is being rejected..." required></textarea>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 border border-red-200 text-red-600 text-sm font-semibold rounded-md hover:bg-red-100 transition-colors">
                        Confirm Rejection
                    </button>
                </form>
            </div>
        </div>
        @endif
    </div>

    {{-- RIGHT: Timeline + Details + Actions --}}
    <div class="space-y-4">

        {{-- Timeline --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-2 pl-3 pr-4 py-2.5 border-b border-slate-200">
                <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                <h3 class="text-sm font-semibold text-slate-700">Timeline</h3>
            </div>
            <div class="p-4">
                <div class="relative">
                    @foreach($timelineSteps as $i => $step)
                    @php $colorClass = $step['color']; @endphp
                    <div class="flex gap-3 {{ !$loop->last ? 'pb-3' : '' }}">
                        <div class="flex flex-col items-center shrink-0">
                            <span class="relative z-10 flex items-center justify-center w-4 h-4 rounded-full {{ $step['done'] ? 'bg-'.$colorClass.'-500' : 'bg-white border-2 border-slate-300' }}">
                                @if($step['done'])
                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                @endif
                            </span>
                            @if(!$loop->last)
                            <div class="w-px flex-1 {{ $step['done'] && ($timelineSteps[$i + 1]['done'] ?? false) ? 'bg-'.$timelineSteps[$i + 1]['color'].'-300' : 'bg-slate-200' }} mt-0.5 mb-0.5"></div>
                            @endif
                        </div>
                        <div class="pb-0.5 min-w-0">
                            <p class="text-xs font-semibold {{ $step['done'] ? 'text-slate-700' : 'text-slate-400' }}">{{ $step['label'] }}</p>
                            @if($step['done'] && ($step['who'] || $step['when']))
                            <p class="text-[11px] text-slate-400 mt-0.5 truncate">
                                @if($step['who']){{ $step['who'] }}@endif
                                @if($step['who'] && $step['when']) · @endif
                                @if($step['when']){{ $step['when']->format('d M H:i') }}@endif
                            </p>
                            @elseif(!$step['done'])
                            <p class="text-[11px] text-slate-300 mt-0.5">Pending</p>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Details --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-2 pl-3 pr-4 py-2.5 border-b border-slate-200">
                <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                <h3 class="text-sm font-semibold text-slate-700">Details</h3>
            </div>
            <div class="divide-y divide-slate-100">
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">From</span>
                    <span class="font-medium text-slate-700 text-right truncate ml-2">
                        {{ $transfer->fromLocation?->name ?? '—' }}
                        @if($fromCode)<span class="font-mono text-[11px] text-slate-400 ml-1">{{ $fromCode }}</span>@endif
                    </span>
                </div>
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">To</span>
                    <span class="font-medium text-slate-700 text-right truncate ml-2">
                        {{ $transfer->toLocation?->name ?? '—' }}
                        @if($toCode)<span class="font-mono text-[11px] text-slate-400 ml-1">{{ $toCode }}</span>@endif
                    </span>
                </div>
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">Requested by</span>
                    <span class="font-medium text-slate-700">{{ $transfer->requester?->name ?? '—' }}</span>
                </div>
                @if($transfer->approver)
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">Approved by</span>
                    <span class="font-medium text-slate-700">{{ $transfer->approver->name }}</span>
                </div>
                @endif
                @if($transfer->dispatcher)
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">Dispatched by</span>
                    <span class="font-medium text-slate-700">{{ $transfer->dispatcher->name }}</span>
                </div>
                @endif
                @if($transfer->receiver)
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-500">Received by</span>
                    <span class="font-medium text-slate-700">{{ $transfer->receiver->name }}</span>
                </div>
                @endif
                @if($transfer->notes)
                <div class="px-4 py-2.5">
                    <p class="text-xs text-slate-400 mb-0.5">Notes</p>
                    <p class="text-sm text-slate-600">{{ $transfer->notes }}</p>
                </div>
                @endif
                @if($transfer->rejection_reason)
                <div class="px-4 py-2.5">
                    <p class="text-xs text-slate-400 mb-0.5">Rejection reason</p>
                    <p class="text-sm text-red-600">{{ $transfer->rejection_reason }}</p>
                </div>
                @endif
            </div>
        </div>

        {{-- Actions --}}
        @if($transfer->canBeSubmitted() || $transfer->canBeAcknowledged() || $transfer->canBeDispatched() || $transfer->canBeReceived() || $transfer->canBeCancelled())
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-2 pl-3 pr-4 py-2.5 border-b border-slate-200">
                <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                <h3 class="text-sm font-semibold text-slate-700">Actions</h3>
            </div>
            <div class="p-4 space-y-2">

                @if($transfer->canBeSubmitted())
                <form method="POST" action="{{ route('management.transfers.submit', $transfer) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 transition-colors">
                        Submit for Approval
                    </button>
                </form>
                <p class="text-[11px] text-slate-400 px-1 -mt-1">Sends this transfer for review</p>
                @endif

                @if($transfer->canBeAcknowledged())
                <form method="POST" action="{{ route('management.transfers.acknowledge', $transfer) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full px-4 py-2 bg-orange-600 text-white text-sm font-semibold rounded-md hover:bg-orange-700 transition-colors">
                        Acknowledge Adjusted Quantities
                    </button>
                </form>
                <p class="text-[11px] text-slate-400 px-1 -mt-1">Confirm you've reviewed the changes</p>
                @endif

                @if($transfer->canBeDispatched() && auth()->user()->can('transfers dispatch'))
                <form method="POST" action="{{ route('management.transfers.dispatch', $transfer) }}" onsubmit="return confirm('Confirm dispatch? Warehouse stock will be decremented.')">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-md hover:bg-purple-700 transition-colors">
                        Dispatch
                    </button>
                </form>
                <p class="text-[11px] text-slate-400 px-1 -mt-1">Decrement warehouse stock and mark dispatched</p>
                @endif

                @if($transfer->canBeReceived() && auth()->user()->can('transfers receive'))
                <form method="POST" action="{{ route('management.transfers.receive', $transfer) }}" onsubmit="return confirm('Confirm receipt? Destination stock will be incremented.')">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 transition-colors">
                        Confirm Receipt
                    </button>
                </form>
                <p class="text-[11px] text-slate-400 px-1 -mt-1">Increment destination stock and finalize</p>
                @endif

                @if($transfer->canBeCancelled())
                <div class="pt-2 mt-2 border-t border-slate-100"></div>
                <form method="POST" action="{{ route('management.transfers.cancel', $transfer) }}" onsubmit="return confirm('Cancel this transfer? This cannot be undone.')">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full px-4 py-2 text-sm font-medium text-red-500 hover:text-red-700 hover:bg-red-50 rounded-md transition-colors text-center">
                        Cancel Transfer
                    </button>
                </form>
                @endif

            </div>
        </div>
        @endif

    </div>
</div>
@endsection
