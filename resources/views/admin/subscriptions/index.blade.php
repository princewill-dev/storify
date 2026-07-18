@extends('admin.layout')
@section('subtitle', 'Subscriptions')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h2 class="text-lg font-bold text-slate-900">Subscriptions</h2>
        <a href="{{ route('admin.subscriptions.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reset</a>
    </div>
    <div class="p-6">
        <form method="GET" class="flex flex-wrap gap-3 mb-6">
            <select name="status" class="w-full sm:w-auto rounded-lg border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Statuses</option>
                <option value="active" @selected(($status ?? '') === 'active')>Active</option>
                <option value="trial" @selected(($status ?? '') === 'trial')>Trial</option>
                <option value="expired" @selected(($status ?? '') === 'expired')>Expired</option>
                <option value="cancelled" @selected(($status ?? '') === 'cancelled')>Cancelled</option>
            </select>
            <input type="text" name="q" value="{{ $q ?? '' }}" class="flex-1 min-w-[200px] rounded-lg border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Business name or plan name">
            <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Filter</button>
        </form>

        
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100">
                    <tr>
                        <th class="text-left py-3 px-4 font-medium text-slate-600">Business</th>
                        <th class="text-left py-3 px-4 font-medium text-slate-600">Plan</th>
                        <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                        <th class="text-left py-3 px-4 font-medium text-slate-600">Started</th>
                        <th class="text-left py-3 px-4 font-medium text-slate-600">Ends</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($subscriptions as $sub)
                        <tr>
                            <td class="py-3 px-4">
                                @if($sub->business)
                                    <a href="{{ route('admin.vendors.show', $sub->business->owner) }}" class="font-semibold text-slate-700 hover:text-slate-900">{{ $sub->business->name }}</a>
                                    <div class="text-xs text-slate-400 font-mono">{{ $sub->business->business_code }}</div>
                                @else
                                    <span class="text-slate-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-slate-700">{{ $sub->subscriptionPlan?->name ?? '&mdash;' }}</td>
                            <td class="py-3 px-4">
                                @php
                                    $subStatusColors = [
                                        'active' => 'bg-emerald-50 text-emerald-700',
                                        'trial' => 'bg-blue-50 text-blue-700',
                                        'expired' => 'bg-red-50 text-red-700',
                                        'cancelled' => 'bg-slate-100 text-slate-600',
                                    ];
                                    $subColor = $subStatusColors[$sub->status] ?? 'bg-slate-100 text-slate-600';
                                @endphp
                                <span class="inline-flex items-center rounded-full {{ $subColor }} px-2.5 py-0.5 text-xs font-medium">{{ ucfirst($sub->status) }}</span>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-600">{{ $sub->starts_at?->format('d M Y') ?? '&mdash;' }}</td>
                            <td class="py-3 px-4 text-sm text-slate-600">{{ $sub->ends_at?->format('d M Y') ?? '&mdash;' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-400">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>

        <div class="mt-4">{{ $subscriptions->links() }}</div>
    </div>
</div>
@endsection
