@extends('admin.layout')
@section('subtitle', $user->name ?: 'User')

@section('content')
<div class="flex items-start justify-between mb-6">
    <div>
        <div class="flex items-center gap-3">
            <h2 class="text-lg font-bold text-slate-900">{{ $user->name ?: 'Unnamed user' }}</h2>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $user->role === 'business_owner' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                {{ $user->role === 'business_owner' ? 'Business Owner' : 'Staff' }}
            </span>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                {{ $user->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($user->status === 'suspended' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500') }}">
                {{ ucfirst($user->status ?? 'unknown') }}
            </span>
            @if($user->is_verified)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700"><i class="fi fi-rr-check-circle text-[10px]"></i> Verified</span>
            @else
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700"><i class="fi fi-rr-clock text-[10px]"></i> Unverified</span>
            @endif
        </div>
        <p class="text-sm text-slate-500 mt-1">{{ $user->email }} · <span class="font-mono text-xs">{{ $user->account_code }}</span></p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="openModal('editUserModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            <i class="fi fi-rr-pencil text-xs"></i> Edit
        </button>
        @if($user->is_verified)
        <form method="POST" action="{{ route('admin.users.unverify', $user) }}" onsubmit="return confirm('Remove verification for {{ $user->name }}? They will be asked to verify again.')">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                <i class="fi fi-rr-cross-circle text-xs"></i> Unverify
            </button>
        </form>
        @else
        <form method="POST" action="{{ route('admin.users.verify', $user) }}">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100">
                <i class="fi fi-rr-check-circle text-xs"></i> Verify
            </button>
        </form>
        @endif
        <button onclick="openModal('resetPasswordModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            <i class="fi fi-rr-key text-xs"></i> Reset Password
        </button>
        @if($user->status === 'suspended')
        <form method="POST" action="{{ route('admin.users.activate', $user) }}">
            @csrf
            <input type="hidden" name="reason" value="Reactivated by admin">
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500">
                <i class="fi fi-rr-check text-xs"></i> Activate
            </button>
        </form>
        @elseif($user->status !== 'deleted')
        <button onclick="openModal('suspendUserModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-red-50 text-red-600 hover:bg-red-100">
            <i class="fi fi-rr-ban text-xs"></i> Suspend
        </button>
        @endif
        @if($user->status === 'deleted')
        <form method="POST" action="{{ route('admin.users.restore', $user) }}">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500">
                <i class="fi fi-rr-undo text-xs"></i> Restore
            </button>
        </form>
        @endif
        @can('admin.users.impersonate')
        @if($user->status !== 'deleted' && ! $user->isAdmin())
        <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" onsubmit="return confirm('Log in as {{ $user->name }}? You can return to admin at any time.')">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                <i class="fi fi-rr-user-shield text-xs"></i> Login as User
            </button>
        </form>
        @endif
        @endcan
        <a href="{{ route('admin.users.index') }}" class="px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="space-y-6">
        {{-- Profile --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Account</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-800">{{ $user->email }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-800">{{ $user->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Location</dt><dd class="font-medium text-slate-800">{{ $user->location ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Last login</dt><dd class="font-medium text-slate-800">{{ $user->last_login_at?->format('d M Y H:i') ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Last IP</dt><dd class="font-mono text-xs text-slate-600">{{ $user->ip_address ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Joined</dt><dd class="font-medium text-slate-800">{{ $user->created_at?->format('d M Y') }}</dd></div>
                @if($user->force_password_change)
                <div class="flex justify-between"><dt class="text-slate-500">Password</dt><dd class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Change required</dd></div>
                @endif
            </dl>
        </div>

        {{-- Business --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Business</h3>
            @if($user->business)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-800">{{ $user->business->name }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Code</dt><dd class="font-mono text-xs text-slate-600">{{ $user->business->business_code }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Stores</dt><dd class="font-medium text-slate-800">{{ $user->business->stores_count ?? $user->business->stores->count() }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Warehouses</dt><dd class="font-medium text-slate-800">{{ $user->business->warehouses_count ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Team</dt><dd class="font-medium text-slate-800">{{ $user->business->users_count ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Orders</dt><dd class="font-medium text-slate-800">{{ number_format($ordersCount) }}</dd></div>
            </dl>
            @if($user->stores->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-slate-100">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Stores</p>
                <ul class="space-y-1.5">
                    @foreach($user->stores as $store)
                    <li class="flex items-center justify-between text-sm">
                        <span class="text-slate-700">{{ $store->name }}</span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $store->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($store->status) }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
            @else
            <p class="text-sm text-slate-400">This user has not set up a business yet.</p>
            @endif
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6">
        {{-- Subscription --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Subscription</h3></div>
            @php($sub = $user->business?->activeSubscription)
            <div class="p-5">
                @if($sub)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ $sub->subscriptionPlan?->name ?? 'Active plan' }}</p>
                        <p class="text-xs text-slate-400">Expires {{ $sub->expires_at?->format('d M Y') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                </div>
                @elseif($user->trial_ends_at && $user->trial_ends_at->isFuture())
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">Free trial</p>
                        <p class="text-xs text-slate-400">Ends {{ $user->trial_ends_at->format('d M Y') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Trial</span>
                </div>
                @else
                <p class="text-sm text-slate-400">No active subscription or trial.</p>
                @endif
            </div>
            @if($payments->isNotEmpty())
            <table class="w-full text-sm border-t border-slate-100">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($payments as $payment)
                    <tr>
                        <td class="px-5 py-2.5 font-mono text-xs text-slate-500">{{ $payment->reference }}</td>
                        <td class="px-5 py-2.5 text-slate-700">₦{{ number_format($payment->amount, 2) }}</td>
                        <td class="px-5 py-2.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $payment->status === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($payment->status) }}</span>
                        </td>
                        <td class="px-5 py-2.5 text-right text-xs text-slate-400">{{ $payment->created_at->format('d M Y') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

        {{-- Activity --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Recent Activity</h3></div>
            <div class="divide-y divide-slate-50 max-h-[420px] overflow-y-auto">
                @forelse($activity as $log)
                <div class="px-5 py-3">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-700">{{ $log->description }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $log->action }} · {{ $log->ip_address ?? '—' }}</p>
                        </div>
                        <span class="text-xs text-slate-400 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                @empty
                <div class="px-5 py-10 text-center text-sm text-slate-400">No activity recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Edit modal --}}
<div id="editUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('editUserModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-base font-bold text-slate-900 mb-4">Edit User</h3>
        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Name</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone</label>
                <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Changes</button>
                <button type="button" onclick="closeModal('editUserModal')" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- Suspend modal --}}
<div id="suspendUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('suspendUserModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-base font-bold text-slate-900 mb-1">Suspend User</h3>
        <p class="text-sm text-slate-500 mb-4">Suspending <span class="font-semibold text-slate-700">{{ $user->name }}</span> will block their access. They will be emailed the reason.</p>
        <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="space-y-4">
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

{{-- Reset password modal --}}
<div id="resetPasswordModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('resetPasswordModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-base font-bold text-slate-900 mb-1">Reset Password</h3>
        <p class="text-sm text-slate-500 mb-4">A temporary password will be generated and emailed to <span class="font-semibold text-slate-700">{{ $user->email }}</span>. They will be required to choose a new password on next sign-in.</p>
        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="space-y-4">
            @csrf
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Generate &amp; Send</button>
                <button type="button" onclick="closeModal('resetPasswordModal')" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection
