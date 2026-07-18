@extends('admin.layout')
@section('subtitle', 'Business KYC Applications')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">KYC Applications</h2>
</div>

<div class="flex flex-wrap items-center justify-between gap-4 mb-4">
    <form method="GET" class="flex items-center gap-2">
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">All statuses</option>
            @foreach(($statusOptions ?? []) as $value => $label)
                <option value="{{ $value }}" @selected((string) $status === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>
        @if($status)
            <a href="{{ route('admin.vendor-kyc.index') }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reset</a>
        @endif
    </form>

    <div class="flex flex-wrap gap-2">
        @foreach($statusOptions as $value => $label)
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                {{ $label }}:
                <span class="font-semibold ml-1">{{ $statusCounts[$value] ?? 0 }}</span>
            </span>
        @endforeach
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Applications</h3>
    </div>
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Legal Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Submitted</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($applications as $application)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-4 py-3 text-slate-500">{{ $application->id }}</td>
                        <td class="px-4 py-3">
                            @if($application->business)
                                <a href="{{ route('admin.vendors.show', $application->user) }}" class="font-medium text-slate-900 hover:text-indigo-600">
                                    {{ $application->business->name }}
                                </a>
                                <div class="text-xs text-slate-400 font-mono">{{ $application->business->business_code }}</div>
                                <div class="text-xs text-slate-400">Owner: {{ $application->user?->name }}</div>
                            @else
                                <a href="{{ route('admin.vendors.show', $application->user) }}" class="font-medium text-slate-900 hover:text-indigo-600">
                                    {{ $application->user->name }}
                                </a>
                                <div class="text-xs text-slate-400">{{ $application->user->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $application->legal_name }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            @if($application->submitted_at)
                                {{ $application->submitted_at->format('d M Y H:i') }}
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php($badge = $statusBadgeData[$application->status] ?? null)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ ($badge['class'] ?? '') ?: 'bg-slate-100 text-slate-600' }}">
                                {{ $badge['label'] ?? ucfirst(str_replace('_', ' ', $application->status)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.vendor-kyc.show', $application) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                                Review
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <i class="fi fi-rr-inbox block text-2xl text-slate-300 mb-2"></i>
                            <span class="text-slate-400">No KYC applications found.</span>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-5 py-3 border-t border-slate-100">
        {{ $applications->links() }}
    </div>
</div>
@endsection
