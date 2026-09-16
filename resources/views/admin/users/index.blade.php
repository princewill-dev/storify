@extends('admin.layout')
@section('subtitle', 'Users')

@section('content')
<div x-data="usersPage">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-bold text-slate-900">Users</h2>
            <p class="text-sm text-slate-500 mt-0.5">Business owners and staff accounts across the platform.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 font-medium text-slate-600">
                <i class="fi fi-rr-user text-[11px]"></i> {{ number_format($stats['owners']) }} owners
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 font-medium text-slate-600">
                <i class="fi fi-rr-users text-[11px]"></i> {{ number_format($stats['staff']) }} staff
            </span>
            @if($stats['suspended'])
            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1.5 font-medium text-red-600">
                {{ number_format($stats['suspended']) }} suspended
            </span>
            @endif
            @if($stats['unverified'])
            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 font-medium text-amber-600">
                {{ number_format($stats['unverified']) }} unverified
            </span>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <form method="GET" class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2">
            <input name="q" value="{{ $q }}" placeholder="Search name, email, phone, code..." class="flex-1 min-w-[200px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            <select name="role" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                <option value="business_owner" @selected($role === 'business_owner')>Owners</option>
                <option value="staff" @selected($role === 'staff')>Staff</option>
                <option value="all" @selected($role === 'all')>All roles</option>
            </select>
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                <option value="">Any status</option>
                @foreach(['active', 'suspended', 'deleted'] as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select name="verified" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                <option value="">Any verification</option>
                <option value="yes" @selected($verified === 'yes')>Verified</option>
                <option value="no" @selected($verified === 'no')>Unverified</option>
            </select>
            <select name="has_business" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                <option value="">Business: any</option>
                <option value="yes" @selected($hasBusiness === 'yes')>Has business</option>
                <option value="no" @selected($hasBusiness === 'no')>No business</option>
            </select>
            <select name="subscription" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                <option value="">Plan: any</option>
                <option value="active" @selected($subscription === 'active')>Active plan</option>
                <option value="trial" @selected($subscription === 'trial')>On trial</option>
                <option value="none" @selected($subscription === 'none')>No plan</option>
            </select>
            <button class="px-3 py-2 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800">Filter</button>
            @if(request()->hasAny(['q', 'role', 'status', 'verified', 'has_business', 'subscription']))
            <a href="{{ route('admin.users.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
            @endif
        </form>

        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 bg-slate-50/50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Role</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Verified</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Plan</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Last Login</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($users as $user)
                <tr class="hover:bg-slate-50/50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $user->name ?: 'Unnamed user' }}</a>
                        <div class="text-xs text-slate-400">{{ $user->email }}</div>
                        <div class="text-[10px] text-slate-300 font-mono">{{ $user->account_code }}</div>
                    </td>
                    <td class="px-4 py-3">
                        @if($user->business)
                        <span class="text-slate-700">{{ $user->business->name }}</span>
                        <div class="text-xs text-slate-400 font-mono">{{ $user->business->business_code }}</div>
                        @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">No business</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $user->role === 'business_owner' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                            {{ $user->role === 'business_owner' ? 'Owner' : 'Staff' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $user->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($user->status === 'suspended' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500') }}">
                            {{ ucfirst($user->status ?? 'unknown') }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($user->is_verified)
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600"><i class="fi fi-rr-check-circle text-[11px]"></i> Verified</span>
                        @else
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-600"><i class="fi fi-rr-clock text-[11px]"></i> Pending</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php($sub = $user->business?->activeSubscription)
                        @if($sub)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ $sub->subscriptionPlan?->name ?? 'Active' }}</span>
                        @elseif($user->trial_ends_at && $user->trial_ends_at->isFuture())
                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Trial</span>
                        @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">None</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="relative inline-block" x-data="{ open: false }">
                            <button @click="open = !open" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                                <i class="fi fi-rr-menu-dots text-sm"></i>
                            </button>
                            <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 text-left">
                                <a href="{{ route('admin.users.show', $user) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    <i class="fi fi-rr-eye text-slate-400"></i> View
                                </a>
                                <button
                                    data-user="{{ json_encode($user->only(['account_code', 'name', 'email', 'phone'])) }}"
                                    @click="openEdit($el.dataset.user); open = false"
                                    class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                    <i class="fi fi-rr-pencil text-slate-400"></i> Edit
                                </button>
                                @if($user->status === 'suspended')
                                <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="Reactivated by admin from user list">
                                    <button class="flex items-center gap-2 w-full px-3 py-2 text-sm text-emerald-600 hover:bg-emerald-50 text-left">
                                        <i class="fi fi-rr-check-circle"></i> Activate
                                    </button>
                                </form>
                                @elseif($user->status !== 'deleted')
                                <button onclick="openModal('suspendUserModal'); document.getElementById('suspendUserForm').action='{{ route('admin.users.suspend', $user) }}'; document.getElementById('suspendUserName').textContent='{{ $user->name }}'; open = false"
                                        class="flex items-center gap-2 w-full px-3 py-2 text-sm text-amber-600 hover:bg-amber-50 text-left">
                                    <i class="fi fi-rr-ban"></i> Suspend
                                </button>
                                @endif
                                @can('admin.users.impersonate')
                                @if($user->status !== 'deleted')
                                <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" onsubmit="return confirm('Log in as {{ $user->name }}? You will be able to browse as this user until you return to admin.')">
                                    @csrf
                                    <button class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                        <i class="fi fi-rr-user-shield text-slate-400"></i> Login as user
                                    </button>
                                </form>
                                @endif
                                @endcan
                                @if($user->status === 'deleted')
                                <form method="POST" action="{{ route('admin.users.restore', $user) }}">
                                    @csrf
                                    <button class="flex items-center gap-2 w-full px-3 py-2 text-sm text-emerald-600 hover:bg-emerald-50 text-left">
                                        <i class="fi fi-rr-undo"></i> Restore
                                    </button>
                                </form>
                                @else
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete {{ $user->name }}? Their account will be deactivated.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="flex items-center gap-2 w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left">
                                        <i class="fi fi-rr-trash text-red-400"></i> Delete
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-5 py-12 text-center">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                            <i class="fi fi-rr-users text-2xl text-slate-400"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-slate-700 mb-1">No users found</h3>
                        <p class="text-sm text-slate-400">Try adjusting the filters or search term.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($users->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">{{ $users->links() }}</div>
        @endif
    </div>

    {{-- Edit modal --}}
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="editOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4">Edit User</h3>
            <form method="POST" :action="'{{ url('office/users') }}/' + form.account_code" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Name</label>
                    <input type="text" name="name" x-model="form.name" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                    <input type="email" name="email" x-model="form.email" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone</label>
                    <input type="text" name="phone" x-model="form.phone" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Changes</button>
                    <button type="button" @click="editOpen = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Suspend modal --}}
<div id="suspendUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('suspendUserModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-base font-bold text-slate-900 mb-1">Suspend User</h3>
        <p class="text-sm text-slate-500 mb-4">Suspending <span id="suspendUserName" class="font-semibold text-slate-700"></span> will block their access. They will be emailed the reason.</p>
        <form id="suspendUserForm" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Reason</label>
                <textarea name="reason" rows="3" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Explain why this account is being suspended"></textarea>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-500">Suspend User</button>
                <button type="button" onclick="closeModal('suspendUserModal')" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('usersPage', () => ({
        editOpen: false,
        form: { account_code: '', name: '', email: '', phone: '' },
        openEdit(payload) {
            const data = JSON.parse(payload);
            this.form = {
                account_code: data.account_code,
                name: data.name ?? '',
                email: data.email ?? '',
                phone: data.phone ?? '',
            };
            this.editOpen = true;
        },
    }));
});
</script>
@endpush
