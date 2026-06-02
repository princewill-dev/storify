@extends('management.layout')
@section('subtitle', 'POS Sessions')

@section('content')
<x-management.page-header title="POS Sessions" subtitle="Session history and sales records across your stores" />

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <x-management.metric-card label="Open Sessions" :value="$openSessionsCount" icon="fi fi-rr-terminal" />
    <x-management.metric-card label="Today's POS Sales" value="₦{{ number_format($todaySales, 2) }}" icon="fi fi-rr-chart-histogram" />
    <x-management.metric-card label="POS Stores" :value="$allStores->where('pos_enabled', true)->count()" icon="fi fi-rr-shop" />
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('management.pos.index') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors {{ !request('status') && !request('store_id') ? 'bg-slate-900 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' }}">
                All Sessions
            </a>
            <a href="{{ route('management.pos.index', ['status' => 'open']) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors {{ request('status') === 'open' ? 'bg-emerald-600 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ request('status') === 'open' ? 'bg-white' : 'bg-emerald-500' }}"></span> Open
            </a>
            <a href="{{ route('management.pos.index', ['status' => 'closed']) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors {{ request('status') === 'closed' ? 'bg-slate-700 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ request('status') === 'closed' ? 'bg-white' : 'bg-slate-500' }}"></span> Closed
            </a>
        </div>
        <form method="GET" action="{{ route('management.pos.index') }}" class="flex items-center gap-2">
            @if(request('status'))
            <input type="hidden" name="status" value="{{ request('status') }}">
            @endif
            <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500 py-1.5">
                <option value="">All Stores</option>
                @foreach($allStores as $s)
                <option value="{{ $s->store_id }}" {{ request('store_id') == $s->store_id ? 'selected' : '' }}>{{ $s->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if($sessions->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 px-4">
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 mb-4"><i class="fi fi-rr-terminal text-2xl"></i></span>
        <h3 class="text-sm font-semibold text-slate-700 mb-1">No POS sessions found</h3>
        <p class="text-xs text-slate-400 text-center max-w-sm">
            @if(request('status') === 'open') No open sessions. Visit a store terminal to start one. @elseif(request('status') === 'closed') No closed sessions yet. @else Enable POS for a store and visit its terminal. @endif
        </p>
    </div>
    @else
    <div class="divide-y divide-slate-100">
        @foreach($sessions as $session)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50/50 transition-colors">
            <a href="{{ $session->isOpen() ? route('management.pos.terminal', $session->store) : route('management.pos.show', $session) }}" class="flex items-start gap-3 min-w-0 flex-1">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl shrink-0 {{ $session->status === 'open' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500' }}">
                    <i class="fi fi-rr-terminal text-sm"></i>
                </span>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-sm font-semibold text-slate-800 group-hover:text-blue-600 transition-colors">{{ $session->store->name }}</p>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $session->status === 'open' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20' : 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-500/20' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $session->status === 'open' ? 'bg-emerald-500' : 'bg-slate-400' }}"></span> {{ ucfirst($session->status) }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $session->staff?->name ?? 'Unknown' }} · {{ $session->opened_at->diffForHumans() }}</p>
                </div>
            </a>
            <div class="flex items-center gap-3 shrink-0">
                <div class="text-right text-xs">
                    <span class="text-slate-400">{{ $session->orders_count }} orders</span>
                </div>
                @if($session->isOpen())
                <a href="{{ route('management.pos.terminal', $session->store) }}" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800 transition-colors">Terminal</a>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    <div class="px-5 py-3 border-t border-slate-100">{{ $sessions->links() }}</div>
    @endif
</div>
@endsection
