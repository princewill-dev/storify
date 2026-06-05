@extends('management.layout')
@section('subtitle', 'POS Sessions')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="POS Sessions" subtitle="{{ $store->name ?? 'Store' }} · Cash register session history" />

<x-management.card>
    <x-management.data-table>
        <x-slot:header>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Session</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Staff</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Opened</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Opening</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Expected</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actual</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Difference</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
        </x-slot:header>
        @forelse($sessions ?? [] as $session)
        <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3">
                <a href="{{ route('management.pos.sessions.show', ['store' => $store->store_id, 'session' => $session]) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $session->session_code }}</a>
            </td>
            <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $session->staff?->name ?? '—' }}</span></td>
            <td class="px-5 py-3 hidden md:table-cell"><span class="text-xs text-slate-400">{{ $session->opened_at->format('d M H:i') }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm text-slate-600">₦{{ number_format($session->opening_balance / 100, 2) }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm text-slate-600">₦{{ number_format(($session->closing_balance_expected ?? 0) / 100, 2) }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format(($session->closing_balance_actual ?? 0) / 100, 2) }}</span></td>
            <td class="px-5 py-3 text-center">
                @if($session->difference !== null)
                <span class="text-sm font-semibold {{ $session->difference >= 0 ? 'text-emerald-600' : 'text-red-500' }}">{{ $session->difference >= 0 ? '+' : '' }}₦{{ number_format(abs($session->difference) / 100, 2) }}</span>
                @else <span class="text-sm text-slate-400">—</span> @endif
            </td>
            <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$session->status" /></td>
        </tr>
        @empty
        <tr><td colspan="8" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-terminal" title="No POS sessions" description="Open a POS session from the POS terminal to start recording sales." />
        </td></tr>
        @endforelse
    </x-management.data-table>
</x-management.card>
@endsection
