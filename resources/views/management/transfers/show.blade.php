@extends('management.layout')
@section('subtitle', 'Transfer ' . $transfer->transfer_code)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('management.transfers.index') }}" class="text-slate-400 hover:text-slate-600">
            <i class="fi fi-rr-arrow-left"></i>
        </a>
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $transfer->transfer_code }}</h2>
            <p class="text-xs text-slate-400">{{ $transfer->fromLocation?->name }} → {{ $transfer->toLocation?->name }}</p>
        </div>
    </div>
    <x-management.status-badge :status="$transfer->status->value" />
</div>

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Items --}}
        <x-management.card header="Transfer Items">
            <x-management.data-table class="border-0 shadow-none rounded-none">
                <x-slot:header>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Requested</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Approved</th>
                </x-slot:header>
                @foreach($transfer->items as $item)
                <tr>
                    <td class="px-5 py-3">
                        <p class="text-sm font-medium text-slate-800">{{ $item->product?->name ?? 'Unknown' }}</p>
                        <p class="text-xs text-slate-400">{{ $item->product?->product_code }}</p>
                    </td>
                    <td class="px-5 py-3 text-center">
                        <span class="text-sm font-semibold text-slate-700">{{ $item->quantity }}</span>
                    </td>
                    <td class="px-5 py-3 text-center">
                        @if($item->approved_quantity)
                        <span class="text-sm font-semibold {{ $item->approved_quantity < $item->quantity ? 'text-amber-600' : 'text-emerald-600' }}">{{ $item->approved_quantity }}</span>
                        @else
                        <span class="text-xs text-slate-300">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </x-management.data-table>
        </x-management.card>

        {{-- Approval Form (visible when pending) --}}
        @if($transfer->canBeApproved() && auth()->user()->can('transfers approve'))
        <x-management.card header="Approve Transfer">
            <form method="POST" action="{{ route('management.transfers.approve', $transfer) }}" class="space-y-4">
                @csrf @method('PATCH')
                <p class="text-sm text-slate-500">Set approved quantities for each item:</p>
                @foreach($transfer->items as $item)
                <div class="flex items-center gap-3">
                    <span class="text-sm text-slate-700 w-48 truncate">{{ $item->product?->name }}</span>
                    <span class="text-xs text-slate-400">Requested: {{ $item->quantity }}</span>
                    <input type="number" name="approved_quantities[{{ $item->id }}]" value="{{ $item->quantity }}" min="1" max="{{ $item->quantity }}" class="w-24 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
                @endforeach
                <div class="flex items-center gap-2 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition-colors">
                        <i class="fi fi-rr-check text-xs"></i> Approve
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('management.transfers.reject', $transfer) }}" class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                @csrf @method('PATCH')
                <label class="block text-sm font-medium text-slate-700">Rejection Reason</label>
                <textarea name="rejection_reason" rows="2" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 text-sm" placeholder="Why is this transfer being rejected?" required></textarea>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 border border-red-200 text-red-600 text-sm font-semibold rounded-lg hover:bg-red-100 transition-colors">
                    <i class="fi fi-rr-cross-circle text-xs"></i> Reject Transfer
                </button>
            </form>
        </x-management.card>
        @endif

    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">

        <x-management.card header="Timeline">
            <div class="space-y-4 relative before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-px before:bg-slate-200">
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                        <i class="fi fi-rr-plus text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Created</p>
                        <p class="text-xs text-slate-400">{{ $transfer->created_at->format('d M Y, H:i') }} by {{ $transfer->requester?->name }}</p>
                    </div>
                </div>

                @if(!$transfer->isDraft())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <i class="fi fi-rr-paper-plane text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Submitted</p>
                        <p class="text-xs text-slate-400">{{ $transfer->updated_at->format('d M Y, H:i') }}</p>
                    </div>
                </div>
                @endif

                @if($transfer->isApproved() || $transfer->isDispatched() || $transfer->isReceived())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                        <i class="fi fi-rr-check-circle text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Approved</p>
                        <p class="text-xs text-slate-400">by {{ $transfer->approver?->name ?? '—' }}</p>
                    </div>
                </div>
                @endif

                @if($transfer->isDispatched() || $transfer->isReceived())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-purple-100 text-purple-600">
                        <i class="fi fi-rr-truck-side text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Dispatched</p>
                        <p class="text-xs text-slate-400">by {{ $transfer->dispatcher?->name ?? '—' }}</p>
                    </div>
                </div>
                @endif

                @if($transfer->isReceived())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <i class="fi fi-rr-check text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Received</p>
                        <p class="text-xs text-slate-400">by {{ $transfer->receiver?->name ?? '—' }}</p>
                    </div>
                </div>
                @endif

                @if($transfer->isRejected())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                        <i class="fi fi-rr-cross-circle text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Rejected</p>
                        <p class="text-xs text-slate-400">{{ $transfer->rejection_reason }}</p>
                    </div>
                </div>
                @endif

                @if($transfer->isCancelled())
                <div class="flex gap-3">
                    <span class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                        <i class="fi fi-rr-cross-circle text-[10px]"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">Cancelled</p>
                    </div>
                </div>
                @endif
            </div>
        </x-management.card>

        <x-management.card header="Details">
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">From</span>
                    <span class="text-sm font-medium text-slate-700">{{ $transfer->fromLocation?->name ?? '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">To</span>
                    <span class="text-sm font-medium text-slate-700">{{ $transfer->toLocation?->name ?? '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Requested By</span>
                    <span class="text-sm font-medium text-slate-700">{{ $transfer->requester?->name }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Items</span>
                    <span class="text-sm font-medium text-slate-700">{{ $transfer->items->count() }}</span>
                </div>
                @if($transfer->notes)
                <div class="pt-2 border-t border-slate-100">
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Notes</span>
                    <p class="text-sm text-slate-600 mt-1">{{ $transfer->notes }}</p>
                </div>
                @endif
            </div>
        </x-management.card>

        {{-- Action Buttons --}}
        <x-management.card>
            <div class="space-y-2">
                @if($transfer->canBeSubmitted())
                <form method="POST" action="{{ route('management.transfers.submit', $transfer) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="block w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">
                        <i class="fi fi-rr-paper-plane mr-1"></i> Submit for Approval
                    </button>
                </form>
                @endif

                @if($transfer->canBeDispatched() && auth()->user()->can('transfers dispatch'))
                <form method="POST" action="{{ route('management.transfers.dispatch', $transfer) }}" onsubmit="return confirm('Confirm dispatch? Warehouse stock will be decremented.')">
                    @csrf @method('PATCH')
                    <button type="submit" class="block w-full py-2 bg-purple-600 text-white text-xs font-semibold rounded-lg hover:bg-purple-700 transition-colors text-center">
                        <i class="fi fi-rr-truck-side mr-1"></i> Dispatch to Store
                    </button>
                </form>
                @endif

                @if($transfer->canBeReceived() && auth()->user()->can('transfers receive'))
                <form method="POST" action="{{ route('management.transfers.receive', $transfer) }}" onsubmit="return confirm('Confirm receipt? Store stock will be incremented.')">
                    @csrf @method('PATCH')
                    <button type="submit" class="block w-full py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors text-center">
                        <i class="fi fi-rr-check mr-1"></i> Confirm Receipt
                    </button>
                </form>
                @endif

                @if($transfer->canBeCancelled())
                <form method="POST" action="{{ route('management.transfers.cancel', $transfer) }}" onsubmit="return confirm('Cancel this transfer?')">
                    @csrf @method('PATCH')
                    <button type="submit" class="block w-full py-2 bg-red-50 border border-red-200 text-red-600 text-xs font-semibold rounded-lg hover:bg-red-100 transition-colors text-center">
                        <i class="fi fi-rr-cross-circle mr-1"></i> Cancel Transfer
                    </button>
                </form>
                @endif
            </div>
        </x-management.card>

    </div>
</div>
@endsection
