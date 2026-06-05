<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $staff->count() }} staff member(s) assigned to this store</p>
        @can('staff create')
        <a href="{{ route('management.staff.create') }}" class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
            <i class="fi fi-rr-plus text-xs"></i> Add Staff
        </a>
        @endcan
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Staff Member</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Role</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase hidden sm:table-cell">Assigned To</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($staff as $member)
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-4 py-3">
                        <a href="{{ route('management.staff.show', $member) }}" class="flex items-center gap-3 group">
                            <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-bold shrink-0">
                                {{ strtoupper(substr($member->name, 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 group-hover:text-blue-600">{{ $member->name }}</p>
                                <p class="text-xs text-slate-400">{{ $member->email }}</p>
                            </div>
                        </a>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <div class="flex flex-wrap gap-1">
                            @foreach($member->roles as $role)
                            <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">{{ $role->name }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="text-sm text-slate-600">{{ $member->assignedStores->count() }} store(s), {{ $member->assignedWarehouses->count() }} WH(s)</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <x-management.status-badge :status="$member->status" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('management.staff.edit', $member) }}" class="text-xs text-slate-500 hover:text-blue-600 font-medium">Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-400">No staff assigned to this store.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
