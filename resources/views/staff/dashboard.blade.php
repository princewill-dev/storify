<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard — Storify Staff</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon ?? asset('favicon.png') }}">
    @vite('resources/css/app.css')
</head>
<body class="h-full bg-slate-50 font-sans antialiased">

<div class="flex flex-col h-full">
    <header class="bg-white border-b border-slate-200">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="{{ $company->favicon ?? asset('favicon.png') }}" alt="" class="h-8 w-8 rounded-lg">
                    <span class="text-lg font-semibold text-slate-900 tracking-tight">{{ $company->name ?? 'Storify' }} Staff</span>
                </div>

                <div class="flex items-center gap-4">
                    @if($user->business)
                    <span class="text-xs text-slate-500 hidden sm:block">{{ $user->business->name }}</span>
                    @endif

                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                        {{ $user->getRoleNames()->first() ?? 'No role' }}
                    </span>

                    <div class="flex items-center gap-3 border-l border-slate-200 pl-4">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold text-white">
                            {{ strtoupper(substr($user->name ?? 'S', 0, 2)) }}
                        </span>
                        <span class="text-sm font-medium text-slate-700 hidden sm:block">{{ $user->name }}</span>
                    </div>

                    <form method="POST" action="{{ route('management.auth.logout') }}">
                        @csrf
                        <button class="text-sm text-slate-500 hover:text-slate-700 font-medium transition-colors">Log out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Welcome, {{ $user->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">Here's what you have access to based on your permissions.</p>
            </div>

            @if($todayOrders > 0)
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Today's Sales</p>
                    <p class="text-xl font-bold text-emerald-600 mt-1">₦{{ number_format($todaySales, 0) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Today's Orders</p>
                    <p class="text-xl font-bold text-blue-600 mt-1">{{ $todayOrders }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Active Session</p>
                    <p class="text-xl font-bold {{ $activeSession ? 'text-teal-600' : 'text-slate-400' }} mt-1">{{ $activeSession ? $activeSession->store->name : 'None' }}</p>
                </div>
            </div>

            @if($recentActivity->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-8">
                <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Recent POS Activity</h3></div>
                <div class="divide-y divide-slate-50">
                    @foreach($recentActivity as $order)
                    @php $tx = $order->transactions->first(); @endphp
                    <div class="px-5 py-3 flex items-center justify-between text-sm hover:bg-slate-50/50">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium text-slate-800">{{ $order->store?->name ?? '—' }}</p>
                            <p class="text-[10px] text-slate-400">#{{ $order->order_number ?? $order->id }} · {{ $order->created_at->diffForHumans() }}</p>
                        </div>
                        <div class="text-right shrink-0 ml-3">
                            <p class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</p>
                            <p class="text-[10px] {{ $tx && $tx->status === 'confirmed' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $tx ? ucfirst($tx->status) : '—' }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @endif

            @if(!$hasAnyPermission)
                <div class="rounded-xl border border-slate-200 bg-white p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    <h3 class="mt-4 text-lg font-semibold text-slate-900">No permissions assigned</h3>
                    <p class="mt-1 text-sm text-slate-500">Your account doesn't have any permissions yet. Contact your administrator to get set up.</p>
                </div>
            @else
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach($modules as $module)
                        <div class="group relative rounded-xl border border-slate-200 bg-white p-6 {{ $module['route'] ? 'hover:border-slate-400 hover:shadow-sm transition-all duration-200' : '' }}">
                            <div class="flex items-start gap-4">
                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg shrink-0
                                    @if($module['key'] === 'pos') bg-emerald-50 text-emerald-600
                                    @elseif($module['key'] === 'stores') bg-violet-50 text-violet-600
                                    @elseif($module['key'] === 'products') bg-sky-50 text-sky-600
                                    @elseif($module['key'] === 'orders') bg-amber-50 text-amber-600
                                    @elseif($module['key'] === 'staff') bg-rose-50 text-rose-600
                                    @elseif($module['key'] === 'warehouses') bg-indigo-50 text-indigo-600
                                    @elseif($module['key'] === 'transactions') bg-teal-50 text-teal-600
                                    @elseif($module['key'] === 'customers') bg-orange-50 text-orange-600
                                    @elseif($module['key'] === 'reports') bg-cyan-50 text-cyan-600
                                    @elseif($module['key'] === 'deliveries') bg-lime-50 text-lime-600
                                    @elseif($module['key'] === 'settings') bg-slate-100 text-slate-600
                                    @elseif($module['key'] === 'coupons') bg-pink-50 text-pink-600
                                    @elseif($module['key'] === 'support') bg-blue-50 text-blue-600
                                    @else bg-slate-50 text-slate-600 @endif">
                                    @if($module['key'] === 'pos')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 003-3h-3v3zm3-3a3 3 0 00-3 3v-3h3zm0 0V7.5m0 3.75H21m-10.5 0H7.5m10.5-9h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 007.5 17.25h10.5A2.25 2.25 0 0020.25 15V5.25A2.25 2.25 0 0018 3zm-3.75 10.5a.75.75 0 01-.75.75h-3a.75.75 0 010-1.5h3a.75.75 0 01.75.75z" /></svg>
                                    @elseif(in_array($module['key'], ['stores','products','warehouses']))
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72l1.189-1.19A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72M6.75 18h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .414.336.75.75.75z" /></svg>
                                    @elseif($module['key'] === 'orders')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15a2.25 2.25 0 012.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                                    @elseif($module['key'] === 'staff')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                                    @elseif($module['key'] === 'transactions')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    @elseif($module['key'] === 'customers')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                    @elseif($module['key'] === 'reports')
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                                    @else
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zm0 9.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zm0 9.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                                    @endif
                                </span>

                                <div class="flex-1 min-w-0">
                                    <h3 class="text-base font-semibold text-slate-900">{{ $module['label'] }}</h3>
                                    <p class="mt-1 text-sm text-slate-500 leading-relaxed">{{ $module['description'] }}</p>

                                    @if($module['warning'])
                                    <p class="mt-2 text-xs font-medium text-amber-600 bg-amber-50 border border-amber-200 rounded-md px-2.5 py-1.5 inline-block">
                                        {{ $module['warning'] }}
                                    </p>
                                    @endif

                                    @if($module['route'])
                                    <span class="mt-3 inline-flex items-center text-sm font-medium text-slate-600 group-hover:text-slate-900 transition-colors">
                                        Open
                                        <svg class="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                                    </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</div>

</body>
</html>
