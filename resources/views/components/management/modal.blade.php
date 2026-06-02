@props(['maxWidth' => 'lg', 'closeable' => true])

<div 
    x-data="{ show: false }" 
    x-show="show" 
    x-transition.opacity
    class="fixed inset-0 z-50 overflow-y-auto"
    x-init="@if($attributes->has('wire:model')) $watch('{{ $attributes->get('wire:model') }}', value => show = value) @endif"
    x-on:open-modal.window="if($event.detail === '{{ $attributes->get('name') }}') show = true"
    x-on:close-modal.window="show = false"
    x-on:keydown.escape.window="show = false"
    style="display: none;"
>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="show" x-transition class="fixed inset-0 bg-slate-900/50" @click="show = false"></div>
        <div x-show="show" x-transition class="relative w-full max-w-{{ $maxWidth }} bg-white rounded-2xl shadow-xl">
            @if($closeable)
            <button @click="show = false" class="absolute top-4 right-4 p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
