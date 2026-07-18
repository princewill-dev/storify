@extends('admin.layout')
@section('subtitle', 'activity logs')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h2 class="text-lg font-bold text-slate-900">Activity Logs</h2>
        <button type="button" onclick="openModal('filterLogsModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Filter</button>
    </div>
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">When</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">User</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Action</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Description</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">IP</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">User Agent</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($logs as $log)
                    <tr>
                        <td class="py-3 px-4 text-slate-600 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="py-3 px-4 text-slate-700">{{ $log->user?->name ?? '&mdash;' }}</td>
                        <td class="py-3 px-4">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $log->action }}</span>
                        </td>
                        <td class="py-3 px-4 text-slate-600 max-w-[300px] truncate" title="{{ $log->description }}">{{ $log->description }}</td>
                        <td class="py-3 px-4 text-slate-600 whitespace-nowrap">{{ $log->ip_address }}</td>
                        <td class="py-3 px-4 text-slate-600 max-w-[240px] truncate" title="{{ $log->user_agent }}">{{ $log->user_agent }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-12 text-center text-slate-400">No activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-6 py-4 border-t border-slate-100">
        {{ $logs->links() }}
    </div>
</div>

<!-- Filter Logs Modal -->
<div id="filterLogsModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="filterLogsLabel" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('filterLogsModal')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-xl">
            <form method="GET">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900" id="filterLogsLabel">Filter Activity Logs</h3>
                    <button type="button" onclick="closeModal('filterLogsModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">User</label>
                            <select name="user_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All</option>
                                @foreach(($users ?? []) as $u)
                                    <option value="{{ $u->id }}" @selected(($userId ?? '')==$u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Action</label>
                            <select name="action" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All</option>
                                @foreach(($actions ?? []) as $a)
                                    <option value="{{ $a }}" @selected(($action ?? '')===$a)>{{ $a }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Search</label>
                            <input type="text" name="q" value="{{ $q ?? '' }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Action, description, IP, user agent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">From</label>
                            <input type="date" name="from" value="{{ $from ?? '' }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">To</label>
                            <input type="date" name="to" value="{{ $to ?? '' }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                    <a href="{{ route('admin.activity-logs.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reset</a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
