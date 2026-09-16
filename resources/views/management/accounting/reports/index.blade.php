@extends('management.layout')
@section('subtitle', 'Reports')

@section('content')

<x-management.page-header title="Financial Reports" subtitle="Statements generated from your posted ledger entries" />

@php
    $reports = [
        ['route' => 'management.accounting.reports.profit-and-loss', 'icon' => 'fi fi-rr-chart-line-up', 'iconClass' => 'bg-emerald-50 text-emerald-600', 'title' => 'Profit & Loss', 'desc' => 'Income and expenses over a period, with net profit.'],
        ['route' => 'management.accounting.reports.balance-sheet', 'icon' => 'fi fi-rr-scale', 'iconClass' => 'bg-indigo-50 text-indigo-600', 'title' => 'Balance Sheet', 'desc' => 'Assets, liabilities, and equity as of a date.'],
        ['route' => 'management.accounting.reports.trial-balance', 'icon' => 'fi fi-rr-list', 'iconClass' => 'bg-amber-50 text-amber-600', 'title' => 'Trial Balance', 'desc' => 'Debit and credit totals for every account.'],
        ['route' => 'management.accounting.reports.general-ledger', 'icon' => 'fi fi-rr-book-alt', 'iconClass' => 'bg-sky-50 text-sky-600', 'title' => 'General Ledger', 'desc' => 'Every posting to an account with a running balance.'],
        ['route' => 'management.accounting.reports.ar-aging', 'icon' => 'fi fi-rr-time-past', 'iconClass' => 'bg-rose-50 text-rose-600', 'title' => 'AR Aging', 'desc' => 'Outstanding customer invoices by age.'],
        ['route' => 'management.accounting.reports.ap-aging', 'icon' => 'fi fi-rr-time-forward', 'iconClass' => 'bg-orange-50 text-orange-600', 'title' => 'AP Aging', 'desc' => 'Outstanding supplier bills by age.'],
        ['route' => 'management.accounting.reports.vat-summary', 'icon' => 'fi fi-rr-percentage', 'iconClass' => 'bg-violet-50 text-violet-600', 'title' => 'VAT Summary', 'desc' => 'Output VAT vs input VAT and net payable.'],
        ['route' => 'management.accounting.reports.expense-summary', 'icon' => 'fi fi-rr-receipt', 'iconClass' => 'bg-slate-100 text-slate-600', 'title' => 'Expense Summary', 'desc' => 'Spending grouped by category.'],
        ['route' => 'management.accounting.reports.integrity', 'icon' => 'fi fi-rr-shield-check', 'iconClass' => 'bg-teal-50 text-teal-600', 'title' => 'Ledger Integrity', 'desc' => 'Wallet vs ledger, unposted payments, and AR checks.'],
    ];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    @foreach($reports as $report)
    <a href="{{ route($report['route']) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:border-slate-300 transition-colors">
        <div class="flex items-center justify-center w-11 h-11 rounded-xl {{ $report['iconClass'] }} mb-4"><i class="{{ $report['icon'] }}"></i></div>
        <h3 class="text-sm font-semibold text-slate-800">{{ $report['title'] }}</h3>
        <p class="text-xs text-slate-400 mt-1">{{ $report['desc'] }}</p>
    </a>
    @endforeach
</div>

@endsection
