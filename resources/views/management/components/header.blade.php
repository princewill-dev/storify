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

        {{-- Search Button --}}
        <button @click="searchOpen = true; $nextTick(() => $refs.searchInput?.focus())" class="flex items-center gap-2 px-3 py-1.5 text-sm text-slate-500 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-lg transition-colors" title="Search (Ctrl+K)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <span class="hidden sm:inline">Search</span>
            <kbd class="hidden lg:inline-flex items-center text-[10px] font-medium text-slate-400 bg-white border border-slate-200 rounded px-1.5 py-0.5">⌘K</kbd>
        </button>

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

    {{-- Global Search Modal --}}
    <template x-teleport="body">
    <div x-show="searchOpen" x-cloak @keydown.escape.window="searchOpen = false; searchQuery = ''; searchResults = []">
        <style>
            .gs-overlay { position: fixed; inset: 0; z-index: 9999; }
            .gs-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
            .gs-modal { position: absolute; left: 50%; transform: translateX(-50%); top: 100px; width: 540px; max-width: calc(100vw - 2rem); background: #fff; border-radius: 16px; box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 4px 6px -1px rgba(0,0,0,0.06), 0 20px 50px -12px rgba(0,0,0,0.25); overflow: hidden; }
            .gs-input-wrap { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid #e2e8f0; }
            .gs-input-wrap svg { width: 20px; height: 20px; color: #94a3b8; flex-shrink: 0; }
            .gs-input { flex: 1; border: none; outline: none; font-size: 15px; color: #1e293b; background: transparent; font-family: inherit; }
            .gs-input::placeholder { color: #94a3b8; }
            .gs-kbd { display: none; align-items: center; gap: 1px; padding: 2px 10px; font-size: 12px; font-weight: 500; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; flex-shrink: 0; }
            @media(min-width:640px){ .gs-kbd{display:inline-flex;} }
            .gs-body { max-height: 340px; overflow-y: auto; }
            .gs-state { text-align: center; padding: 60px 24px; }
            .gs-state-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
            .gs-state-icon svg { width: 22px; height: 22px; }
            .gs-state h3 { font-size: 14px; font-weight: 600; color: #334155; margin: 0 0 4px; }
            .gs-state p { font-size: 13px; color: #94a3b8; margin: 0; }
            .gs-spinner { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 60px 24px; font-size: 14px; color: #64748b; }
            .gs-spinner-icon { width: 20px; height: 20px; border: 2.5px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: gs-spin 0.6s linear infinite; }
            @keyframes gs-spin { to { transform: rotate(360deg); } }
            .gs-group-header { display: flex; align-items: center; gap: 10px; padding: 8px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
            .gs-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
            .gs-dot-products { background: #6366f1; }
            .gs-dot-stores { background: #f59e0b; }
            .gs-dot-warehouses { background: #3b82f6; }
            .gs-dot-customers { background: #f43f5e; }
            .gs-dot-transactions { background: #8b5cf6; }
            .gs-dot-staff { background: #14b8a6; }
            .gs-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; text-decoration: none; color: inherit; transition: background 0.1s; border-bottom: 1px solid #f8fafc; }
            .gs-item:last-child { border-bottom: none; }
            .gs-item:hover { background: #f1f5f9; }
            .gs-item-title { font-size: 14px; font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .gs-item-sub { font-size: 12px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
            .gs-item-arrow { color: #cbd5e1; flex-shrink: 0; margin-left: 12px; width: 16px; height: 16px; display: none; }
            .gs-item:hover .gs-item-arrow { display: block; }
            @media(min-width:640px){ .gs-item-sub{display:inline;} .gs-item-arrow{display:block;} }
            .gs-footer { display: flex; align-items: center; gap: 20px; padding: 10px 18px; border-top: 1px solid #e2e8f0; background: #fafafa; font-size: 11px; color: #94a3b8; }
            .gs-footer kbd { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 5px; font-size: 10px; font-weight: 600; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; margin-right: 4px; }
        </style>
        <div class="gs-overlay">
            <div class="gs-backdrop" @click="searchOpen = false; searchQuery = ''; searchResults = []"></div>
            <div class="gs-modal" @click.outside="searchOpen = false; searchQuery = ''; searchResults = []">
                <div class="gs-input-wrap">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                    <input type="text" x-ref="searchInput" x-model="searchQuery" placeholder="Search products, orders, customers..." class="gs-input"
                           @input="clearTimeout(searchTimer); searchTimer = setTimeout(async () => {
                               searchLoading = true; searchEmpty = false;
                               if (searchQuery.length < 2) { searchResults = []; searchLoading = false; return; }
                               try {
                                   const resp = await fetch('/management/search?q=' + encodeURIComponent(searchQuery), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                                   const data = await resp.json();
                                   searchResults = data.results || [];
                                   searchEmpty = searchResults.length === 0;
                               } catch(e) { searchResults = []; }
                               searchLoading = false;
                           }, 250)">
                    <span class="gs-kbd">⌘K</span>
                </div>

                <div class="gs-body">
                    <div x-show="searchLoading" class="gs-spinner"><div class="gs-spinner-icon"></div>Searching...</div>

                    <div x-show="searchEmpty && !searchLoading" class="gs-state">
                        <div class="gs-state-icon" style="background:#f1f5f9"><svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#94a3b8"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg></div>
                        <h3>No results for "<span x-text="searchQuery"></span>"</h3>
                        <p>Try a different search term</p>
                    </div>

                    <div x-show="searchResults.length === 0 && !searchLoading && !searchEmpty && searchQuery.length < 2" class="gs-state">
                        <div class="gs-state-icon" style="background:#eff6ff"><svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#3b82f6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg></div>
                        <h3>Search your business</h3>
                        <p>Find products, orders, customers, stores, staff instantly</p>
                    </div>

                    <div x-show="searchResults.length > 0 && !searchLoading">
                        <template x-for="group in searchResults" :key="group.group">
                            <div>
                                <div class="gs-group-header">
                                    <span class="gs-dot" :class="'gs-dot-' + group.group.toLowerCase()"></span>
                                    <span x-text="group.group"></span>
                                    <span style="color:#cbd5e1;font-size:10px;font-weight:500" x-text="group.items.length"></span>
                                </div>
                                <template x-for="(item, idx) in group.items" :key="item.url || item.title">
                                    <a :href="item.url || '#'" class="gs-item" :style="!item.url ? 'opacity:0.3;pointer-events:none' : ''">
                                        <span style="min-width:0;flex:1">
                                            <span class="gs-item-title" x-text="item.title"></span>
                                            <span class="gs-item-sub" x-text="item.subtitle" style="display:none"></span>
                                        </span>
                                        <svg class="gs-item-arrow" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="gs-footer">
                    <span><kbd>Esc</kbd>close</span>
                    <span><kbd>↵</kbd>open</span>
                </div>
            </div>
        </div>
    </div>
    </template>
</header>
