<header class="sticky top-0 z-30 bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 sm:px-6 shrink-0">
    <div class="flex items-center gap-3">
        <button @click="mobileMenuOpen = !mobileMenuOpen" class="lg:hidden p-2 -ml-1 rounded-lg text-slate-500 hover:bg-slate-100">
            <i class="fi fi-rr-menu-burger text-lg"></i>
        </button>
        <button @click="sidebarOpen = !sidebarOpen" class="hidden lg:flex p-2 -ml-1 rounded-lg text-slate-400 hover:bg-slate-100">
            <i class="fi fi-rr-menu-burger text-lg"></i>
        </button>
        <div>
            <h1 class="text-sm font-semibold text-slate-800">@yield('subtitle', 'Dashboard')</h1>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <a href="{{ route('home.index') }}" class="hidden sm:flex items-center gap-1 text-xs text-slate-400 hover:text-slate-600 transition-colors" title="Back to Home">
            <i class="fi fi-rr-home text-sm"></i>
        </a>
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-slate-200 flex items-center justify-center overflow-hidden">
                <span class="text-[10px] font-bold text-slate-500 uppercase">{{ substr(auth()->user()->name ?? 'A', 0, 1) }}</span>
            </div>
            <span class="text-sm font-medium text-slate-700 hidden sm:block">{{ auth()->user()->name ?? 'Admin' }}</span>
        </div>
    </div>
</header>
