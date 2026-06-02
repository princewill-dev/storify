@props(['header' => null, 'padding' => true])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden']) }}>
    @if($header)
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
            @if(is_string($header))
                <h3 class="text-sm font-semibold text-slate-800">{{ $header }}</h3>
            @else
                {{ $header }}
            @endif
        </div>
    @endif
    <div class="{{ $padding ? 'p-5' : '' }}">
        {{ $slot }}
    </div>
</div>
