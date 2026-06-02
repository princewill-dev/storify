@extends('management.layout')
@section('subtitle', 'Staff')

@section('content')
<div x-data="staffManager()">
<x-management.page-header title="Staff" subtitle="{{ $store ? $store->name . ' — assigned staff' : 'Manage your team members' }}">
    <x-slot:actions>
        <a href="{{ route('management.staff.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Invite Staff
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Email</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Role</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider w-16"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
        @forelse($staff as $member)
        <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3">
                <div class="flex items-center gap-2.5">
                    <img src="{{ $member->photoUrl() }}" alt="" class="h-7 w-7 rounded-full object-cover shrink-0 bg-slate-200">
                    <span class="text-sm font-medium text-slate-800">{{ $member->name }}</span>
                    @if($member->isBusinessOwner())
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Owner</span>
                    @endif
                </div>
            </td>
            <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-500">{{ $member->email }}</span></td>
            <td class="px-5 py-3 hidden md:table-cell">
                @foreach($member->roles as $role)<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 mr-1">{{ $role->name }}</span>@endforeach
            </td>
            <td class="px-5 py-3 text-center"><x-management.status-badge :status="$member->status" /></td>
            <td class="px-5 py-3 text-center">
                @unless($member->isBusinessOwner())
                <div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 z-40 mt-2 w-48 bg-white rounded-xl shadow-lg border border-slate-200 py-1">
                        <a href="{{ route('management.staff.show', $member) }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50"><i class="fi fi-rr-eye w-4"></i> View</a>
                        <a href="{{ route('management.staff.edit', $member) }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50"><i class="fi fi-rr-edit w-4"></i> Edit</a>
                        @if($member->status === 'invited')
                        <form action="{{ route('management.staff.resend-invite', $member) }}" method="POST">@csrf<button class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 w-full text-left"><i class="fi fi-rr-paper-plane w-4"></i>Resend Invite</button></form>
                        @elseif($member->status === 'active')
                        <button onclick="confirmStaff('Suspend','{{ route('management.staff.suspend', $member) }}','PATCH','{{ addslashes($member->name) }}')" class="flex items-center gap-2 px-3 py-2 text-sm text-amber-600 hover:bg-amber-50 w-full text-left"><i class="fi fi-rr-pause w-4"></i>Suspend</button>
                        @elseif($member->status === 'suspended')
                        <button onclick="confirmStaff('Activate','{{ route('management.staff.activate', $member) }}','PATCH','{{ addslashes($member->name) }}')" class="flex items-center gap-2 px-3 py-2 text-sm text-emerald-600 hover:bg-emerald-50 w-full text-left"><i class="fi fi-rr-play w-4"></i>Activate</button>
                        @endif
                        <button onclick="confirmStaff('Remove','{{ route('management.staff.destroy', $member) }}','DELETE','{{ addslashes($member->name) }}')" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i>Remove</button>
                    </div>
                </div>
                @endunless
            </td>
        </tr>
        @empty
        <tr><td colspan="5" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-users" title="No staff members" description="Invite team members." action-label="Invite Staff" action-url="{{ route('management.staff.create') }}" /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Confirm Action Modal --}}
<div x-show="confirming" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="confirming" x-transition class="fixed inset-0 bg-slate-900/50" @click="confirming = null"></div>
        <div x-show="confirming" x-transition class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2"><span x-text="confirming?.action"></span> staff member?</h3>
            <p class="text-sm text-slate-500 mb-4"><span x-text="confirming?.action"></span> <strong x-text="confirming?.name"></strong>?</p>
            <form :action="confirming?.url" method="POST">
                @csrf <input type="hidden" name="_method" :value="confirming?.method">
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700" x-text="confirming?.action"></button>
                    <button type="button" @click="confirming = null" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
@push('scripts')
<script>window.confirmStaff=function(a,u,m,n){var e=document.querySelector('[x-data="staffManager"]');if(e)Alpine.$data(e).confirming={action:a,url:u,method:m,name:n}};document.addEventListener('alpine:init',()=>{Alpine.data('staffManager',()=>({confirming:null}))});</script>
@endpush
@endsection
