@props(['align' => 'right'])

<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button @click="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
    </button>
    <div x-show="open" x-transition.opacity.scale.origin.top.right
         class="absolute z-40 {{ $align === 'right' ? 'right-0' : 'left-0' }} mt-2 w-44 bg-white rounded-xl shadow-lg border border-slate-200 py-1">
        {{ $slot }}
    </div>
</div>
