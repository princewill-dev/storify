@extends('management.layout')
@section('subtitle', $staff->name)

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$staff->name" subtitle="Staff since {{ $staff->created_at->format('d M Y') }}">
    <x-slot:actions>
        <x-management.status-badge :status="$staff->status" />
    </x-slot:actions>
</x-management.page-header>

<div>
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Left: Details --}}
        <div class="lg:col-span-3 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-slate-800 mb-5">Staff Details</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Email</span>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $staff->email }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Phone</span>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $staff->phone ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Invitation Sent</span>
                        <p class="text-sm text-slate-600 mt-0.5">
                            @if($staff->invited_at)
                                {{ $staff->invited_at->format('d M Y, h:i A') }}
                                <span class="text-xs text-slate-400">({{ $staff->invited_at->diffForHumans() }})</span>
                            @else
                                —
                            @endif
                        </p>
                    </div>
                    @if($staff->accepted_at)
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Invitation Accepted</span>
                        <p class="text-sm text-emerald-600 mt-0.5">
                            {{ $staff->accepted_at->format('d M Y, h:i A') }}
                            <span class="text-xs text-emerald-500">({{ $staff->accepted_at->diffForHumans() }})</span>
                        </p>
                    </div>
                    @endif
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider">Last Login</span>
                        <p class="text-sm text-slate-600 mt-0.5">{{ $staff->last_login_at?->diffForHumans() ?? 'Never' }}</p>
                    </div>
                    @if($staff->status === 'invited')
                    <div class="sm:col-span-2 pt-1">
                        <form action="{{ route('management.staff.resend-invite', $staff) }}" method="POST" class="inline-flex items-center gap-2">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-amber-50 text-amber-700 text-sm font-medium rounded-lg border border-amber-200 hover:bg-amber-100 transition-colors">
                                <i class="fi fi-rr-paper-plane text-xs"></i> Resend Invitation
                            </button>
                            <span class="text-xs text-slate-400">Invite pending — staff hasn't accepted yet</span>
                        </form>
                    </div>
                    @endif
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-slate-800 mb-4">Roles</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($staff->roles as $role)
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{{ $role->name }}</span>
                    @endforeach
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-slate-800 mb-4">Assigned Locations</h3>
                <div class="space-y-1.5">
                    @forelse($staff->assignedStores as $st)
                    <p class="text-sm text-slate-600"><i class="fi fi-rr-shop mr-1.5 text-slate-400"></i>{{ $st->name }}</p>
                    @empty
                    <p class="text-sm text-slate-400">No stores assigned</p>
                    @endforelse
                    @forelse($staff->assignedWarehouses as $wh)
                    <p class="text-sm text-slate-600"><i class="fi fi-rr-warehouse-alt mr-1.5 text-slate-400"></i>{{ $wh->name }}</p>
                    @empty
                    <p class="text-sm text-slate-400">No warehouses assigned</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right: Photo Card --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 sticky top-24">
                <h3 class="text-sm font-semibold text-slate-800 mb-5">Profile Photo</h3>
                <div class="flex flex-col items-center gap-5">
                    <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full ring-4 ring-slate-100 overflow-hidden bg-slate-200 shadow-inner">
                        <img src="{{ $staff->photoUrl() }}" alt="" class="w-full h-full object-cover">
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-semibold text-slate-800">{{ $staff->name }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $staff->email }}</p>
                    </div>
                    <a href="{{ route('management.staff.edit', $staff) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        Edit Staff
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
