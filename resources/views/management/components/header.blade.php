<header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-4 border-b border-slate-200 bg-white/95 backdrop-blur px-4 sm:px-6">
    
    {{-- Mobile menu toggle --}}
    <button @click="mobileMenuOpen = !mobileMenuOpen" class="lg:hidden -ml-2 p-2 text-slate-500 hover:text-slate-700 rounded-lg hover:bg-slate-100">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </button>

    {{-- Sidebar toggle (desktop) --}}
    <button @click="sidebarOpen = !sidebarOpen" class="hidden lg:flex -ml-2 p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </button>

    {{-- Breadcrumb — uses full $breadcrumbs array from controller; falls back to old subtitle behavior --}}
    <nav class="flex items-center gap-1.5 text-sm text-slate-500 min-w-0">
        @if(!empty($breadcrumbs) && count($breadcrumbs) > 1)
            @foreach($breadcrumbs as $i => $crumb)
                @if($i > 0)<i class="fi fi-rr-angle-small-right text-xs text-slate-400"></i>@endif
                @php $label = is_array($crumb) ? ($crumb['label'] ?? '') : $crumb; @endphp
                @if($loop->last)
                    <span class="text-slate-800 font-medium truncate">{{ $label }}</span>
                @else
                    <a href="{{ is_array($crumb) ? ($crumb['url'] ?? '#') : '#' }}" class="hover:text-slate-700 transition-colors truncate">{{ $label }}</a>
                @endif
            @endforeach
        @else
            <a href="{{ route('management.dashboard') }}" class="hover:text-slate-700 transition-colors truncate">Dashboard</a>
            @if(!Route::is('management.dashboard'))
                <i class="fi fi-rr-angle-small-right text-xs text-slate-400"></i>
                <span class="text-slate-800 font-medium truncate">@yield('subtitle')</span>
            @endif
        @endif
    </nav>

    {{-- Spacer --}}
    <div class="flex-1"></div>

    {{-- Right actions --}}
    <div class="flex items-center gap-2">
        {{-- Profile dropdown --}}
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-100 transition-colors">
                <img src="{{ $headerVendor->photoUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover bg-slate-200 shrink-0">
                <div class="hidden sm:flex flex-col items-start leading-none">
                    <span class="text-sm font-medium text-slate-700 max-w-[120px] truncate">{{ $headerVendor->name }}</span>
                    @if($roleName = $headerVendor->getRoleNames()->first())
                    <span class="text-[10px] font-medium text-slate-400">{{ $roleName }}</span>
                    @endif
                </div>
            </button>
            <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50">
                <div class="px-3 py-2 border-b border-slate-100">
                    <p class="text-sm font-medium text-slate-900 truncate">{{ $headerVendor->name }}</p>
                    <p class="text-xs text-slate-500 truncate">{{ $headerVendor->email }}</p>
                    @if($roleName = $headerVendor->getRoleNames()->first())
                    <span class="inline-block mt-1 text-[10px] font-medium text-slate-500 bg-slate-100 rounded-full px-2 py-0.5">{{ $roleName }}</span>
                    @endif
                </div>
                <a href="{{ route('management.profile.index') }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fi fi-rr-user text-slate-400 w-4 text-center"></i> Profile
                </a>
                <a href="{{ route('management.stores.index') }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fi fi-rr-shop text-slate-400 w-4 text-center"></i> My Stores
                </a>
                <div class="border-t border-slate-100 mt-1">
                    <a href="#" onclick="event.preventDefault(); document.getElementById('mgmt-logout-form').submit();"
                       class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                        <i class="fi fi-rr-exit w-4 text-center"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>
