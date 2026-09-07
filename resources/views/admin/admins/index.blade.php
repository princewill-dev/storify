@extends('admin.layout')

@section('subtitle', 'Admins')

@section('content')
<div class="flex items-center justify-between mb-6" x-data="{ inviteOpen: false }">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Admins</h2>
        <p class="text-sm text-slate-500 mt-0.5">Invite and manage platform admin accounts.</p>
    </div>
    <button @click="inviteOpen = true" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-user-add text-sm"></i> Invite Admin
    </button>

    {{-- Invite Modal --}}
    <div x-show="inviteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="inviteOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-slate-900">Invite Admin</h3>
                <button @click="inviteOpen = false" class="text-slate-400 hover:text-slate-600"><i class="fi fi-rr-cross"></i></button>
            </div>
            @if($errors->any())
            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
            @endif
            <form action="{{ route('admin.admins.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required placeholder="admin@example.com"
                        class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Role</label>
                    <select name="role" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="" disabled selected>Select a role</option>
                        @foreach($roles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">They will receive an email with a unique setup link.</p>
                </div>
                <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    Send Invitation
                </button>
            </form>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100 bg-slate-50/50">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Admin</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Role</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Invited</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($admins as $admin)
                <tr class="hover:bg-slate-50/50">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 text-slate-500 font-semibold text-sm">
                                {{ strtoupper(substr($admin->name ?: $admin->email, 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-medium text-slate-800">{{ $admin->name ?: 'Pending setup' }}</div>
                                <div class="text-xs text-slate-400">{{ $admin->email }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 px-4">
                        @if($admin->role === 'superadmin')
                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Super Admin</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $admin->roles->first()?->name ?? 'Admin' }}</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if($admin->status === 'invited')
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                        </span>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-slate-500">
                        {{ $admin->invited_at ? $admin->invited_at->format('M d, Y') : '—' }}
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center justify-end gap-1.5">
                            @if($admin->status === 'invited')
                            <form method="POST" action="{{ route('admin.admins.resend', $admin) }}">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Resend</button>
                            </form>
                            @endif
                            @if($admin->role !== 'superadmin')
                            <form method="POST" action="{{ route('admin.admins.update', $admin) }}" class="flex items-center gap-1">
                                @csrf
                                @method('PUT')
                                <select name="role" onchange="this.form.submit()" class="text-xs border-slate-200 rounded-md px-2 py-1 text-slate-600 bg-white">
                                    <option value="" disabled>Change role</option>
                                    @foreach($roles as $role)
                                    <option value="{{ $role->name }}" @selected($admin->hasRole($role->name))>{{ $role->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                            <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" onsubmit="return confirm('Remove {{ $admin->email }}? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button class="px-2.5 py-1 text-xs font-medium text-red-600 bg-red-50 rounded-md hover:bg-red-100">Remove</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-12 text-center">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                            <i class="fi fi-rr-users text-2xl text-slate-400"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-slate-700 mb-1">No admins yet</h3>
                        <p class="text-sm text-slate-400">Invite your first admin to help manage the platform.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
